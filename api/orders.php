<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$user = current_user();

if ($method === 'GET') {
    if ($user && ($user['role'] ?? 'user') === 'admin') {
        $stmt = db()->query('SELECT o.id, o.user_id, o.total, o.status, o.created_at, u.name AS user_name, u.email AS user_email
                             FROM orders o
                             LEFT JOIN users u ON u.id = o.user_id
                             ORDER BY o.created_at DESC');
    } elseif ($user) {
        $stmt = db()->prepare('SELECT o.id, o.user_id, o.total, o.status, o.created_at, u.name AS user_name, u.email AS user_email
                               FROM orders o
                               LEFT JOIN users u ON u.id = o.user_id
                               WHERE o.user_id = ?
                               ORDER BY o.created_at DESC');
        $stmt->execute([(int) $user['id']]);
    } else {
        send_json(['ok' => true, 'orders' => []]);
    }

    $orders = [];
    foreach ($stmt->fetchAll() as $row) {
        $itemsStmt = db()->prepare('SELECT oi.id, oi.product_id, oi.qty, oi.unit_price, p.name
                                    FROM order_items oi
                                    INNER JOIN products p ON p.id = oi.product_id
                                    WHERE oi.order_id = ?');
        $itemsStmt->execute([(int) $row['id']]);

        $orders[] = [
            'id' => (int) $row['id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'customer' => $row['user_name'] ?: 'Vendég vásárló',
            'email' => $row['user_email'],
            'total' => (int) $row['total'],
            'status' => $row['status'],
            'date' => substr((string) $row['created_at'], 0, 10),
            'created_at' => $row['created_at'],
            'items' => array_map(static fn(array $item): array => [
                'id' => (int) $item['id'],
                'product_id' => (int) $item['product_id'],
                'name' => $item['name'],
                'qty' => (int) $item['qty'],
                'unit_price' => (int) $item['unit_price'],
            ], $itemsStmt->fetchAll()),
        ];
    }

    send_json(['ok' => true, 'orders' => $orders]);
}

if ($method === 'POST') {
    $action = clean_string($payload['action'] ?? 'create');

    if ($action === 'create') {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (!$items) {
            send_json(['ok' => false, 'error' => 'A kosár üres.'], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $total = 0;
            $validatedItems = [];
            $prodStmt = $pdo->prepare('SELECT id, price, stock FROM products WHERE id = ? LIMIT 1');

            foreach ($items as $item) {
                $productId = (int) ($item['id'] ?? 0);
                $qty = max(1, (int) ($item['qty'] ?? 1));
                if ($productId <= 0) {
                    continue;
                }

                $prodStmt->execute([$productId]);
                $product = $prodStmt->fetch();
                if (!$product || (int) $product['stock'] < $qty) {
                    throw new RuntimeException('Nincs elegendő készlet a rendeléshez.');
                }

                $unitPrice = (int) $product['price'];
                $total += $unitPrice * $qty;
                $validatedItems[] = ['product_id' => $productId, 'qty' => $qty, 'unit_price' => $unitPrice];
            }

            if (!$validatedItems) {
                throw new RuntimeException('Nincs rendelhető tétel a kosárban.');
            }

            $orderStmt = $pdo->prepare('INSERT INTO orders (user_id, total, status) VALUES (?, ?, ?)');
            $orderStmt->execute([$user ? (int) $user['id'] : null, $total, 'Feldolgozás alatt']);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (?, ?, ?, ?)');
            $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
            foreach ($validatedItems as $row) {
                $itemStmt->execute([$orderId, $row['product_id'], $row['qty'], $row['unit_price']]);
                $stockStmt->execute([$row['qty'], $row['product_id']]);
            }

            $pdo->commit();
            send_json(['ok' => true, 'order_id' => $orderId], 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            send_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    require_admin();
    if ($action === 'update_status') {
        $id = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 60);
        if ($id <= 0 || $status === '') {
            send_json(['ok' => false, 'error' => 'Hiányzó rendelés adatok.'], 422);
        }

        $stmt = db()->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
