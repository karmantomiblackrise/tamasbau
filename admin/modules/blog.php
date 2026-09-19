<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_module_helpers.php';
require_admin();

$pageTitle = 'Blog';
$activeNav = 'blog';
$rows = [];
$count = null;

try {
    $pdo = db();
    $count = count_if_table_exists($pdo, 'blog_posts');
    $rows = fetch_recent_rows($pdo, 'blog_posts', ['id','title','status','updated_at']);
} catch (Throwable $exception) {
    flash_set('error', db_connection_error_message($exception));
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="card" style="margin-bottom:12px;">
    <h3><?= h('Blog') ?></h3>
    <div class="card__value"><?= h((string) ($count ?? 0)) ?></div>
    <p class="helper">Tábla: <?= h('blog_posts') ?></p>
</div>
<?php render_module_table(['ID','Cím','Állapot','Frissítve'], $rows); ?>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
