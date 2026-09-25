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

    function auth_session_hash(): string
    {
        $salt = (string) env_or_fallback(['TB_SESSION_HASH_SALT', 'TB_APP_NAME'], 'tamasbau');
        return hash('sha256', $salt . '|' . session_id());
    }

    function auth_ip_hash(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $salt = (string) env_or_fallback(['TB_IP_HASH_SALT', 'TB_APP_NAME'], 'tamasbau');
        return hash('sha256', $salt . '|' . $ip);
    }

    function auth_register_session_record(int $userId): void
    {
        try {
            $stmt = db()->prepare('INSERT INTO user_sessions (user_id, session_token_hash, user_agent, ip_hash, is_revoked, last_seen_at) VALUES (?, ?, ?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE is_revoked = 0, last_seen_at = NOW(), revoked_at = NULL, user_agent = VALUES(user_agent), ip_hash = VALUES(ip_hash)');
            $stmt->execute([$userId, auth_session_hash(), clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255), auth_ip_hash()]);
        } catch (Throwable $e) {
        }
    }

    function auth_log_login_event(?int $userId, ?string $emailAttempt, bool $success, string $reason = ''): void
    {
        try {
            $risk = $success ? 'low' : 'medium';
            $stmt = db()->prepare('INSERT INTO user_login_events (user_id, email_attempt, is_success, risk_level, ip_hash, user_agent, failure_reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $userId,
                $emailAttempt !== null ? clean_string($emailAttempt, 190) : null,
                $success ? 1 : 0,
                $risk,
                auth_ip_hash(),
                clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
                $success ? null : clean_string($reason, 120),
            ]);
        } catch (Throwable $e) {
        }
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
    auth_register_session_record($newUserId);
    auth_log_login_event($newUserId, $email, true);
    send_json(['ok' => true, 'user' => user_payload_by_id($newUserId), 'csrf_token' => csrf_token()], 201);
}

if ($method === 'POST' && $action === 'login') {
    enforce_rate_limit('auth_login', 12, 900);
    $email = clean_string($payload['email'] ?? '', 190);
    $password = (string) ($payload['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        auth_log_login_event(null, $email !== '' ? $email : null, false, 'invalid_payload');
        send_json(['ok' => false, 'error' => 'Érvénytelen bejelentkezési adatok.'], 422);
    }

    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        auth_log_login_event((int) ($row['id'] ?? 0) ?: null, $email, false, 'invalid_credentials');
        send_json(['ok' => false, 'error' => 'Hibás e-mail vagy jelszó.'], 401);
    }

    if ((int) $row['is_active'] !== 1) {
        auth_log_login_event((int) $row['id'], $email, false, 'account_inactive');
        send_json(['ok' => false, 'error' => 'A fiók le van tiltva.'], 403);
    }

    session_regenerate_id(true);
    $loginUserId = (int) $row['id'];
    $_SESSION['user_id'] = $loginUserId;
    auth_register_session_record($loginUserId);
    auth_log_login_event($loginUserId, $email, true);
    $loggedUser = user_payload_by_id($loginUserId);
    if ($loggedUser && ($loggedUser['role'] ?? 'user') === 'admin') {
        log_admin_activity($loginUserId, 'admin_login', 'user', $loginUserId);
    }
    send_json(['ok' => true, 'user' => $loggedUser, 'csrf_token' => csrf_token()]);
}

if ($method === 'POST' && $action === 'logout') {
    $logoutUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($logoutUserId > 0) {
        try {
            $stmt = db()->prepare('UPDATE user_sessions SET is_revoked = 1, revoked_at = NOW(), last_seen_at = NOW() WHERE user_id = ? AND session_token_hash = ?');
            $stmt->execute([$logoutUserId, auth_session_hash()]);
        } catch (Throwable $e) {
        }
    }
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
