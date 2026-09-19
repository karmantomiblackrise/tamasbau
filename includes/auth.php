<?php

declare(strict_types=1);

function is_logged_in(): bool
{
    return !empty($_SESSION['auth']['user_id']);
}

function is_admin_user(): bool
{
    return is_logged_in() && (($_SESSION['auth']['role'] ?? '') === 'admin');
}

function require_admin(): void
{
    if (!is_admin_user()) {
        flash_set('error', 'Bejelentkezés szükséges.');
        redirect('admin/login.php');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'user_id' => (int) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'is_admin' => $user['role'] === 'admin',
    ];
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function find_user_by_username(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    return $user ?: null;
}
