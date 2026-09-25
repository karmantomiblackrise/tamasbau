<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$admin = require_admin();
enforce_rate_limit('admin_analytics', 120, 900);

if ($method === 'GET') {
    $pdo = db();

    $kpiStmt = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total ELSE 0 END),0) AS revenue_day,
            COALESCE(SUM(CASE WHEN YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1) THEN total ELSE 0 END),0) AS revenue_week,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN total ELSE 0 END),0) AS revenue_month,
            SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS orders_day,
            SUM(CASE WHEN YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1) THEN 1 ELSE 0 END) AS orders_week,
            SUM(CASE WHEN DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 1 ELSE 0 END) AS orders_month
         FROM orders"
    );
    $kpiOrders = $kpiStmt->fetch() ?: [];

    $kpiUsersStmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS users_day,
            SUM(CASE WHEN YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1) THEN 1 ELSE 0 END) AS users_week,
            SUM(CASE WHEN DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 1 ELSE 0 END) AS users_month
         FROM users"
    );
    $kpiUsers = $kpiUsersStmt->fetch() ?: [];

    $quotesNew = (int) ($pdo->query("SELECT COUNT(*) FROM quotes WHERE status='new'")->fetchColumn() ?: 0);
    $supportUnread = (int) ($pdo->query("SELECT COALESCE(SUM(admin_unread_count),0) FROM support_chats WHERE deleted_at IS NULL")->fetchColumn() ?: 0);

    $topProductsStmt = $pdo->query(
        "SELECT p.id, p.name, COALESCE(SUM(oi.qty),0) AS sold_qty
         FROM products p
         LEFT JOIN order_items oi ON oi.product_id = p.id
         GROUP BY p.id, p.name
         ORDER BY sold_qty DESC, p.name ASC
         LIMIT 10"
    );
    $topProducts = $topProductsStmt->fetchAll();

    $topCategoriesStmt = $pdo->query(
        "SELECT c.id, c.name, COALESCE(SUM(oi.qty),0) AS sold_qty
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id
         LEFT JOIN order_items oi ON oi.product_id = p.id
         GROUP BY c.id, c.name
         ORDER BY sold_qty DESC, c.name ASC
         LIMIT 10"
    );
    $topCategories = $topCategoriesStmt->fetchAll();

    $lowStockThreshold = max(0, (int) ($_GET['low_stock_threshold'] ?? 5));
    $lowStockStmt = $pdo->prepare('SELECT id, name, stock FROM products WHERE stock <= ? ORDER BY stock ASC, name ASC LIMIT 100');
    $lowStockStmt->execute([$lowStockThreshold]);
    $lowStock = $lowStockStmt->fetchAll();

    send_json([
        'ok' => true,
        'kpis' => [
            'revenue_day' => (int) ($kpiOrders['revenue_day'] ?? 0),
            'revenue_week' => (int) ($kpiOrders['revenue_week'] ?? 0),
            'revenue_month' => (int) ($kpiOrders['revenue_month'] ?? 0),
            'orders_day' => (int) ($kpiOrders['orders_day'] ?? 0),
            'orders_week' => (int) ($kpiOrders['orders_week'] ?? 0),
            'orders_month' => (int) ($kpiOrders['orders_month'] ?? 0),
            'new_users_day' => (int) ($kpiUsers['users_day'] ?? 0),
            'new_users_week' => (int) ($kpiUsers['users_week'] ?? 0),
            'new_users_month' => (int) ($kpiUsers['users_month'] ?? 0),
            'new_quotes' => $quotesNew,
            'support_unread_messages' => $supportUnread,
        ],
        'top_products' => $topProducts,
        'top_categories' => $topCategories,
        'low_stock_threshold' => $lowStockThreshold,
        'low_stock_products' => $lowStock,
    ]);
}

if ($method === 'POST') {
    $payload = get_json_input();
    $action = clean_string((string) ($payload['action'] ?? ''), 40);
    if ($action !== 'export_csv') {
        send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
    }

    $type = clean_string((string) ($payload['type'] ?? ''), 40);
    $exports = [
        'orders' => ['sql' => 'SELECT id, user_id, total, status, created_at FROM orders ORDER BY created_at DESC', 'filename' => 'orders-export.csv'],
        'products' => ['sql' => 'SELECT id, name, category_id, price, stock, created_at FROM products ORDER BY id DESC', 'filename' => 'products-export.csv'],
        'users' => ['sql' => 'SELECT id, name, email, role, phone, is_active, created_at FROM users ORDER BY id DESC', 'filename' => 'users-export.csv'],
        'quotes' => ['sql' => 'SELECT id, name, phone, email, work_type, status, created_at FROM quotes ORDER BY created_at DESC', 'filename' => 'quotes-export.csv'],
    ];
    if (!isset($exports[$type])) {
        send_json(['ok' => false, 'error' => 'Ismeretlen export típus.'], 422);
    }

    $stmt = db()->query($exports[$type]['sql']);
    $rows = $stmt->fetchAll();
    $columns = $rows ? array_keys($rows[0]) : [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $exports[$type]['filename'] . '"');
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        send_json(['ok' => false, 'error' => 'CSV export hiba.'], 500);
    }
    if ($columns) {
        fputcsv($out, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = (string) ($row[$column] ?? '');
            }
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
