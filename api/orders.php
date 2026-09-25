<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $user = require_login();
    if ($user && ($user['role'] ?? 'user') === 'admin') {
        $stmt = db()->query('SELECT o.id, o.user_id, o.total, o.status, o.created_at, u.name AS user_name, u.email AS user_email
                             FROM orders o
                             LEFT JOIN users u ON u.id = o.user_id
                             ORDER BY o.created_at DESC');
    } else {
        $stmt = db()->prepare('SELECT o.id, o.user_id, o.total, o.status, o.created_at, u.name AS user_name, u.email AS user_email
                               FROM orders o
                               LEFT JOIN users u ON u.id = o.user_id
                               WHERE o.user_id = ?
                               ORDER BY o.created_at DESC');
        $stmt->execute([(int) $user['id']]);
    }

    $orderRows = $stmt->fetchAll();
    $orderIds = array_map(static fn(array $row): int => (int) $row['id'], $orderRows);
    $itemsByOrder = [];
    if ($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemsStmt = db()->prepare('SELECT oi.id, oi.order_id, oi.product_id, oi.qty, oi.unit_price, p.name
                                    FROM order_items oi
                                    LEFT JOIN products p ON p.id = oi.product_id
                                    WHERE oi.order_id IN (' . $placeholders . ')');
        $itemsStmt->execute($orderIds);
        foreach ($itemsStmt->fetchAll() as $item) {
            $orderId = (int) $item['order_id'];
            if (!isset($itemsByOrder[$orderId])) {
                $itemsByOrder[$orderId] = [];
            }
            $itemsByOrder[$orderId][] = [
                'id' => (int) $item['id'],
                'product_id' => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                'name' => $item['name'] ?: 'Törölt termék',
                'qty' => (int) $item['qty'],
                'unit_price' => (int) $item['unit_price'],
            ];
        }
    }

    $orders = [];
    foreach ($orderRows as $row) {
        $orderId = (int) $row['id'];
        $orders[] = [
            'id' => $orderId,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'customer' => $row['user_name'] ?: 'Vendég vásárló',
            'email' => $row['user_email'],
            'total' => (int) $row['total'],
            'status' => $row['status'],
            'date' => substr((string) $row['created_at'], 0, 10),
            'created_at' => $row['created_at'],
            'items' => $itemsByOrder[$orderId] ?? [],
        ];
    }

    send_json(['ok' => true, 'orders' => $orders]);
}

if ($method === 'POST') {
    $action = clean_string($payload['action'] ?? 'create');

    if ($action === 'create') {
        $user = require_login();
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
            $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            foreach ($validatedItems as $row) {
                $stockStmt->execute([$row['qty'], $row['product_id'], $row['qty']]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Készlethiány miatt a rendelés nem teljesíthető.');
                }
                $itemStmt->execute([$orderId, $row['product_id'], $row['qty'], $row['unit_price']]);
            }

            $pdo->commit();
            send_json(['ok' => true, 'order_id' => $orderId], 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            send_json(['ok' => false, 'error' => 'A rendelés feldolgozása sikertelen.'], 500);
        }
    }

    if ($action === 'update_status') {
        require_admin();
        $id = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 60);
        $allowedStatuses = ['Feldolgozás alatt', 'Teljesítve', 'Lemondva'];
        if ($id <= 0 || !in_array($status, $allowedStatuses, true)) {
            send_json(['ok' => false, 'error' => 'Hiányzó rendelés adatok.'], 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $currentStmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                throw new RuntimeException('Rendelés nem található.');
            }
            if ($current['status'] === 'Teljesítve' && $status === 'Lemondva') {
                throw new RuntimeException('Teljesített rendelés nem mondható le.');
            }

            if ($status === 'Lemondva' && $current['status'] !== 'Lemondva') {
                $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL');
                $itemsStmt->execute([$id]);
                $restockStmt = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                foreach ($itemsStmt->fetchAll() as $item) {
                    $restockStmt->execute([(int) $item['qty'], (int) $item['product_id']]);
                }
            }
            if ($status !== 'Lemondva' && $current['status'] === 'Lemondva') {
                $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL');
                $itemsStmt->execute([$id]);
                $reserveStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                foreach ($itemsStmt->fetchAll() as $item) {
                    $qty = (int) $item['qty'];
                    $productId = (int) $item['product_id'];
                    $reserveStmt->execute([$qty, $productId, $qty]);
                    if ($reserveStmt->rowCount() !== 1) {
                        throw new RuntimeException('Nincs elegendő készlet a rendelés újraaktiválásához.');
                    }
                }
            }

            $updateStmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
            $updateStmt->execute([$status, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'Rendelés nem található.') {
                send_json(['ok' => false, 'error' => $e->getMessage()], 404);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'Nincs elegendő készlet a rendelés újraaktiválásához.') {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'Teljesített rendelés nem mondható le.') {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            send_json(['ok' => false, 'error' => 'A rendelés állapota nem frissíthető.'], 422);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
