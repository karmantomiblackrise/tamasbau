<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
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

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, phone, created_at, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user['id'] = (int) $user['id'];
    $user['is_active'] = (int) $user['is_active'];
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        send_json(['ok' => false, 'error' => 'Bejelentkezés szükséges.'], 401);
    }
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
