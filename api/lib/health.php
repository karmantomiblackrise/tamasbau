<?php
declare(strict_types=1);

/*
 * Üzemeltetési diagnosztika (health check). Titkot, jelszót, elérési utat nem ad vissza.
 */

function tb_health_required_schema(): array
{
    return [
        'tables' => ['users', 'products', 'orders', 'quotes', 'support_chats', 'projects', 'work_orders', 'service_tickets', 'in_app_notifications', 'workflow_rules', 'workflow_jobs', 'user_sessions', 'user_login_events', 'user_totp_settings', 'digital_signatures', 'customer_reviews', 'review_requests', 'invoice_drafts', 'work_order_notes'],
        'columns' => [
            ['workflow_jobs', 'next_attempt_at'],
            ['user_totp_settings', 'last_used_step'],
            ['service_tickets', 'sla_breached_at'],
            ['digital_signatures', 'document_hash'],
            ['customer_reviews', 'follow_up_status'],
            ['work_order_sync_operations', 'resolution'],
        ],
    ];
}

function tb_health_check_item(string $key, string $label, string $status, string $message): array
{
    return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
}

function tb_health_checks(): array
{
    $root = dirname(__DIR__, 2);
    $checks = [];

    $dbOk = false;
    try {
        $start = microtime(true);
        db()->query('SELECT 1')->fetch();
        $dbOk = true;
        $checks[] = tb_health_check_item('database', 'Adatbázis kapcsolat', 'ok', 'Elérhető (' . round((microtime(true) - $start) * 1000) . ' ms).');
    } catch (Throwable $e) {
        app_log_error('health_db_failed', $e);
        $checks[] = tb_health_check_item('database', 'Adatbázis kapcsolat', 'fail', 'Nem elérhető. Ellenőrizze a TB_DB_* beállításokat (.env).');
    }

    if ($dbOk) {
        $schema = tb_health_required_schema();
        $missing = [];
        foreach ($schema['tables'] as $table) {
            if (!tb_table_exists($table)) {
                $missing[] = $table;
            }
        }
        foreach ($schema['columns'] as [$table, $column]) {
            if (!in_array($table, $missing, true) && !tb_column_exists($table, $column)) {
                $missing[] = $table . '.' . $column;
            }
        }
        $checks[] = $missing
            ? tb_health_check_item('schema', 'Adatbázis séma / migrációk', 'fail', 'Hiányzik: ' . implode(', ', $missing) . '. Futtassa a database/migrations fájlokat (README).')
            : tb_health_check_item('schema', 'Adatbázis séma / migrációk', 'ok', 'Minden szükséges tábla és oszlop megvan.');
    }

    $notWritable = [];
    foreach (['logs', 'uploads', 'uploads/products', 'uploads/project-files'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (!is_dir($path) || !is_writable($path)) {
            $notWritable[] = $dir . '/';
        }
    }
    $checks[] = $notWritable
        ? tb_health_check_item('write_permissions', 'Írási jogosultságok', 'fail', 'Nem írható: ' . implode(', ', $notWritable) . ' (javasolt: 755 vagy 775).')
        : tb_health_check_item('write_permissions', 'Írási jogosultságok', 'ok', 'logs/ és uploads/ írható.');

    $smtpConfigured = function_exists('smtp_mailer_configured') && smtp_mailer_configured();
    $phpMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer') || is_file($root . '/vendor/phpmailer/phpmailer/src/PHPMailer.php') || is_file($root . '/vendor/PHPMailer/src/PHPMailer.php') || is_file($root . '/api/vendor/PHPMailer/src/PHPMailer.php');
    if (!$smtpConfigured) {
        $checks[] = tb_health_check_item('smtp', 'SMTP beállítás', 'warn', 'Az SMTP nincs teljesen beállítva (MAIL_HOST / MAIL_FROM_ADDRESS). Élő teszt: Admin → SMTP diagnosztika.');
    } else {
        $checks[] = tb_health_check_item('smtp', 'SMTP beállítás', 'ok', 'Beállítva' . ($phpMailer ? ' (PHPMailer elérhető).' : ' (beépített socket kliens).') . ' Élő teszt: Admin → SMTP diagnosztika.');
    }

    $missingExt = [];
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'] as $ext) {
        if (!extension_loaded($ext)) {
            $missingExt[] = $ext;
        }
    }
    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $checks[] = (!$phpOk || $missingExt)
        ? tb_health_check_item('php', 'PHP környezet', 'fail', 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ($missingExt ? '; hiányzó kiterjesztés: ' . implode(', ', $missingExt) : '') . ' (min. PHP 8.1).')
        : tb_health_check_item('php', 'PHP környezet', 'ok', 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ', szükséges kiterjesztések betöltve' . (extension_loaded('gd') ? '.' : ' (a gd nem kötelező).'));

    $envWarnings = [];
    if (app_env() !== 'production') {
        $envWarnings[] = 'APP_ENV nem production';
    }
    if (!tb_app_key_configured()) {
        $envWarnings[] = 'TB_APP_KEY nincs beállítva (min. 32 karakter)';
    }
    if (!str_starts_with((string) app_config()['app_url'], 'https://')) {
        $envWarnings[] = 'TB_APP_URL nem HTTPS';
    }
    $checks[] = $envWarnings
        ? tb_health_check_item('config', 'Konfiguráció', 'warn', implode('; ', $envWarnings) . '.')
        : tb_health_check_item('config', 'Konfiguráció', 'ok', 'Éles beállítások rendben.');

    if ($dbOk && tb_table_exists('workflow_jobs')) {
        try {
            $failed = (int) db()->query("SELECT COUNT(*) AS c FROM workflow_jobs WHERE status = 'failed'")->fetch()['c'];
            $overdue = (int) db()->query("SELECT COUNT(*) AS c FROM workflow_jobs WHERE status = 'pending' AND COALESCE(next_attempt_at, created_at) < (NOW() - INTERVAL 1 HOUR)")->fetch()['c'];
            $status = $failed > 0 || $overdue > 0 ? 'warn' : 'ok';
            $checks[] = tb_health_check_item('workflows', 'Workflow sor', $status, 'Sikertelen: ' . $failed . ', 1 óránál régebben várakozó: ' . $overdue . '.');
        } catch (Throwable $e) {
            $checks[] = tb_health_check_item('workflows', 'Workflow sor', 'warn', 'Nem ellenőrizhető.');
        }
    }

    $free = @disk_free_space($root);
    if ($free !== false) {
        $mb = (int) round($free / 1048576);
        $checks[] = tb_health_check_item('disk', 'Szabad tárhely', $mb < 200 ? 'warn' : 'ok', $mb . ' MB szabad.');
    }

    $overall = 'ok';
    foreach ($checks as $check) {
        if ($check['status'] === 'fail') {
            $overall = 'fail';
            break;
        }
        if ($check['status'] === 'warn') {
            $overall = 'degraded';
        }
    }
    return ['status' => $overall, 'checked_at' => date('c'), 'checks' => $checks];
}
