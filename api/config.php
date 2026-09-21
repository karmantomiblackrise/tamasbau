<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('TB_DB_HOST') ?: '127.0.0.1';
    $port = getenv('TB_DB_PORT') ?: '3306';
    $name = getenv('TB_DB_NAME') ?: 'tamasbau';
    $user = getenv('TB_DB_USER') ?: 'root';
    $pass = getenv('TB_DB_PASS') ?: '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function send_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function clean_string(?string $value, int $max = 255): string
{
    $value = trim((string) $value);
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }
    return $value;
}

function is_state_changing_method(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function validate_csrf_token(): void
{
    if (!is_state_changing_method()) {
        return;
    }
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || $provided === '' || !hash_equals(csrf_token(), $provided)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen CSRF token.'], 403);
    }
}

function current_user(): ?array
{
    static $cached = null;
    static $cachedUser = null;
    static $cachedForUserId = null;
    $sessionUserId = $_SESSION['user_id'] ?? null;

    if ($cached !== null && $cachedForUserId === $sessionUserId) {
        return $cachedUser;
    }

    if (empty($sessionUserId)) {
        $cached = true;
        $cachedUser = null;
        $cachedForUserId = null;
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, phone, created_at, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$sessionUserId]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        $cached = true;
        $cachedUser = null;
        $cachedForUserId = null;
        return null;
    }

    $user['id'] = (int) $user['id'];
    $user['is_active'] = (int) $user['is_active'];
    $cached = true;
    $cachedUser = $user;
    $cachedForUserId = $sessionUserId;
    return $cachedUser;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        send_json(['ok' => false, 'error' => 'Bejelentkezés szükséges.'], 401);
    }
    validate_csrf_token();
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? 'user') !== 'admin') {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a művelethez.'], 403);
    }
    return $user;
}
