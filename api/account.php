<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$user = require_login();

if ($method === 'GET') {
    $userId = (int) $user['id'];

    $ordersStmt = db()->prepare('SELECT id, total, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $ordersStmt->execute([$userId]);
    $orders = $ordersStmt->fetchAll();

    $estimatesStmt = db()->prepare('SELECT id, area, wiring_type, alarm_qty, camera_qty, intercom, total, created_at FROM saved_estimates WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $estimatesStmt->execute([$userId]);
    $estimates = $estimatesStmt->fetchAll();

    $supportStmt = db()->prepare('SELECT id, status, last_message_at, customer_unread_count FROM support_chats WHERE user_email = ? AND deleted_at IS NULL ORDER BY last_message_at DESC LIMIT 50');
    $supportStmt->execute([(string) $user['email']]);
    $supportChats = $supportStmt->fetchAll();

    $wishlistStmt = db()->prepare('SELECT w.product_id, w.created_at, p.name, p.stock, p.image_url FROM wishlists w INNER JOIN products p ON p.id = w.product_id WHERE w.user_id = ? ORDER BY w.created_at DESC');
    $wishlistStmt->execute([$userId]);
    $wishlist = $wishlistStmt->fetchAll();

    $addressStmt = db()->prepare('SELECT shipping_name, shipping_phone, shipping_postal_code, shipping_city, shipping_address, billing_name, billing_tax_number, billing_postal_code, billing_city, billing_address FROM user_addresses WHERE user_id = ? LIMIT 1');
    $addressStmt->execute([$userId]);
    $addresses = $addressStmt->fetch() ?: new stdClass();

    $prefStmt = db()->prepare('SELECT order_emails, quote_emails, support_emails, marketing_emails FROM user_notification_preferences WHERE user_id = ? LIMIT 1');
    $prefStmt->execute([$userId]);
    $preferences = $prefStmt->fetch() ?: ['order_emails' => 1, 'quote_emails' => 1, 'support_emails' => 1, 'marketing_emails' => 0];

    $projects = [];
    $workOrders = [];
    $appointments = [];
    $serviceTickets = [];
    $documents = [];
    $notifications = [];
    $operationBadges = [
        'projects' => 0,
        'work_orders' => 0,
        'appointments' => 0,
        'service_tickets' => 0,
        'documents' => 0,
        'notifications_unread' => 0,
    ];

    try {
        $projectsStmt = db()->prepare('SELECT id, title, status, project_type, planned_start_date, planned_end_date, updated_at FROM projects WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50');
        $projectsStmt->execute([$userId]);
        $projects = $projectsStmt->fetchAll();
        $operationBadges['projects'] = count($projects);

        $workOrdersStmt = db()->prepare('SELECT DISTINCT id, project_id, title, status, priority, due_at, updated_at FROM work_orders WHERE user_id = ? OR assigned_to_user_id = ? ORDER BY updated_at DESC LIMIT 50');
        $workOrdersStmt->execute([$userId, $userId]);
        $workOrders = $workOrdersStmt->fetchAll();
        $operationBadges['work_orders'] = count(array_filter($workOrders, static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['todo', 'in_progress', 'blocked'], true)));

        $appointmentsStmt = db()->prepare('SELECT DISTINCT id, appointment_type, status, starts_at, ends_at, location FROM appointments WHERE user_id = ? OR assigned_to_user_id = ? ORDER BY starts_at DESC LIMIT 50');
        $appointmentsStmt->execute([$userId, $userId]);
        $appointments = $appointmentsStmt->fetchAll();
        $operationBadges['appointments'] = count(array_filter($appointments, static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['requested', 'confirmed', 'rescheduled'], true)));

        $serviceStmt = db()->prepare('SELECT id, subject, status, priority, follow_up_at, updated_at FROM service_tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50');
        $serviceStmt->execute([$userId]);
        $serviceTickets = $serviceStmt->fetchAll();
        $operationBadges['service_tickets'] = count(array_filter($serviceTickets, static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['open', 'triaged', 'scheduled', 'in_progress', 'waiting_customer'], true)));

        $documentsStmt = db()->prepare('SELECT DISTINCT pf.id, pf.project_id, pf.work_order_id, pf.category, pf.original_name, pf.storage_path, pf.mime_type, pf.file_size, pf.created_at
                                        FROM project_files pf
                                        LEFT JOIN projects p ON p.id = pf.project_id
                                        LEFT JOIN work_orders wo ON wo.id = pf.work_order_id
                                        LEFT JOIN projects pwo ON pwo.id = wo.project_id
                                        WHERE pf.user_id = ? OR p.user_id = ? OR wo.user_id = ? OR wo.assigned_to_user_id = ? OR pwo.user_id = ?
                                        ORDER BY pf.created_at DESC
                                        LIMIT 80');
        $documentsStmt->execute([$userId, $userId, $userId, $userId, $userId]);
        $documents = $documentsStmt->fetchAll();
        $operationBadges['documents'] = count($documents);

        $notificationsStmt = db()->prepare('SELECT id, title, message, link_url, is_read, created_at FROM in_app_notifications WHERE audience = ? AND (user_id IS NULL OR user_id = ?) ORDER BY created_at DESC LIMIT 50');
        $notificationsStmt->execute(['user', $userId]);
        $notifications = $notificationsStmt->fetchAll();
        $operationBadges['notifications_unread'] = count(array_filter($notifications, static fn(array $row): bool => (int) ($row['is_read'] ?? 0) === 0));
    } catch (Throwable $e) {
    }

    send_json([
        'ok' => true,
        'account' => [
            'user' => $user,
            'orders' => $orders,
            'saved_estimates' => $estimates,
            'support_chats' => $supportChats,
            'wishlist' => $wishlist,
            'addresses' => $addresses,
            'notification_preferences' => $preferences,
            'projects' => $projects,
            'work_orders' => $workOrders,
            'appointments' => $appointments,
            'service_tickets' => $serviceTickets,
            'documents' => $documents,
            'operations_notifications' => $notifications,
            'operations_badges' => $operationBadges,
        ],
    ]);
}

if ($method !== 'POST') {
    send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
}

validate_csrf_token();
enforce_rate_limit('account_write', 30, 900);
$action = clean_string((string) ($payload['action'] ?? ''), 40);
$userId = (int) $user['id'];

if ($action === 'update_addresses') {
    $shippingName = clean_string((string) ($payload['shipping_name'] ?? ''), 120);
    $shippingPhone = clean_string((string) ($payload['shipping_phone'] ?? ''), 40);
    $shippingPostalCode = clean_string((string) ($payload['shipping_postal_code'] ?? ''), 20);
    $shippingCity = clean_string((string) ($payload['shipping_city'] ?? ''), 120);
    $shippingAddress = clean_string((string) ($payload['shipping_address'] ?? ''), 255);
    $billingName = clean_string((string) ($payload['billing_name'] ?? ''), 120);
    $billingTaxNumber = clean_string((string) ($payload['billing_tax_number'] ?? ''), 60);
    $billingPostalCode = clean_string((string) ($payload['billing_postal_code'] ?? ''), 20);
    $billingCity = clean_string((string) ($payload['billing_city'] ?? ''), 120);
    $billingAddress = clean_string((string) ($payload['billing_address'] ?? ''), 255);

    if (
        $shippingName === '' || $shippingPostalCode === '' || $shippingCity === '' || $shippingAddress === ''
        || $billingName === '' || $billingPostalCode === '' || $billingCity === '' || $billingAddress === ''
    ) {
        send_json(['ok' => false, 'error' => 'A szállítási és számlázási adatok kitöltése kötelező.'], 422);
    }

    $stmt = db()->prepare(
        'INSERT INTO user_addresses (user_id, shipping_name, shipping_phone, shipping_postal_code, shipping_city, shipping_address, billing_name, billing_tax_number, billing_postal_code, billing_city, billing_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         shipping_name = VALUES(shipping_name),
         shipping_phone = VALUES(shipping_phone),
         shipping_postal_code = VALUES(shipping_postal_code),
         shipping_city = VALUES(shipping_city),
         shipping_address = VALUES(shipping_address),
         billing_name = VALUES(billing_name),
         billing_tax_number = VALUES(billing_tax_number),
         billing_postal_code = VALUES(billing_postal_code),
         billing_city = VALUES(billing_city),
         billing_address = VALUES(billing_address)'
    );
    $stmt->execute([$userId, $shippingName, $shippingPhone ?: null, $shippingPostalCode, $shippingCity, $shippingAddress, $billingName, $billingTaxNumber ?: null, $billingPostalCode, $billingCity, $billingAddress]);
    send_json(['ok' => true]);
}

if ($action === 'update_notifications') {
    $orderEmails = !empty($payload['order_emails']) ? 1 : 0;
    $quoteEmails = !empty($payload['quote_emails']) ? 1 : 0;
    $supportEmails = !empty($payload['support_emails']) ? 1 : 0;
    $marketingEmails = !empty($payload['marketing_emails']) ? 1 : 0;

    $stmt = db()->prepare(
        'INSERT INTO user_notification_preferences (user_id, order_emails, quote_emails, support_emails, marketing_emails)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         order_emails = VALUES(order_emails),
         quote_emails = VALUES(quote_emails),
         support_emails = VALUES(support_emails),
         marketing_emails = VALUES(marketing_emails)'
    );
    $stmt->execute([$userId, $orderEmails, $quoteEmails, $supportEmails, $marketingEmails]);
    send_json(['ok' => true]);
}

if ($action === 'change_password') {
    enforce_rate_limit('account_change_password', 5, 900);
    $currentPassword = (string) ($payload['current_password'] ?? '');
    $newPassword = (string) ($payload['new_password'] ?? '');
    if ($currentPassword === '' || mb_strlen($newPassword) < 8) {
        send_json(['ok' => false, 'error' => 'A jelenlegi és az új jelszó megadása kötelező.'], 422);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
        send_json(['ok' => false, 'error' => 'A jelenlegi jelszó hibás.'], 403);
    }

    $update = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    send_json(['ok' => true]);
}

if ($action === 'change_email') {
    enforce_rate_limit('account_change_email', 5, 900);
    $currentPassword = (string) ($payload['current_password'] ?? '');
    $newEmail = clean_string((string) ($payload['new_email'] ?? ''), 190);
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || $currentPassword === '') {
        send_json(['ok' => false, 'error' => 'Érvénytelen új e-mail cím vagy hiányzó jelszó.'], 422);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
        send_json(['ok' => false, 'error' => 'A jelenlegi jelszó hibás.'], 403);
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $exists->execute([$newEmail, $userId]);
    if ($exists->fetch()) {
        send_json(['ok' => false, 'error' => 'Ez az e-mail cím már foglalt.'], 409);
    }

    $oldEmail = (string) $user['email'];
    $update = db()->prepare('UPDATE users SET email = ? WHERE id = ?');
    $update->execute([$newEmail, $userId]);
    $supportUpdate = db()->prepare('UPDATE support_chats SET user_email = ? WHERE user_email = ?');
    $supportUpdate->execute([$newEmail, $oldEmail]);
    send_json(['ok' => true]);
}

if ($action === 'wishlist_add') {
    $productId = (int) ($payload['product_id'] ?? 0);
    if ($productId <= 0) {
        send_json(['ok' => false, 'error' => 'Érvénytelen termék azonosító.'], 422);
    }
    $exists = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $exists->execute([$productId]);
    if (!$exists->fetch()) {
        send_json(['ok' => false, 'error' => 'A termék nem található.'], 404);
    }
    try {
        $stmt = db()->prepare('INSERT INTO wishlists (user_id, product_id) VALUES (?, ?)');
        $stmt->execute([$userId, $productId]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            send_json(['ok' => true, 'message' => 'A termék már szerepel a kívánságlistában.']);
        }
        throw $e;
    }
    send_json(['ok' => true]);
}

if ($action === 'wishlist_remove') {
    $productId = (int) ($payload['product_id'] ?? 0);
    if ($productId <= 0) {
        send_json(['ok' => false, 'error' => 'Érvénytelen termék azonosító.'], 422);
    }
    $stmt = db()->prepare('DELETE FROM wishlists WHERE user_id = ? AND product_id = ?');
    $stmt->execute([$userId, $productId]);
    send_json(['ok' => true]);
}

if ($action === 'request_data_export' || $action === 'request_account_delete') {
    $consentVersion = clean_string((string) ($payload['consent_version'] ?? 'v1'), 40);
    $note = clean_string((string) ($payload['note'] ?? ''), 1200);
    $requestType = $action === 'request_data_export' ? 'data_export' : 'account_delete';
    $stmt = db()->prepare('INSERT INTO gdpr_requests (user_id, request_type, consent_version, note) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $requestType, $consentVersion, $note ?: null]);
    send_json(['ok' => true]);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
