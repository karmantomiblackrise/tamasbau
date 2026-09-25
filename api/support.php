<?php
declare(strict_types=1);

require_once __DIR__ . '/support-lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'POST') {
    validate_csrf_token();

    $action = clean_string((string) ($payload['action'] ?? 'create'), 40);
    $sessionUser = current_user();
    $name = clean_string((string) ($payload['name'] ?? ($sessionUser['name'] ?? '')), 120);
    $email = clean_string((string) ($payload['email'] ?? ($sessionUser['email'] ?? '')), 190);
    $message = clean_string((string) ($payload['message'] ?? ''), 5000);
    $chatId = (int) ($payload['chat_id'] ?? 0);

    if ($sessionUser) {
        $name = clean_string((string) ($sessionUser['name'] ?? $name), 120);
        $email = clean_string((string) ($sessionUser['email'] ?? $email), 190);
    }

    if ($action === 'mark_read') {
        if ($chatId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            send_json(['ok' => false, 'error' => 'Érvényes e-mail cím és support chat azonosító szükséges.'], 422);
        }

        $chat = support_mark_chat_read($chatId, 'customer', $email);
        if ($chat === null) {
            send_json(['ok' => false, 'error' => 'Support chat nem található.'], 404);
        }

        send_json(['ok' => true, 'chat' => $chat]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
        send_json(['ok' => false, 'error' => 'Érvényes e-mail cím és üzenet megadása kötelező.'], 422);
    }

    try {
        $chat = support_create_or_append_customer_message($name, $email, $message, $chatId > 0 ? $chatId : null);
    } catch (Throwable $e) {
        send_json(['ok' => false, 'error' => 'A support üzenet mentése sikertelen.'], 500);
    }

    send_json(['ok' => true, 'chat' => $chat], 201);
}

if ($method === 'GET') {
    $sessionUser = current_user();
    $email = clean_string((string) ($_GET['email'] ?? ($sessionUser['email'] ?? '')), 190);
    $chatId = (int) ($_GET['chat_id'] ?? 0);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json(['ok' => false, 'error' => 'Érvényes e-mail cím szükséges a support chat lekéréséhez.'], 422);
    }

    $chat = support_find_public_chat($email, $chatId > 0 ? $chatId : null);
    send_json(['ok' => true, 'chat' => $chat]);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
