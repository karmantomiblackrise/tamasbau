<?php
declare(strict_types=1);

/*
 * Ütemezett automatika (opcionális – cron nélkül az admin felület is futtatja).
 * cPanel → Cron Jobs, pl. 5 percenként:
 *   php /home/FELHASZNALO/public_html/api/cron.php >/dev/null 2>&1
 * Kapcsolók: --sla (SLA ellenőrzés azonnal), --health (diagnosztika kiírása)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/platform-lib.php';

$args = array_slice($argv ?? [], 1);
$out = ['started_at' => date('c')];
try {
    $out['automation'] = tb_automation_tick(true);
    if (in_array('--sla', $args, true)) {
        $out['sla'] = tb_sla_run(tb_workflow_rule_config('service_sla_monitor'));
    }
    if (in_array('--health', $args, true)) {
        $out['health'] = tb_health_checks();
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error_id'] = app_log_error('cron', $e);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
exit($out['ok'] ? 0 : 1);
