<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$stats = [];

try {
    $pdo = db();
    $lowStock = 0;
    if (table_exists($pdo, 'products')) {
        $lowStockStmt = $pdo->query('SELECT COUNT(*) FROM products WHERE stock <= low_stock_threshold');
        $lowStock = (int) $lowStockStmt->fetchColumn();
    }

    $stats = [
        'Tartalom' => (count_if_table_exists($pdo, 'blog_posts') ?? 0) + (count_if_table_exists($pdo, 'price_list_items') ?? 0),
        'Ajánlatkérések' => count_if_table_exists($pdo, 'quote_requests') ?? 0,
        'Üzenetek' => count_if_table_exists($pdo, 'contact_messages') ?? 0,
        'Rendelések' => count_if_table_exists($pdo, 'orders') ?? 0,
        'Alacsony készlet' => $lowStock,
        'Webshop állapot' => table_exists($pdo, 'products') && table_exists($pdo, 'orders') ? 'OK' : 'Hiányos',
    ];
} catch (Throwable $exception) {
    $stats = [
        'Rendszer' => 'Adatbázis hiba',
        'Részlet' => db_connection_error_message($exception),
    ];
}

require_once __DIR__ . '/partials/header.php';
?>
<div class="cards">
    <?php foreach ($stats as $label => $value): ?>
        <div class="card">
            <h3><?= h((string) $label) ?></h3>
            <div class="card__value"><?= h((string) $value) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/partials/footer.php';
