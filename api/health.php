<?php
declare(strict_types=1);

/*
 * Health check végpont.
 *  - Nyilvános hívás: csak összesített állapot (ok/fail), részletek nélkül.
 *  - Részletes diagnosztika: bejelentkezett admin/superadmin, VAGY X-Health-Token fejléc
 *    (TB_HEALTH_TOKEN .env érték, legalább 32 karakter) külső monitorozáshoz.
 * Titkot, jelszót, fájlrendszer útvonalat soha nem ad vissza.
 */

require_once __DIR__ . '/platform-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json(['ok' => false, 'error' => 'Csak GET kérés támogatott.'], 405);
}

enforce_rate_limit('health_check', 60, 60);

$detailed = false;
$token = (string) env_or_fallback(['TB_HEALTH_TOKEN'], '');
$given = (string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');
if (strlen($token) >= 32 && $given !== '' && hash_equals($token, $given)) {
    $detailed = true;
} else {
    $user = current_user();
    if ($user && in_array((string) $user['role'], ['admin', 'superadmin'], true)) {
        $detailed = true;
    }
}

if ($detailed) {
    $result = tb_health_checks();
    send_json(['ok' => $result['status'] !== 'fail'] + $result, $result['status'] === 'fail' ? 503 : 200);
}

$dbOk = true;
try {
    db()->query('SELECT 1')->fetch();
} catch (Throwable $e) {
    $dbOk = false;
    app_log_error('health_public_db', $e);
}
send_json(['ok' => $dbOk, 'status' => $dbOk ? 'ok' : 'fail', 'time' => date('c')], $dbOk ? 200 : 503);
