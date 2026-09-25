<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function user_payload_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, role, phone, created_at, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    $user['id'] = (int) $user['id'];
    $user['is_active'] = (int) $user['is_active'];
    return $user;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$action = clean_string($_GET['action'] ?? ($payload['action'] ?? ''));

if ($method === 'POST') {
    validate_csrf_token();
}

if ($method === 'GET' && $action === 'me') {
    send_json(['ok' => true, 'user' => current_user(), 'csrf_token' => csrf_token()]);
}

if ($method === 'POST' && $action === 'register') {
    enforce_rate_limit('auth_register', 8, 900);
    $name = clean_string($payload['name'] ?? '', 120);
    $email = clean_string($payload['email'] ?? '', 190);
    $password = (string) ($payload['password'] ?? '');
    $phone = clean_string($payload['phone'] ?? '', 30);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 6) {
        send_json(['ok' => false, 'error' => 'Érvénytelen regisztrációs adatok.'], 422);
    }

    $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        send_json(['ok' => false, 'error' => 'Ez az e-mail cím már foglalt.'], 409);
    }

    $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, phone, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'user', $phone ?: null]);

    session_regenerate_id(true);
    $newUserId = (int) db()->lastInsertId();
    $_SESSION['user_id'] = $newUserId;
    send_json(['ok' => true, 'user' => user_payload_by_id($newUserId), 'csrf_token' => csrf_token()], 201);
}

if ($method === 'POST' && $action === 'login') {
    enforce_rate_limit('auth_login', 12, 900);
    $email = clean_string($payload['email'] ?? '', 190);
    $password = (string) ($payload['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        send_json(['ok' => false, 'error' => 'Érvénytelen bejelentkezési adatok.'], 422);
    }

    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        send_json(['ok' => false, 'error' => 'Hibás e-mail vagy jelszó.'], 401);
    }

    if ((int) $row['is_active'] !== 1) {
        send_json(['ok' => false, 'error' => 'A fiók le van tiltva.'], 403);
    }

    session_regenerate_id(true);
    $loginUserId = (int) $row['id'];
    $_SESSION['user_id'] = $loginUserId;
    $loggedUser = user_payload_by_id($loginUserId);
    if ($loggedUser && ($loggedUser['role'] ?? 'user') === 'admin') {
        log_admin_activity($loginUserId, 'admin_login', 'user', $loginUserId);
    }
    send_json(['ok' => true, 'user' => $loggedUser, 'csrf_token' => csrf_token()]);
}

if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    csrf_token();
    send_json(['ok' => true]);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
