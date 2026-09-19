<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Rendszerállapot';
$activeNav = 'system-status';

$statusRows = [];

try {
    $pdo = db();
    $requiredTables = [
        'users',
        'blog_posts',
        'price_list_items',
        'services',
        'menus',
        'site_settings',
        'quote_requests',
        'contact_messages',
        'categories',
        'brands',
        'products',
        'orders',
        'order_items',
        'shipping_methods',
        'payment_methods',
        'shop_settings',
    ];

    foreach ($requiredTables as $table) {
        $statusRows[] = [$table, table_exists($pdo, $table) ? 'OK' : 'Hiányzik'];
    }

    $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    $statusRows[] = ['Aktív admin felhasználók', (string) $activeAdmins];
    $statusRows[] = ['APP_BASE_PATH', app_base_path() === '' ? '(root)' : app_base_path()];
} catch (Throwable $exception) {
    $statusRows[] = ['Adatbázis', db_connection_error_message($exception)];
}

require_once __DIR__ . '/partials/header.php';
?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Ellenőrzés</th><th>Állapot</th></tr></thead>
        <tbody>
        <?php foreach ($statusRows as [$name, $state]): ?>
            <tr>
                <td><?= h($name) ?></td>
                <td><?= h($state) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="helper" style="margin-top:10px;">Bővebb telepítési diagnosztika: <a href="<?= h(app_url('admin/setup-check.php')) ?>">setup-check.php</a></p>
<?php require_once __DIR__ . '/partials/footer.php';
