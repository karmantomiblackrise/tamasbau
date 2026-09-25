<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/checkout-payment.php';

function order_statuses(): array
{
    return ['new', 'payment_pending', 'processing', 'packing', 'shipped', 'completed', 'cancelled', 'refunded'];
}

function final_order_statuses(): array
{
    return ['cancelled', 'refunded'];
}

function reversal_order_statuses(): array
{
    return ['cancelled', 'refunded'];
}

function order_status_label(string $status): string
{
    $status = normalize_order_status_value($status);
    $labels = [
        'new' => 'Új',
        'payment_pending' => 'Fizetésre vár',
        'processing' => 'Feldolgozás alatt',
        'packing' => 'Csomagolás alatt',
        'shipped' => 'Átadva futárnak',
        'completed' => 'Teljesítve',
        'cancelled' => 'Lemondva',
        'refunded' => 'Visszatérítve',
    ];
    return $labels[$status] ?? $status;
}

function normalize_order_status_value(string $status): string
{
    $normalized = strtolower(trim($status));
    $legacyMap = [
        'fizetésre vár' => 'payment_pending',
        'feldolgozás alatt' => 'processing',
        'teljesítve' => 'completed',
        'lemondva' => 'cancelled',
    ];
    return $legacyMap[$normalized] ?? $normalized;
}

function can_transition_order_status(string $from, string $to): bool
{
    $from = normalize_order_status_value($from);
    $to = normalize_order_status_value($to);
    if ($from === $to) {
        return true;
    }

    $map = [
        'new' => ['payment_pending', 'processing', 'cancelled'],
        'payment_pending' => ['processing', 'cancelled', 'refunded'],
        'processing' => ['packing', 'shipped', 'cancelled'],
        'packing' => ['shipped', 'cancelled'],
        'shipped' => ['completed', 'refunded'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    return in_array($to, $map[$from] ?? [], true);
}

function insert_order_status_log(PDO $pdo, int $orderId, string $fromStatus, string $toStatus, ?int $adminUserId, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO order_status_logs (order_id, from_status, to_status, changed_by_user_id, note) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$orderId, $fromStatus, $toStatus, $adminUserId, $note]);
}

function insert_stock_movement(PDO $pdo, int $productId, int $orderId, string $type, int $qty, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, order_id, movement_type, qty, note) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$productId, $orderId, $type, $qty, $note]);
}

function send_order_status_email(int $orderId, ?int $userId, string $status, ?string $trackingNumber = null, ?string $trackingUrl = null): ?string
{
    if (!$userId) {
        return null;
    }

    try {
        $prefStmt = db()->prepare('SELECT u.name, u.email, p.order_emails FROM users u LEFT JOIN user_notification_preferences p ON p.user_id = u.id WHERE u.id = ? LIMIT 1');
        $prefStmt->execute([$userId]);
        $row = $prefStmt->fetch();
        if (!$row || !filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (isset($row['order_emails']) && (int) $row['order_emails'] === 0) {
            return null;
        }

        $statusLabel = order_status_label($status);
        $statusText = htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = sprintf('Rendelés állapotváltozás #%d – %s', $orderId, $statusLabel);
        $trackingPart = '';
        if ($trackingNumber) {
            $trackingPart = '<p>Csomagszám: <strong>' . htmlspecialchars($trackingNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
        }
        if ($trackingUrl) {
            $safeUrl = htmlspecialchars($trackingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $trackingPart .= '<p>Tracking link: <a href="' . $safeUrl . '">' . $safeUrl . '</a></p>';
        }

        $html = sprintf(
            '<p>Kedves %s!</p><p>A rendelése (#%d) új állapota: <strong>%s</strong>.</p>%s',
            htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $orderId,
            $statusText,
            $trackingPart
        );

        send_app_mail((string) $row['email'], (string) ($row['name'] ?? ''), $subject, $html);
    } catch (Throwable $e) {
        return 'A státusz e-mail kiküldése sikertelen, de az állapot mentésre került.';
    }

    return null;
}

function send_order_confirmation_email(int $orderId, int $userId, int $total, string $paymentMethod, string $status): ?string
{
    try {
        $prefStmt = db()->prepare('SELECT u.name, u.email, p.order_emails FROM users u LEFT JOIN user_notification_preferences p ON p.user_id = u.id WHERE u.id = ? LIMIT 1');
        $prefStmt->execute([$userId]);
        $row = $prefStmt->fetch();
        if (!$row || !filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (isset($row['order_emails']) && (int) $row['order_emails'] === 0) {
            return null;
        }

        $statusLabel = order_status_label($status);
        $subject = sprintf('Rendelés visszaigazolás #%d', $orderId);
        $html = sprintf(
            '<p>Kedves %s!</p><p>Köszönjük rendelését. Azonosító: <strong>#%d</strong>.</p><p>Aktuális állapot: <strong>%s</strong>.</p><p>Fizetés módja: <strong>%s</strong>.</p><p>Végösszeg: <strong>%s Ft</strong>.</p>',
            htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $orderId,
            htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($paymentMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            number_format($total, 0, ',', ' ')
        );
        send_app_mail((string) $row['email'], (string) ($row['name'] ?? ''), $subject, $html);
    } catch (Throwable $e) {
        return 'A visszaigazoló e-mail küldése sikertelen, de a rendelés rögzítve lett.';
    }
    return null;
}

function normalize_tracking_url(string $value): ?string
{
    $url = clean_string($value, 1000);
    if ($url === '') {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen tracking URL.'], 422);
    }
    return $url;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $user = require_login();
    $isAdmin = user_has_role($user, ['admin', 'superadmin']);
    $orderIdFilter = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($isAdmin) {
        $sql = 'SELECT o.id, o.user_id, o.total, o.status, o.payment_status, o.payment_method, o.payment_provider,
                       o.shipping_method, o.shipping_name, o.shipping_phone, o.shipping_postal_code, o.shipping_city, o.shipping_address,
                       o.billing_name, o.billing_tax_number, o.billing_postal_code, o.billing_city, o.billing_address,
                       o.tracking_number, o.tracking_url, o.stock_reverted, o.created_at, u.name AS user_name, u.email AS user_email
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id';
        $args = [];
        if ($orderIdFilter > 0) {
            $sql .= ' WHERE o.id = ?';
            $args[] = $orderIdFilter;
        }
        $sql .= ' ORDER BY o.created_at DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
    } else {
        $sql = 'SELECT o.id, o.user_id, o.total, o.status, o.payment_status, o.payment_method, o.payment_provider,
                       o.shipping_method, o.shipping_name, o.shipping_phone, o.shipping_postal_code, o.shipping_city, o.shipping_address,
                       o.billing_name, o.billing_tax_number, o.billing_postal_code, o.billing_city, o.billing_address,
                       o.tracking_number, o.tracking_url, o.stock_reverted, o.created_at, u.name AS user_name, u.email AS user_email
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.user_id = ?';
        $args = [(int) $user['id']];
        if ($orderIdFilter > 0) {
            $sql .= ' AND o.id = ?';
            $args[] = $orderIdFilter;
        }
        $sql .= ' ORDER BY o.created_at DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
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
            $qty = (int) $item['qty'];
            $unitPrice = (int) $item['unit_price'];
            $itemsByOrder[$orderId][] = [
                'id' => (int) $item['id'],
                'product_id' => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                'name' => $item['name'] ?: 'Törölt termék',
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $qty * $unitPrice,
            ];
        }
    }

    $statusHistory = [];
    if ($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        try {
            $historyStmt = db()->prepare('SELECT id, order_id, from_status, to_status, changed_by_user_id, note, created_at
                                          FROM order_status_logs
                                          WHERE order_id IN (' . $placeholders . ')
                                          ORDER BY id DESC');
            $historyStmt->execute($orderIds);
            foreach ($historyStmt->fetchAll() as $row) {
                $id = (int) $row['order_id'];
                if (!isset($statusHistory[$id])) {
                    $statusHistory[$id] = [];
                }
                $statusHistory[$id][] = [
                    'id' => (int) $row['id'],
                    'from_status' => $row['from_status'],
                    'to_status' => $row['to_status'],
                    'changed_by_user_id' => $row['changed_by_user_id'] !== null ? (int) $row['changed_by_user_id'] : null,
                    'note' => $row['note'],
                    'created_at' => $row['created_at'],
                ];
            }
        } catch (Throwable $e) {
        }
    }

    $orders = [];
    foreach ($orderRows as $row) {
        $orderId = (int) $row['id'];
        $items = $itemsByOrder[$orderId] ?? [];
        $subtotal = array_reduce($items, static fn(int $sum, array $item): int => $sum + (int) $item['line_total'], 0);
        $orders[] = [
            'id' => $orderId,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'customer' => $row['user_name'] ?: 'Vendég vásárló',
            'email' => $row['user_email'],
            'total' => (int) $row['total'],
            'subtotal' => $subtotal,
            'status' => normalize_order_status_value((string) $row['status']),
            'payment_status' => $row['payment_status'] ?: 'accepted',
            'payment_method' => $row['payment_method'],
            'payment_provider' => $row['payment_provider'],
            'shipping_method' => $row['shipping_method'],
            'shipping' => [
                'name' => $row['shipping_name'],
                'phone' => $row['shipping_phone'],
                'postal_code' => $row['shipping_postal_code'],
                'city' => $row['shipping_city'],
                'address' => $row['shipping_address'],
            ],
            'billing' => [
                'name' => $row['billing_name'],
                'tax_number' => $row['billing_tax_number'],
                'postal_code' => $row['billing_postal_code'],
                'city' => $row['billing_city'],
                'address' => $row['billing_address'],
            ],
            'tracking_number' => $row['tracking_number'],
            'tracking_url' => $row['tracking_url'],
            'stock_reverted' => (int) ($row['stock_reverted'] ?? 0) === 1,
            'date' => substr((string) $row['created_at'], 0, 10),
            'created_at' => $row['created_at'],
            'items' => $items,
            'status_history' => $statusHistory[$orderId] ?? [],
        ];
    }

    if ($orderIdFilter > 0) {
        if (!$orders) {
            send_json(['ok' => false, 'error' => 'Rendelés nem található.'], 404);
        }
        send_json(['ok' => true, 'order' => $orders[0], 'allowed_statuses' => order_statuses()]);
    }

    send_json(['ok' => true, 'orders' => $orders, 'allowed_statuses' => order_statuses()]);
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

        $allowedShippingMethods = ['standard', 'express', 'pickup'];
        if (!$items) {
            send_json(['ok' => false, 'error' => 'A kosár üres.'], 422);
        }
        if (!in_array($shippingMethod, $allowedShippingMethods, true)) {
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

        $initialStatus = $paymentMeta['status'] === 'payment_pending' ? 'payment_pending' : 'new';
        $paymentStatus = $paymentMeta['status'] === 'payment_pending' ? 'pending' : 'accepted';

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $total = 0;
            $validatedItems = [];
            $prodStmt = $pdo->prepare('SELECT id, price, stock FROM products WHERE id = ? LIMIT 1 FOR UPDATE');

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
                    shipping_method, payment_method, payment_provider, payment_status, status, stock_reverted
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $orderStmt->execute([
                (int) $user['id'],
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
                $paymentStatus,
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
                insert_stock_movement($pdo, (int) $row['product_id'], $orderId, 'reserve', (int) $row['qty'], 'Checkout reserve');
            }

            if ($initialStatus !== 'new') {
                insert_order_status_log($pdo, $orderId, 'new', $initialStatus, (int) $user['id'], 'Checkout create');
            }
            $pdo->commit();

            $mailWarning = send_order_confirmation_email($orderId, (int) $user['id'], $total, (string) $paymentMeta['method'], $initialStatus);
            send_json([
                'ok' => true,
                'order_id' => $orderId,
                'payment' => $paymentMeta,
                'shipping_method' => $shippingMethod,
                'status' => $initialStatus,
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
        $adminUser = require_login();
        if (!user_has_role($adminUser, ['admin', 'superadmin'])) {
            send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a művelethez.'], 403);
        }
        enforce_rate_limit('order_status_update', 60, 900);

        $id = (int) ($payload['id'] ?? 0);
        $status = normalize_order_status_value(clean_string((string) ($payload['status'] ?? ''), 60));
        $note = clean_string((string) ($payload['note'] ?? ''), 500);
        $trackingNumberProvided = array_key_exists('tracking_number', $payload);
        $trackingUrlProvided = array_key_exists('tracking_url', $payload);
        $trackingNumber = $trackingNumberProvided ? clean_string((string) ($payload['tracking_number'] ?? ''), 120) : null;
        $trackingUrl = $trackingUrlProvided ? normalize_tracking_url((string) ($payload['tracking_url'] ?? '')) : null;

        if ($id <= 0 || !in_array($status, order_statuses(), true)) {
            send_json(['ok' => false, 'error' => 'Hiányzó rendelés adatok.'], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $currentStmt = $pdo->prepare('SELECT id, user_id, status, stock_reverted, tracking_number, tracking_url FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                throw new RuntimeException('Rendelés nem található.');
            }

            $fromStatus = normalize_order_status_value((string) $current['status']);
            if (!can_transition_order_status($fromStatus, $status)) {
                throw new RuntimeException('Nem engedélyezett státuszátmenet.');
            }

            $stockReverted = (int) ($current['stock_reverted'] ?? 0) === 1;
            $targetIsFinal = in_array($status, final_order_statuses(), true);
            $sourceIsFinal = in_array($fromStatus, final_order_statuses(), true);
            $targetIsReversal = in_array($status, reversal_order_statuses(), true);

            if ($targetIsReversal && !$stockReverted) {
                $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL');
                $itemsStmt->execute([$id]);
                $restockStmt = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                foreach ($itemsStmt->fetchAll() as $item) {
                    $qty = (int) $item['qty'];
                    $productId = (int) $item['product_id'];
                    $restockStmt->execute([$qty, $productId]);
                    insert_stock_movement($pdo, $productId, $id, 'release', $qty, 'Order final state restock');
                }
                $stockReverted = true;
            }

            if (!$targetIsFinal && $sourceIsFinal && $stockReverted) {
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
                    insert_stock_movement($pdo, $productId, $id, 'reserve', $qty, 'Order reopened reserve');
                }
                $stockReverted = false;
            }

            $nextTrackingNumber = $current['tracking_number'] ?? null;
            if ($trackingNumberProvided) {
                $nextTrackingNumber = ($trackingNumber ?? '') !== '' ? $trackingNumber : null;
            }
            $nextTrackingUrl = $current['tracking_url'] ?? null;
            if ($trackingUrlProvided) {
                $nextTrackingUrl = $trackingUrl;
            }

            $paymentStatus = null;
            if ($status === 'refunded') {
                $paymentStatus = 'refunded';
            } elseif ($status === 'completed') {
                $paymentStatus = 'paid';
            } elseif ($status === 'payment_pending') {
                $paymentStatus = 'pending';
            }

            $updateStmt = $pdo->prepare('UPDATE orders
                                        SET status = ?,
                                            payment_status = COALESCE(?, payment_status),
                                            tracking_number = ?,
                                            tracking_url = ?,
                                            stock_reverted = ?
                                        WHERE id = ?');
            $updateStmt->execute([
                $status,
                $paymentStatus,
                $nextTrackingNumber,
                $nextTrackingUrl,
                $stockReverted ? 1 : 0,
                $id,
            ]);

            insert_order_status_log($pdo, $id, $fromStatus, $status, (int) $adminUser['id'], $note ?: null);
            $pdo->commit();

            log_admin_activity((int) $adminUser['id'], 'order_status_update', 'order', $id, [
                'from' => $fromStatus,
                'to' => $status,
                'tracking_number' => $nextTrackingNumber,
                'tracking_url' => $nextTrackingUrl,
            ]);

            $mailWarning = send_order_status_email($id, (int) $current['user_id'], $status, $nextTrackingNumber ?: null, $nextTrackingUrl ?: null);
            send_json(['ok' => true, 'warning' => $mailWarning]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'Rendelés nem található.') {
                send_json(['ok' => false, 'error' => $e->getMessage()], 404);
            }
            if ($e instanceof RuntimeException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            send_json(['ok' => false, 'error' => 'A rendelés állapota nem frissíthető.'], 422);
        }
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
