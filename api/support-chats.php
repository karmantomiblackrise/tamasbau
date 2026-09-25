<?php
declare(strict_types=1);

require_once __DIR__ . '/support-lib.php';

$admin = require_admin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $chatId = (int) ($_GET['id'] ?? 0);
    $scope = support_normalize_scope(clean_string((string) ($_GET['scope'] ?? 'active'), 20));
    if ($chatId > 0) {
        $chat = support_chat_detail_payload($chatId);
        if ($chat === null) {
            send_json(['ok' => false, 'error' => 'Support chat nem található.'], 404);
        }
        send_json(['ok' => true, 'chat' => $chat]);
    }

    send_json([
        'ok' => true,
        'scope' => $scope,
        'summary' => support_list_summary(),
        'chats' => support_list_chats($scope),
    ]);
}

if ($method === 'POST') {
    $action = clean_string((string) ($payload['action'] ?? ''));
    $chatId = (int) ($payload['id'] ?? 0);

    if ($chatId <= 0) {
        send_json(['ok' => false, 'error' => 'Érvénytelen support chat.'], 422);
    }

    if ($action === 'reply') {
        $message = clean_string((string) ($payload['message'] ?? ''), 5000);
        $status = clean_string((string) ($payload['status'] ?? ''), 30);

        if ($message === '') {
            send_json(['ok' => false, 'error' => 'Az admin válasz nem lehet üres.'], 422);
        }
        if ($status !== '' && !is_valid_support_status($status)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen support chat státusz.'], 422);
        }

        try {
            $chat = support_create_admin_reply($chatId, $admin, $message, $status !== '' ? $status : null);
        } catch (OutOfBoundsException $e) {
            send_json(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (DomainException $e) {
            send_json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            send_json(['ok' => false, 'error' => 'Az admin válasz mentése sikertelen.'], 500);
        }

        send_json(['ok' => true, 'chat' => $chat]);
    }

    if ($action === 'mark_read') {
        $chat = support_mark_chat_read($chatId);
        if ($chat === null) {
            send_json(['ok' => false, 'error' => 'Support chat nem található.'], 404);
        }
        send_json(['ok' => true, 'chat' => $chat]);
    }

    if ($action === 'update_status') {
        $status = clean_string((string) ($payload['status'] ?? ''), 30);
        if (!is_valid_support_status($status)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen support chat státusz.'], 422);
        }

        try {
            $chat = support_update_chat_status($chatId, $status);
        } catch (DomainException $e) {
            send_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if ($chat === null) {
            send_json(['ok' => false, 'error' => 'Support chat nem található.'], 404);
        }
        send_json(['ok' => true, 'chat' => $chat]);
    }

    if ($action === 'archive' || $action === 'delete' || $action === 'restore') {
        $archived = $action === 'restore'
            ? false
            : filter_var($payload['archived'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $archived = $archived !== false;

        try {
            $chat = support_archive_chat($chatId, $archived);
        } catch (DomainException $e) {
            send_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if ($chat === null) {
            send_json(['ok' => false, 'error' => 'Support chat nem található.'], 404);
        }

        send_json(['ok' => true, 'chat' => $chat]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
