<?php
declare(strict_types=1);

/*
 * Nyilvános értékelés végpont.
 *  GET  ?action=public            → jóváhagyott, publikus értékelések
 *  GET  ?action=request&token=... → értékelő link ellenőrzése
 *  POST {action: submit, token, rating, feedback, public_consent} (CSRF védett)
 */

require_once __DIR__ . '/platform-lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$action = clean_string((string) ($_GET['action'] ?? ($payload['action'] ?? 'public')), 20);

try {
    if ($method === 'GET' && $action === 'public') {
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        send_json(['ok' => true] + tb_reviews_public($limit, $offset));
    }

    if ($method === 'GET' && $action === 'request') {
        enforce_rate_limit('review_token_check', 30, 600);
        $request = tb_review_request_by_token(clean_string((string) ($_GET['token'] ?? ''), 64));
        if (!$request) {
            send_json(['ok' => false, 'error' => 'Az értékelő link érvénytelen, lejárt vagy már felhasználták.'], 404);
        }
        $labels = ['project' => 'projekt', 'work_order' => 'munkavégzés', 'service_ticket' => 'szerviz'];
        $first = preg_split('/\s+/u', trim((string) $request['recipient_name'])) ?: [''];
        send_json(['ok' => true, 'request' => ['source_label' => $labels[$request['source_type']] ?? 'munka', 'recipient_first_name' => $first[0], 'expires_at' => $request['expires_at']], 'csrf_token' => csrf_token()]);
    }

    if ($method === 'POST' && $action === 'submit') {
        validate_csrf_token();
        enforce_rate_limit('review_token_submit', 10, 3600);
        $reviewId = tb_review_submit_by_token(
            clean_string((string) ($payload['token'] ?? ''), 64),
            (int) ($payload['rating'] ?? 0),
            clean_string((string) ($payload['feedback'] ?? ''), 4000),
            !empty($payload['public_consent'])
        );
        send_json(['ok' => true, 'id' => $reviewId, 'message' => 'Köszönjük az értékelést!'], 201);
    }
} catch (Throwable $e) {
    $status = tb_exception_http_status($e);
    if ($status === 500) {
        $errorId = app_log_error('review_api', $e);
        send_json(['ok' => false, 'error' => 'Szerverhiba történt. Hibaazonosító: ' . $errorId], 500);
    }
    send_json(['ok' => false, 'error' => $e->getMessage()], $status);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
