<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function support_statuses(): array
{
    return ['new', 'open', 'resolved'];
}

function is_valid_support_status(string $status): bool
{
    return in_array($status, support_statuses(), true);
}

function support_chat_summary_payload(array $chat): array
{
    return [
        'id' => (int) $chat['id'],
        'user_name' => $chat['user_name'] ?? '',
        'user_email' => $chat['user_email'] ?? '',
        'status' => $chat['status'] ?? 'new',
        'created_at' => $chat['created_at'] ?? null,
        'updated_at' => $chat['updated_at'] ?? null,
        'last_message_at' => $chat['last_message_at'] ?? null,
        'unread_count' => (int) ($chat['unread_count'] ?? 0),
        'last_message' => $chat['last_message'] ?? null,
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

function support_chat_detail_payload(int $chatId): ?array
{
    $chatStmt = db()->prepare(
        "SELECT sc.id, sc.user_name, sc.user_email, sc.status, sc.created_at, sc.updated_at, sc.last_message_at, sc.unread_count,
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

function support_find_public_chat(string $email, ?int $chatId = null): ?array
{
    if ($chatId !== null && $chatId > 0) {
        $stmt = db()->prepare('SELECT id FROM support_chats WHERE id = ? AND user_email = ? LIMIT 1');
        $stmt->execute([$chatId, $email]);
        $row = $stmt->fetch();
        if ($row) {
            return support_chat_detail_payload((int) $row['id']);
        }
    }

    $stmt = db()->prepare('SELECT id FROM support_chats WHERE user_email = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
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
        $stmt = $pdo->prepare('SELECT id, user_name, user_email, status, unread_count FROM support_chats WHERE id = ? AND user_email = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$preferredChatId, $email]);
        $chat = $stmt->fetch();
        if ($chat) {
            return $chat;
        }
    }

    $stmt = $pdo->prepare('SELECT id, user_name, user_email, status, unread_count FROM support_chats WHERE user_email = ? ORDER BY updated_at DESC, id DESC LIMIT 1 FOR UPDATE');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

function support_create_or_append_customer_message(string $name, string $email, string $message, ?int $preferredChatId = null): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $existingChat = support_find_customer_chat_for_update($pdo, $email, $preferredChatId);
        if ($existingChat) {
            $chatId = (int) $existingChat['id'];
            $nextStatus = ($existingChat['status'] ?? 'new') === 'resolved' ? 'open' : (string) ($existingChat['status'] ?? 'new');
            $nextName = $name !== '' ? $name : (string) ($existingChat['user_name'] ?? '');

            $updateStmt = $pdo->prepare(
                'UPDATE support_chats
                 SET user_name = ?, status = ?, last_message_at = NOW(), updated_at = NOW(), unread_count = unread_count + 1
                 WHERE id = ?'
            );
            $updateStmt->execute([$nextName !== '' ? $nextName : null, $nextStatus, $chatId]);
        } else {
            $insertChatStmt = $pdo->prepare(
                'INSERT INTO support_chats (user_name, user_email, status, last_message_at, unread_count)
                 VALUES (?, ?, ?, NOW(), 1)'
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

    return $chat;
}

function support_list_chats(): array
{
    $stmt = db()->prepare(
        "SELECT sc.id, sc.user_name, sc.user_email, sc.status, sc.created_at, sc.updated_at, sc.last_message_at, sc.unread_count,
                (
                    SELECT sm.message
                    FROM support_messages sm
                    WHERE sm.chat_id = sc.id
                    ORDER BY sm.created_at DESC, sm.id DESC
                    LIMIT 1
                ) AS last_message
         FROM support_chats sc
         ORDER BY FIELD(sc.status, 'new', 'open', 'resolved'), sc.unread_count DESC, sc.last_message_at DESC, sc.id DESC"
    );
    $stmt->execute();
    return array_map('support_chat_summary_payload', $stmt->fetchAll());
}

function support_mark_chat_read(int $chatId): ?array
{
    $stmt = db()->prepare('UPDATE support_chats SET unread_count = 0, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$chatId]);
    return support_chat_detail_payload($chatId);
}

function support_update_chat_status(int $chatId, string $status): ?array
{
    $stmt = db()->prepare('UPDATE support_chats SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $chatId]);
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

        $nextStatus = $requestedStatus !== null && $requestedStatus !== ''
            ? $requestedStatus
            : (($chat['status'] ?? 'new') === 'resolved' ? 'resolved' : 'open');

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
             SET status = ?, unread_count = 0, last_message_at = NOW(), updated_at = NOW()
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

    return $detail;
}
