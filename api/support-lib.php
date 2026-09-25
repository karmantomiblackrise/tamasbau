<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function support_statuses(): array
{
    return ['new', 'open', 'resolved', 'closed'];
}

function support_status_labels(): array
{
    return [
        'new' => 'Új',
        'open' => 'Nyitott',
        'resolved' => 'Megoldott',
        'closed' => 'Lezárt',
    ];
}

function support_status_label(string $status): string
{
    return support_status_labels()[$status] ?? $status;
}

function support_status_transitions(): array
{
    return [
        'new' => ['new', 'open', 'resolved', 'closed'],
        'open' => ['open', 'resolved', 'closed'],
        'resolved' => ['resolved', 'open', 'closed'],
        'closed' => ['closed', 'open'],
    ];
}

function is_valid_support_status(string $status): bool
{
    return in_array($status, support_statuses(), true);
}

function is_valid_support_status_transition(string $currentStatus, string $nextStatus): bool
{
    if (!is_valid_support_status($currentStatus) || !is_valid_support_status($nextStatus)) {
        return false;
    }

    $allowed = support_status_transitions()[$currentStatus] ?? [];
    return in_array($nextStatus, $allowed, true);
}

function support_normalize_scope(string $scope): string
{
    return in_array($scope, ['active', 'archived', 'all'], true) ? $scope : 'active';
}

function support_list_where_clause(string $scope): string
{
    return match (support_normalize_scope($scope)) {
        'archived' => 'sc.deleted_at IS NOT NULL',
        'all' => '1=1',
        default => 'sc.deleted_at IS NULL',
    };
}

function support_chat_summary_payload(array $chat): array
{
    $adminUnreadCount = (int) ($chat['admin_unread_count'] ?? $chat['unread_count'] ?? 0);
    $customerUnreadCount = (int) ($chat['customer_unread_count'] ?? 0);
    $deletedAt = $chat['deleted_at'] ?? null;

    return [
        'id' => (int) $chat['id'],
        'user_name' => $chat['user_name'] ?? '',
        'user_email' => $chat['user_email'] ?? '',
        'status' => $chat['status'] ?? 'new',
        'created_at' => $chat['created_at'] ?? null,
        'updated_at' => $chat['updated_at'] ?? null,
        'last_message_at' => $chat['last_message_at'] ?? null,
        'last_customer_message_at' => $chat['last_customer_message_at'] ?? null,
        'last_admin_message_at' => $chat['last_admin_message_at'] ?? null,
        'admin_unread_count' => $adminUnreadCount,
        'customer_unread_count' => $customerUnreadCount,
        'unread_count' => $adminUnreadCount,
        'last_message' => $chat['last_message'] ?? null,
        'deleted_at' => $deletedAt,
        'is_archived' => $deletedAt !== null,
        'can_archive' => in_array((string) ($chat['status'] ?? 'new'), ['resolved', 'closed'], true),
    ];
}

function support_message_payload(array $message): array
{
    return [
        'id' => (int) $message['id'],
        'chat_id' => (int) $message['chat_id'],
        'sender_type' => $message['sender_type'] ?? 'customer',
        'sender_name' => $message['sender_name'] ?? '',
        'sender_email' => $message['sender_email'] ?? '',
        'message' => $message['message'] ?? '',
        'created_at' => $message['created_at'] ?? null,
    ];
}

function support_fetch_chat_row(int $chatId): ?array
{
    $chatStmt = db()->prepare(
        "SELECT sc.id, sc.user_name, sc.user_email, sc.status, sc.created_at, sc.updated_at, sc.last_message_at,
                sc.last_customer_message_at, sc.last_admin_message_at, sc.admin_unread_count, sc.customer_unread_count,
                sc.unread_count, sc.deleted_at,
                (
                    SELECT sm.message
                    FROM support_messages sm
                    WHERE sm.chat_id = sc.id
                    ORDER BY sm.created_at DESC, sm.id DESC
                    LIMIT 1
                ) AS last_message
         FROM support_chats sc
         WHERE sc.id = ?
         LIMIT 1"
    );
    $chatStmt->execute([$chatId]);
    $chat = $chatStmt->fetch();

    return $chat ?: null;
}

function support_chat_detail_payload(int $chatId): ?array
{
    $chat = support_fetch_chat_row($chatId);
    if (!$chat) {
        return null;
    }

    $messageStmt = db()->prepare(
        'SELECT id, chat_id, sender_type, sender_name, sender_email, message, created_at
         FROM support_messages
         WHERE chat_id = ?
         ORDER BY created_at ASC, id ASC'
    );
    $messageStmt->execute([$chatId]);
    $messages = array_map('support_message_payload', $messageStmt->fetchAll());

    return support_chat_summary_payload($chat) + ['messages' => $messages];
}

function support_public_chat_detail(int $chatId, string $email): ?array
{
    $stmt = db()->prepare('SELECT id FROM support_chats WHERE id = ? AND user_email = ? LIMIT 1');
    $stmt->execute([$chatId, $email]);
    $row = $stmt->fetch();

    return $row ? support_chat_detail_payload((int) $row['id']) : null;
}

function support_find_public_chat(string $email, ?int $chatId = null): ?array
{
    if ($chatId !== null && $chatId > 0) {
        $chat = support_public_chat_detail($chatId, $email);
        if ($chat !== null) {
            return $chat;
        }
    }

    $stmt = db()->prepare(
        'SELECT id
         FROM support_chats
         WHERE user_email = ? AND deleted_at IS NULL
         ORDER BY updated_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return support_chat_detail_payload((int) $row['id']);
}

function support_find_customer_chat_for_update(PDO $pdo, string $email, ?int $preferredChatId = null): ?array
{
    if ($preferredChatId !== null && $preferredChatId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, user_name, user_email, status, admin_unread_count, customer_unread_count, unread_count, deleted_at
             FROM support_chats
             WHERE id = ? AND user_email = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$preferredChatId, $email]);
        $chat = $stmt->fetch();
        if ($chat) {
            return $chat;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, user_name, user_email, status, admin_unread_count, customer_unread_count, unread_count, deleted_at
         FROM support_chats
         WHERE user_email = ? AND deleted_at IS NULL
         ORDER BY updated_at DESC, id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([$email]);

    return $stmt->fetch() ?: null;
}

function support_preview_message(string $message, int $maxLength = 180): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($normalized, 'UTF-8') <= $maxLength) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $maxLength - 1, 'UTF-8')) . '…';
    }

    if (strlen($normalized) <= $maxLength) {
        return $normalized;
    }

    return rtrim(substr($normalized, 0, $maxLength - 1)) . '...';
}

function support_safe_send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'reason' => 'invalid_email'];
    }

    if (!smtp_mailer_configured() && !app_is_development()) {
        error_log('Support e-mail értesítés kihagyva: SMTP nincs konfigurálva.');
        return ['sent' => false, 'reason' => 'smtp_not_configured'];
    }

    try {
        $result = send_app_mail($toEmail, $toName, $subject, $htmlBody);
        return ['sent' => true, 'mode' => $result['mode'] ?? 'unknown'];
    } catch (Throwable $e) {
        error_log('Support e-mail értesítés sikertelen: ' . $e->getMessage());
        return ['sent' => false, 'reason' => 'send_failed'];
    }
}

function support_notify_admins_about_customer_message(array $chat, string $message): void
{
    $stmt = db()->query(
        "SELECT name, email
         FROM users
         WHERE role = 'admin' AND is_active = 1 AND email <> ''"
    );
    $admins = $stmt->fetchAll();
    if (!$admins) {
        return;
    }

    $subject = sprintf('Új support ügyfélüzenet (#%d)', (int) $chat['id']);
    $customerName = htmlspecialchars((string) ($chat['user_name'] ?: 'Ügyfél'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $customerEmail = htmlspecialchars((string) ($chat['user_email'] ?: ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messagePreview = format_html_email(support_preview_message($message));
    $chatUrl = htmlspecialchars(app_config()['app_url'] . '/#admin', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = <<<HTML
<p>Új ügyfélüzenet érkezett a support chatbe.</p>
<p><strong>Beszélgetés:</strong> #{$chat['id']}<br><strong>Ügyfél:</strong> {$customerName}<br><strong>E-mail:</strong> {$customerEmail}</p>
<p><strong>Üzenet:</strong><br>{$messagePreview}</p>
<p>Admin felület: <a href="{$chatUrl}">{$chatUrl}</a></p>
HTML;

    foreach ($admins as $admin) {
        support_safe_send_mail(
            (string) ($admin['email'] ?? ''),
            (string) ($admin['name'] ?? 'Adminisztrátor'),
            $subject,
            $body
        );
    }
}

function support_notify_customer_about_admin_reply(array $chat, string $message): void
{
    $email = (string) ($chat['user_email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $customerName = (string) ($chat['user_name'] ?? 'Ügyfél');
    $escapedCustomerName = htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject = sprintf('Új admin válasz érkezett a support chatben (#%d)', (int) $chat['id']);
    $statusLabel = htmlspecialchars(support_status_label((string) ($chat['status'] ?? 'open')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messagePreview = format_html_email(support_preview_message($message));
    $chatUrl = htmlspecialchars(app_config()['app_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = <<<HTML
<p>Kedves {$escapedCustomerName}!</p>
<p>Új admin válasz érkezett a support beszélgetéséhez.</p>
<p><strong>Beszélgetés:</strong> #{$chat['id']}<br><strong>Állapot:</strong> {$statusLabel}</p>
<p><strong>Válasz:</strong><br>{$messagePreview}</p>
<p>A beszélgetést itt tudja megnyitni: <a href="{$chatUrl}">{$chatUrl}</a></p>
HTML;

    support_safe_send_mail($email, $customerName, $subject, $body);
}

function support_create_or_append_customer_message(string $name, string $email, string $message, ?int $preferredChatId = null): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $existingChat = support_find_customer_chat_for_update($pdo, $email, $preferredChatId);
        if ($existingChat) {
            $chatId = (int) $existingChat['id'];
            $currentStatus = (string) ($existingChat['status'] ?? 'new');
            $nextStatus = in_array($currentStatus, ['resolved', 'closed'], true) ? 'open' : $currentStatus;
            $nextName = $name !== '' ? $name : (string) ($existingChat['user_name'] ?? '');

            $updateStmt = $pdo->prepare(
                'UPDATE support_chats
                 SET user_name = ?, status = ?, deleted_at = NULL, last_message_at = NOW(), last_customer_message_at = NOW(),
                     updated_at = NOW(), admin_unread_count = admin_unread_count + 1, unread_count = unread_count + 1
                 WHERE id = ?'
            );
            $updateStmt->execute([$nextName !== '' ? $nextName : null, $nextStatus, $chatId]);
        } else {
            $insertChatStmt = $pdo->prepare(
                'INSERT INTO support_chats (
                    user_name, user_email, status, last_message_at, last_customer_message_at,
                    admin_unread_count, customer_unread_count, unread_count, deleted_at
                 ) VALUES (?, ?, ?, NOW(), NOW(), 1, 0, 1, NULL)'
            );
            $insertChatStmt->execute([$name !== '' ? $name : null, $email, 'new']);
            $chatId = (int) $pdo->lastInsertId();
        }

        $insertMessageStmt = $pdo->prepare(
            'INSERT INTO support_messages (chat_id, sender_type, sender_name, sender_email, message)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertMessageStmt->execute([$chatId, 'customer', $name !== '' ? $name : null, $email, $message]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $chat = support_chat_detail_payload($chatId);
    if ($chat === null) {
        throw new RuntimeException('A support chat mentése sikerült, de a beszélgetés nem tölthető vissza.');
    }

    support_notify_admins_about_customer_message($chat, $message);

    return $chat;
}

function support_list_summary(): array
{
    $stmt = db()->query(
        "SELECT
            COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN admin_unread_count ELSE 0 END), 0) AS admin_unread_total,
            COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN customer_unread_count ELSE 0 END), 0) AS customer_unread_total,
            COALESCE(SUM(CASE WHEN deleted_at IS NULL AND status IN ('new', 'open') THEN 1 ELSE 0 END), 0) AS open_count,
            COALESCE(SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS archived_count
         FROM support_chats"
    );
    $summary = $stmt->fetch() ?: [];

    return [
        'admin_unread_total' => (int) ($summary['admin_unread_total'] ?? 0),
        'customer_unread_total' => (int) ($summary['customer_unread_total'] ?? 0),
        'open_count' => (int) ($summary['open_count'] ?? 0),
        'archived_count' => (int) ($summary['archived_count'] ?? 0),
    ];
}

function support_list_chats(string $scope = 'active'): array
{
    $stmt = db()->prepare(
        "SELECT sc.id, sc.user_name, sc.user_email, sc.status, sc.created_at, sc.updated_at, sc.last_message_at,
                sc.last_customer_message_at, sc.last_admin_message_at, sc.admin_unread_count, sc.customer_unread_count,
                sc.unread_count, sc.deleted_at,
                (
                    SELECT sm.message
                    FROM support_messages sm
                    WHERE sm.chat_id = sc.id
                    ORDER BY sm.created_at DESC, sm.id DESC
                    LIMIT 1
                ) AS last_message
         FROM support_chats sc
         WHERE " . support_list_where_clause($scope) . "
         ORDER BY FIELD(sc.status, 'new', 'open', 'resolved', 'closed'),
                  sc.admin_unread_count DESC,
                  sc.last_message_at DESC,
                  sc.id DESC"
    );
    $stmt->execute();

    return array_map('support_chat_summary_payload', $stmt->fetchAll());
}

function support_mark_chat_read(int $chatId, string $audience = 'admin', ?string $email = null): ?array
{
    if ($audience === 'customer') {
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $chat = support_public_chat_detail($chatId, $email);
        if ($chat === null) {
            return null;
        }

        $stmt = db()->prepare('UPDATE support_chats SET customer_unread_count = 0 WHERE id = ? AND user_email = ?');
        $stmt->execute([$chatId, $email]);

        return support_chat_detail_payload($chatId);
    }

    $stmt = db()->prepare('UPDATE support_chats SET admin_unread_count = 0, unread_count = 0 WHERE id = ?');
    $stmt->execute([$chatId]);

    return support_chat_detail_payload($chatId);
}

function support_update_chat_status(int $chatId, string $status): ?array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT id, status FROM support_chats WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();
        if (!$chat) {
            $pdo->commit();
            return null;
        }

        $currentStatus = (string) ($chat['status'] ?? 'new');
        if (!is_valid_support_status_transition($currentStatus, $status)) {
            throw new DomainException(sprintf('A(z) %s állapotból nem váltható %s státuszra.', support_status_label($currentStatus), support_status_label($status)));
        }

        $updateStmt = $pdo->prepare('UPDATE support_chats SET status = ?, updated_at = NOW() WHERE id = ?');
        $updateStmt->execute([$status, $chatId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return support_chat_detail_payload($chatId);
}

function support_archive_chat(int $chatId, bool $archived = true): ?array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT id, status FROM support_chats WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();
        if (!$chat) {
            $pdo->commit();
            return null;
        }

        $status = (string) ($chat['status'] ?? 'new');
        if ($archived && !in_array($status, ['resolved', 'closed'], true)) {
            throw new DomainException('Csak megoldott vagy lezárt support chat archiválható.');
        }

        $updateStmt = $pdo->prepare('UPDATE support_chats SET deleted_at = ?, updated_at = NOW() WHERE id = ?');
        $updateStmt->execute([$archived ? date('Y-m-d H:i:s') : null, $chatId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return support_chat_detail_payload($chatId);
}

function support_create_admin_reply(int $chatId, array $adminUser, string $message, ?string $requestedStatus = null): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $chatStmt = $pdo->prepare('SELECT id, status FROM support_chats WHERE id = ? LIMIT 1 FOR UPDATE');
        $chatStmt->execute([$chatId]);
        $chat = $chatStmt->fetch();
        if (!$chat) {
            throw new OutOfBoundsException('Support chat nem található.');
        }

        $currentStatus = (string) ($chat['status'] ?? 'new');
        $nextStatus = $requestedStatus !== null && $requestedStatus !== ''
            ? $requestedStatus
            : 'open';

        if (!is_valid_support_status_transition($currentStatus, $nextStatus)) {
            throw new DomainException(sprintf('A(z) %s állapotból nem váltható %s státuszra.', support_status_label($currentStatus), support_status_label($nextStatus)));
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO support_messages (chat_id, sender_type, sender_name, sender_email, message)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            $chatId,
            'admin',
            clean_string((string) ($adminUser['name'] ?? 'Adminisztrátor'), 120),
            clean_string((string) ($adminUser['email'] ?? ''), 190),
            $message,
        ]);

        $updateStmt = $pdo->prepare(
            'UPDATE support_chats
             SET status = ?, deleted_at = NULL, admin_unread_count = 0, unread_count = 0,
                 customer_unread_count = customer_unread_count + 1, last_message_at = NOW(),
                 last_admin_message_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $updateStmt->execute([$nextStatus, $chatId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $detail = support_chat_detail_payload($chatId);
    if ($detail === null) {
        throw new RuntimeException('A support chat válasz mentése sikerült, de a beszélgetés nem tölthető vissza.');
    }

    support_notify_customer_about_admin_reply($detail, $message);

    return $detail;
}
