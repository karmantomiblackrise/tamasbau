<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/checkout-payment.php';

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
        validate_csrf_token();
        $user = require_login();
        enforce_rate_limit('checkout_create', 12, 900);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $checkout = is_array($payload['checkout'] ?? null) ? $payload['checkout'] : [];
        $shipping = is_array($checkout['shipping'] ?? null) ? $checkout['shipping'] : [];
        $billing = is_array($checkout['billing'] ?? null) ? $checkout['billing'] : [];
        $shippingMethod = clean_string((string) ($checkout['shipping_method'] ?? 'standard'), 40);
        $paymentMeta = resolve_checkout_payment((string) ($checkout['payment_method'] ?? ''));
        $initialStatus = $paymentMeta['status'] === 'payment_pending' ? 'Fizetésre vár' : 'Feldolgozás alatt';
        if (!$items) {
            send_json(['ok' => false, 'error' => 'A kosár üres.'], 422);
        }
        if (!in_array($shippingMethod, ['standard', 'express', 'pickup'], true)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen szállítási mód.'], 422);
        }
        $shippingName = clean_string((string) ($shipping['name'] ?? ''), 120);
        $shippingPhone = clean_string((string) ($shipping['phone'] ?? ''), 40);
        $shippingAddress = clean_string((string) ($shipping['address'] ?? ''), 255);
        $shippingCity = clean_string((string) ($shipping['city'] ?? ''), 120);
        $shippingPostal = clean_string((string) ($shipping['postal_code'] ?? ''), 20);
        $billingName = clean_string((string) ($billing['name'] ?? ''), 120);
        $billingTaxNumber = clean_string((string) ($billing['tax_number'] ?? ''), 60);
        $billingAddress = clean_string((string) ($billing['address'] ?? ''), 255);
        $billingCity = clean_string((string) ($billing['city'] ?? ''), 120);
        $billingPostal = clean_string((string) ($billing['postal_code'] ?? ''), 20);
        if (
            $shippingName === '' || $shippingAddress === '' || $shippingCity === '' || $shippingPostal === ''
            || $billingName === '' || $billingAddress === '' || $billingCity === '' || $billingPostal === ''
        ) {
            send_json(['ok' => false, 'error' => 'Hiányos szállítási vagy számlázási adatok.'], 422);
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

            $orderStmt = $pdo->prepare(
                'INSERT INTO orders (
                    user_id, total,
                    shipping_name, shipping_phone, shipping_postal_code, shipping_city, shipping_address,
                    billing_name, billing_tax_number, billing_postal_code, billing_city, billing_address,
                    shipping_method, payment_method, payment_provider, payment_status, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $orderStmt->execute([
                $user ? (int) $user['id'] : null,
                $total,
                $shippingName,
                $shippingPhone ?: null,
                $shippingPostal,
                $shippingCity,
                $shippingAddress,
                $billingName,
                $billingTaxNumber ?: null,
                $billingPostal,
                $billingCity,
                $billingAddress,
                $shippingMethod,
                $paymentMeta['method'],
                $paymentMeta['provider'],
                $paymentMeta['status'],
                $initialStatus,
            ]);
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
            $mailWarning = null;
            try {
                $mailUserStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
                $mailUserStmt->execute([(int) $user['id']]);
                $mailUser = $mailUserStmt->fetch() ?: ['name' => $user['name'] ?? '', 'email' => $user['email'] ?? ''];
                $isPaymentPending = $paymentMeta['status'] === 'payment_pending';
                $subject = $isPaymentPending
                    ? sprintf('Rendelés rögzítve, fizetés függőben #%d', $orderId)
                    : sprintf('Rendelés visszaigazolás #%d', $orderId);
                $htmlBody = sprintf(
                    '<p>Kedves %s!</p><p>%s Azonosító: <strong>#%d</strong>.</p><p>Végösszeg: <strong>%s Ft</strong>, fizetés: <strong>%s</strong>.</p>',
                    htmlspecialchars((string) ($mailUser['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $isPaymentPending ? 'Rendelését rögzítettük, az online fizetés megerősítésére várunk.' : 'Köszönjük rendelését.',
                    $orderId,
                    number_format($total, 0, ',', ' '),
                    htmlspecialchars((string) $paymentMeta['method'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
                send_app_mail((string) ($mailUser['email'] ?? ''), (string) ($mailUser['name'] ?? ''), $subject, $htmlBody);
            } catch (Throwable $mailException) {
                $mailWarning = 'A visszaigazoló e-mail küldése sikertelen, de a rendelés rögzítve lett.';
            }

            send_json([
                'ok' => true,
                'order_id' => $orderId,
                'payment' => $paymentMeta,
                'shipping_method' => $shippingMethod,
                'warning' => $mailWarning,
            ], 201);
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
        $adminUser = require_admin();
        $id = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 60);
        $allowedStatuses = ['Fizetésre vár', 'Feldolgozás alatt', 'Teljesítve', 'Lemondva'];
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
            log_admin_activity((int) $adminUser['id'], 'order_status_update', 'order', $id, ['status' => $status]);
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
