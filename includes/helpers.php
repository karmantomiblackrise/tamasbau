<?php

declare(strict_types=1);

function app_base_path(): string
{
    $trimmed = trim(APP_BASE_PATH, '/');
    return $trimmed === '' ? '' : '/' . $trimmed;
}

function app_cookie_path(): string
{
    $base = app_base_path();
    return $base === '' ? '/' : $base . '/';
}

function app_url(string $path = ''): string
{
    $base = rtrim(APP_URL, '/');
    $fullPath = app_base_path() . '/' . ltrim($path, '/');
    return $base . '/' . ltrim($fullPath, '/');
}

function redirect(string $path): void
{
    if (str_contains($path, '?')) {
        [$cleanPath, $query] = explode('?', $path, 2);
        header('Location: ' . app_url($cleanPath) . '?' . $query);
        exit;
    }

    header('Location: ' . app_url($path));
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function app_log(string $message): void
{
    $logPath = dirname(__DIR__) . '/logs/admin-recovery.log';
    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        error_log('Nem sikerült létrehozni a napló könyvtárat: ' . $logDir);
        return;
    }

    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    if (file_put_contents($logPath, $line, FILE_APPEND) === false) {
        error_log('Nem sikerült admin recovery naplót írni: ' . $logPath);
    }
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'schema' => DB_NAME,
        'table' => $tableName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $sql = 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table AND column_name = :column';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'schema' => DB_NAME,
        'table' => $tableName,
        'column' => $columnName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function count_if_table_exists(PDO $pdo, string $tableName): ?int
{
    if (!table_exists($pdo, $tableName)) {
        return null;
    }

    $sql = sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '', $tableName));
    $stmt = $pdo->query($sql);

    return (int) $stmt->fetchColumn();
}
