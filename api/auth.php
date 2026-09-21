<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$action = clean_string($_GET['action'] ?? ($payload['action'] ?? ''));

if ($method === 'GET' && $action === 'me') {
    send_json(['ok' => true, 'user' => current_user()]);
}

if ($method === 'POST' && $action === 'register') {
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

    $_SESSION['user_id'] = (int) db()->lastInsertId();
    send_json(['ok' => true, 'user' => current_user()], 201);
}

if ($method === 'POST' && $action === 'login') {
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

    $_SESSION['user_id'] = (int) $row['id'];
    send_json(['ok' => true, 'user' => current_user()]);
}

if ($method === 'POST' && $action === 'logout') {
    unset($_SESSION['user_id']);
    send_json(['ok' => true]);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
