<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-lib.php';

const AUTH_2FA_TTL_SECONDS = 300;
const AUTH_2FA_MAX_ATTEMPTS = 5;

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

function auth_register_session_record(int $userId): void
{
    try {
        $stmt = db()->prepare('INSERT INTO user_sessions (user_id, session_token_hash, user_agent, ip_hash, is_revoked, last_seen_at) VALUES (?, ?, ?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), is_revoked = 0, last_seen_at = NOW(), revoked_at = NULL, user_agent = VALUES(user_agent), ip_hash = VALUES(ip_hash)');
        $stmt->execute([$userId, auth_session_hash(), clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255), auth_ip_hash()]);
    } catch (Throwable $e) {
        app_log_error('session_record_failed', $e);
    }
}

function auth_log_login_event(?int $userId, ?string $emailAttempt, bool $success, string $reason = ''): string
{
    $email = $emailAttempt !== null ? clean_string(strtolower($emailAttempt), 190) : '';
    $risk = tb_login_risk_level($userId, $email, auth_ip_hash(), $success);
    try {
        $stmt = db()->prepare('INSERT INTO user_login_events (user_id, email_attempt, is_success, risk_level, ip_hash, user_agent, failure_reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $userId,
            $email !== '' ? $email : null,
            $success ? 1 : 0,
            $risk,
            auth_ip_hash(),
            clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
            $success ? ($reason !== '' ? clean_string($reason, 120) : null) : clean_string($reason, 120),
        ]);
    } catch (Throwable $e) {
        app_log_error('login_event_failed', $e);
    }

    if (!$success && $risk === 'high') {
        $first = tb_notify(null, 'admin', 'Gyanús bejelentkezési aktivitás', 'Sok sikertelen belépési kísérlet: ' . $email, '/admin-center.html#security');
        if ($first) {
            tb_alert_email('Gyanús bejelentkezési aktivitás', 'Az elmúlt 15 percben legalább 5 sikertelen belépési kísérlet történt ezzel az e-mail címmel: ' . $email . "\nIdőpont: " . date('Y-m-d H:i:s'));
        }
    }
    if ($success && $risk === 'medium' && $userId !== null) {
        tb_notify($userId, 'user', 'Bejelentkezés új eszközről/helyről', 'Ha nem Ön volt, azonnal változtasson jelszót és vonja vissza a munkameneteket.', '/admin-center.html#security');
    }
    return $risk;
}

function auth_complete_login(int $userId, string $email, string $method = 'password'): void
{
    unset($_SESSION['pending_2fa']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['tb_session_touch'] = time();
    auth_register_session_record($userId);
    auth_log_login_event($userId, $email, true, $method === 'password' ? '' : $method);
    $loggedUser = user_payload_by_id($userId);
    if ($loggedUser && in_array($loggedUser['role'] ?? 'user', ['admin', 'superadmin'], true)) {
        log_admin_activity($userId, 'admin_login', 'user', $userId, ['method' => $method]);
    }
    send_json([
        'ok' => true,
        'user' => $loggedUser,
        'csrf_token' => csrf_token(),
        'two_factor_setup_required' => $loggedUser ? user_must_setup_two_factor($loggedUser) : false,
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$action = clean_string($_GET['action'] ?? ($payload['action'] ?? ''));

if ($method === 'POST') {
    validate_csrf_token();
}

if ($method === 'GET' && $action === 'me') {
    $me = current_user();
    $pending = $_SESSION['pending_2fa'] ?? null;
    send_json([
        'ok' => true,
        'user' => $me,
        'csrf_token' => csrf_token(),
        'two_factor_pending' => !$me && is_array($pending) && (int) ($pending['expires'] ?? 0) > time(),
        'two_factor_setup_required' => $me ? user_must_setup_two_factor($me) : false,
        'two_factor_policy' => admin_two_factor_policy(),
    ]);
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
    auth_log_login_event($newUserId, $email, true, 'register');
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

    $stmt = db()->prepare('SELECT id, email, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        auth_log_login_event($row ? (int) $row['id'] : null, $email, false, 'invalid_credentials');
        send_json(['ok' => false, 'error' => 'Hibás e-mail vagy jelszó.'], 401);
    }

    if ((int) $row['is_active'] !== 1) {
        auth_log_login_event((int) $row['id'], $email, false, 'account_inactive');
        send_json(['ok' => false, 'error' => 'A fiók le van tiltva.'], 403);
    }

    $loginUserId = (int) $row['id'];
    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
        try {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $loginUserId]);
        } catch (Throwable $e) {
            app_log_error('password_rehash_failed', $e);
        }
    }

    if (user_two_factor_enabled($loginUserId)) {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['user_id' => $loginUserId, 'email' => (string) $row['email'], 'expires' => time() + AUTH_2FA_TTL_SECONDS, 'attempts' => 0];
        send_json(['ok' => true, 'two_factor_required' => true, 'csrf_token' => csrf_token(), 'message' => 'Adja meg a hitelesítő alkalmazás 6 jegyű kódját vagy egy helyreállító kódot.']);
    }

    auth_complete_login($loginUserId, (string) $row['email']);
}

if ($method === 'POST' && $action === 'verify_2fa') {
    enforce_rate_limit('auth_verify_2fa', 15, 900);
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
        unset($_SESSION['pending_2fa']);
        send_json(['ok' => false, 'error' => 'A kétlépcsős azonosítás ideje lejárt. Kérjük, jelentkezzen be újra.', 'code' => 'two_factor_expired'], 401);
    }
    $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
    $_SESSION['pending_2fa'] = $pending;
    if ($pending['attempts'] > AUTH_2FA_MAX_ATTEMPTS) {
        unset($_SESSION['pending_2fa']);
        auth_log_login_event((int) $pending['user_id'], (string) $pending['email'], false, '2fa_too_many_attempts');
        send_json(['ok' => false, 'error' => 'Túl sok hibás kód. Kérjük, jelentkezzen be újra.', 'code' => 'two_factor_expired'], 429);
    }
    $userId = (int) $pending['user_id'];
    $code = clean_string((string) ($payload['code'] ?? ''), 20);
    $recovery = clean_string((string) ($payload['recovery_code'] ?? ''), 40);
    if ($code !== '' && tb_verify_user_totp($userId, $code)) {
        auth_complete_login($userId, (string) $pending['email'], 'totp');
    }
    if ($recovery !== '' && tb_consume_recovery_code($userId, $recovery)) {
        tb_notify($userId, 'user', 'Helyreállító kód felhasználva', 'Bejelentkezés helyreállító kóddal történt. Javasoljuk új kódok generálását.', '/admin-center.html#security');
        log_admin_activity($userId, 'two_factor_recovery_code_used', 'user', $userId);
        auth_complete_login($userId, (string) $pending['email'], 'recovery_code');
    }
    auth_log_login_event($userId, (string) $pending['email'], false, 'invalid_2fa_code');
    send_json(['ok' => false, 'error' => 'Hibás vagy már felhasznált kód.', 'attempts_left' => max(0, AUTH_2FA_MAX_ATTEMPTS - $pending['attempts'])], 401);
}

if ($method === 'POST' && $action === 'cancel_2fa') {
    unset($_SESSION['pending_2fa']);
    send_json(['ok' => true]);
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
