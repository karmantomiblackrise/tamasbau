<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_module_helpers.php';
require_admin();

$pageTitle = 'Kapcsolati üzenetek';
$activeNav = 'messages';
$rows = [];
$count = null;

try {
    $pdo = db();
    $count = count_if_table_exists($pdo, 'contact_messages');
    $rows = fetch_recent_rows($pdo, 'contact_messages', ['id','name','email','created_at']);
} catch (Throwable $exception) {
    flash_set('error', db_connection_error_message($exception));
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="card" style="margin-bottom:12px;">
    <h3><?= h('Kapcsolati üzenetek') ?></h3>
    <div class="card__value"><?= h((string) ($count ?? 0)) ?></div>
    <p class="helper">Tábla: <?= h('contact_messages') ?></p>
</div>
<?php render_module_table(['ID','Név','Email','Létrehozva'], $rows); ?>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
