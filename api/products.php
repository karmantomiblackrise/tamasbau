<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $search = mb_strtolower(clean_string($_GET['search'] ?? '', 120));
    $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : 0;

    $sql = 'SELECT p.id, p.name, p.category_id, c.name AS category_name, c.parent_id, p.price, p.stock, p.icon, p.description, p.created_at
            FROM products p
            INNER JOIN categories c ON c.id = p.category_id
            WHERE 1 = 1';
    $args = [];

    if ($categoryId > 0) {
        $sql .= ' AND p.category_id = ?';
        $args[] = $categoryId;
    }

    if ($search !== '') {
        $sql .= ' AND (LOWER(p.name) LIKE ? OR LOWER(p.description) LIKE ?)';
        $args[] = '%' . $search . '%';
        $args[] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY p.created_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $products = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'category_id' => (int) $row['category_id'],
            'category_name' => $row['category_name'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'price' => (int) $row['price'],
            'stock' => (int) $row['stock'],
            'icon' => $row['icon'] ?: 'fa-box',
            'desc' => $row['description'] ?? '',
            'created_at' => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    send_json(['ok' => true, 'products' => $products]);
}

require_admin();

if ($method === 'POST') {
    $action = clean_string($payload['action'] ?? '');

    if ($action === 'create') {
        $name = clean_string($payload['name'] ?? '', 160);
        $categoryId = (int) ($payload['category_id'] ?? 0);
        $price = (int) ($payload['price'] ?? 0);
        $stock = (int) ($payload['stock'] ?? 0);
        $icon = clean_string($payload['icon'] ?? 'fa-box', 80);
        $description = clean_string($payload['desc'] ?? '', 1500);

        if ($name === '' || $categoryId <= 0 || $price < 0 || $stock < 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen termék adatok.'], 422);
        }

        $stmt = db()->prepare('INSERT INTO products (name, category_id, price, stock, icon, description) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $categoryId, $price, $stock, $icon ?: 'fa-box', $description]);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    if ($action === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        $name = clean_string($payload['name'] ?? '', 160);
        $categoryId = (int) ($payload['category_id'] ?? 0);
        $price = (int) ($payload['price'] ?? 0);
        $stock = (int) ($payload['stock'] ?? 0);
        $icon = clean_string($payload['icon'] ?? 'fa-box', 80);
        $description = clean_string($payload['desc'] ?? '', 1500);

        if ($id <= 0 || $name === '' || $categoryId <= 0 || $price < 0 || $stock < 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen termék adatok.'], 422);
        }

        $stmt = db()->prepare('UPDATE products SET name = ?, category_id = ?, price = ?, stock = ?, icon = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $categoryId, $price, $stock, $icon ?: 'fa-box', $description, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                send_json(['ok' => false, 'error' => 'Termék nem található.'], 404);
            }
        }
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen termék azonosító.'], 422);
        }
        try {
            $stmt = db()->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            send_json(['ok' => false, 'error' => 'A már rendelt termék nem törölhető közvetlenül.'], 422);
        }
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Termék nem található.'], 404);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
