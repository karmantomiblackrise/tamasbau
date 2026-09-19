<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Alacsony készlet';
$activeNav = 'stock';
$rows = [];

try {
    $pdo = db();
    if (table_exists($pdo, 'products')) {
        $stmt = $pdo->query('SELECT id, name, stock, low_stock_threshold FROM products WHERE stock <= low_stock_threshold ORDER BY stock ASC LIMIT 50');
        $rows = $stmt->fetchAll() ?: [];
    }
} catch (Throwable $exception) {
    flash_set('error', db_connection_error_message($exception));
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="card" style="margin-bottom:12px;">
    <h3>Alacsony készletű termékek</h3>
    <div class="card__value"><?= h((string) count($rows)) ?></div>
</div>
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Termék</th><th>Készlet</th><th>Küszöb</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="4">Nincs alacsony készletű termék.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= h((string) $row['id']) ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h((string) $row['stock']) ?></td>
                <td><?= h((string) $row['low_stock_threshold']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
