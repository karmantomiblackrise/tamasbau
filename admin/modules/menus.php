<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_module_helpers.php';
require_admin();

$pageTitle = 'Menük';
$activeNav = 'menus';
$rows = [];
$count = null;

try {
    $pdo = db();
    $count = count_if_table_exists($pdo, 'menus');
    $rows = fetch_recent_rows($pdo, 'menus', ['id','title','url','updated_at']);
} catch (Throwable $exception) {
    flash_set('error', db_connection_error_message($exception));
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="card" style="margin-bottom:12px;">
    <h3><?= h('Menük') ?></h3>
    <div class="card__value"><?= h((string) ($count ?? 0)) ?></div>
    <p class="helper">Tábla: <?= h('menus') ?></p>
</div>
<?php render_module_table(['ID','Cím','URL','Frissítve'], $rows); ?>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
