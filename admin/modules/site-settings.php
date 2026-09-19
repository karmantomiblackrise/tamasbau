<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_module_helpers.php';
require_admin();

$pageTitle = 'Oldalbeállítások';
$activeNav = 'site-settings';
$rows = [];
$count = null;

try {
    $pdo = db();
    $count = count_if_table_exists($pdo, 'site_settings');
    $rows = fetch_recent_rows($pdo, 'site_settings', ['id','setting_key','setting_value','updated_at']);
} catch (Throwable $exception) {
    flash_set('error', db_connection_error_message($exception));
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="card" style="margin-bottom:12px;">
    <h3><?= h('Oldalbeállítások') ?></h3>
    <div class="card__value"><?= h((string) ($count ?? 0)) ?></div>
    <p class="helper">Tábla: <?= h('site_settings') ?></p>
</div>
<?php render_module_table(['ID','Kulcs','Érték','Frissítve'], $rows); ?>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
