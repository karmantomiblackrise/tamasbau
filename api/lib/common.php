<?php
declare(strict_types=1);

/*
 * Közös segédfüggvények az új platform modulokhoz.
 * A fájl csak függvényeket definiál, közvetlen kimenete nincs.
 */

function tb_json(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? 'null' : $encoded;
}

function tb_json_decode_array(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function tb_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * LIKE minta biztonságos escapinggel (%, _ és \ karakterek literálként).
 */
function tb_like(string $query): string
{
    return '%' . addcslashes($query, '%_\\') . '%';
}

function tb_backoffice_roles(): array
{
    return ['admin', 'superadmin', 'project_manager', 'service_agent', 'support_agent', 'quote_manager'];
}

function tb_is_backoffice(array $user): bool
{
    return in_array((string) ($user['role'] ?? 'user'), tb_backoffice_roles(), true);
}

/**
 * Belső költség / margin adatot csak admin és superadmin láthat.
 */
function tb_can_see_internal_costs(array $user): bool
{
    return in_array((string) ($user['role'] ?? 'user'), ['admin', 'superadmin'], true);
}

/**
 * In-app értesítés létrehozása. Alapértelmezetten ugyanaz a cím+üzenet naponta egyszer jön létre,
 * $dedupeForever esetén soha nem duplikálódik.
 */
function tb_notify(?int $userId, string $audience, string $title, ?string $message = null, ?string $link = null, bool $dedupeForever = false): bool
{
    try {
        $title = clean_string($title, 160);
        $message = $message !== null ? clean_string($message, 1000) : null;
        $sql = 'SELECT id FROM in_app_notifications WHERE audience = ? AND user_id <=> ? AND title = ? AND message <=> ?';
        if (!$dedupeForever) {
            $sql .= ' AND DATE(created_at) = CURDATE()';
        }
        $exists = db()->prepare($sql . ' LIMIT 1');
        $exists->execute([$audience, $userId, $title, $message]);
        if ($exists->fetch()) {
            return false;
        }
        $stmt = db()->prepare('INSERT INTO in_app_notifications (user_id, audience, title, message, link_url) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $audience, $title, $message, $link !== null ? clean_string($link, 500) : null]);
        return true;
    } catch (Throwable $e) {
        app_log_error('notification_failed', $e);
        return false;
    }
}

/**
 * Riasztási e-mail az üzemeltetőnek. Hibánál nem dob kivételt, hanem a hiba szövegét adja vissza.
 */
function tb_alert_email(string $subject, string $text): ?string
{
    $to = trim((string) env_or_fallback(['TB_ALERT_EMAIL'], ''));
    if ($to === '') {
        $to = (string) (app_config()['mail']['from_address'] ?? '');
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'Nincs beállított riasztási e-mail cím (TB_ALERT_EMAIL).';
    }
    try {
        send_app_mail($to, 'Tamás Bau üzemeltetés', $subject, format_html_email($text), $text);
        return null;
    } catch (Throwable $e) {
        return 'SMTP küldés sikertelen: ' . redact_secrets($e->getMessage());
    }
}

/**
 * CSV cella escaping RFC 4180 szerint + formula injection védelem (Excel/LibreOffice).
 */
function tb_csv_cell(mixed $value): string
{
    if ($value === null) {
        $value = '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t"], true) && !preg_match('/^-?\d+(?:[.,]\d+)?$/', $text)) {
        $text = "'" . $text;
    }
    return '"' . str_replace('"', '""', $text) . '"';
}

function tb_csv_build(array $header, array $rows, string $separator = ';'): string
{
    $lines = [implode($separator, array_map('tb_csv_cell', $header))];
    foreach ($rows as $row) {
        $lines[] = implode($separator, array_map('tb_csv_cell', array_values($row)));
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

function tb_send_csv(string $filename, string $csv): void
{
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.csv';
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: no-store, private');
    echo $csv;
    exit;
}

/**
 * Alkalmazás kulcs (TB_APP_KEY). Ha nincs beállítva, telepítés-specifikus fallback kulcs készül,
 * de a diagnosztika figyelmeztet, hogy éles környezetben állítsuk be.
 */
function tb_app_key_configured(): bool
{
    return strlen((string) env_or_fallback(['TB_APP_KEY'], '')) >= 32;
}

function tb_app_key(): string
{
    $key = (string) env_or_fallback(['TB_APP_KEY'], '');
    if ($key === '') {
        $key = 'tb-fallback|' . (string) env_value('TB_DB_PASS', '') . '|' . (string) env_value('TB_DB_NAME', 'tamasbau') . '|' . (string) env_or_fallback(['TB_APP_NAME'], 'tamasbau');
    }
    return hash('sha256', $key, true);
}

function tb_secret_encrypt(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        return 'b64:' . base64_encode($plain);
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', tb_app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('A titkosítás nem sikerült.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function tb_secret_decrypt(string $stored): ?string
{
    if (str_starts_with($stored, 'v1:')) {
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', tb_app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
    if (str_starts_with($stored, 'b64:')) {
        $plain = base64_decode(substr($stored, 4), true);
        return $plain === false ? null : $plain;
    }
    $legacy = base64_decode($stored, true);
    return $legacy === false ? null : $legacy;
}

function tb_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function tb_column_exists(string $table, string $column): bool
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function tb_pagination(int $defaultLimit = 25, int $maxLimit = 100): array
{
    $limit = max(1, min($maxLimit, (int) ($_GET['limit'] ?? $defaultLimit)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    return [$limit, $offset];
}
