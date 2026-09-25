<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function sync_quote_to_lead(int $quoteId, string $name, string $email, string $phone, string $message): void
{
    try {
        $findLead = db()->prepare('SELECT id FROM leads WHERE email = ? AND (phone = ? OR phone IS NULL OR phone = \'\') AND status <> \'archived\' ORDER BY created_at DESC LIMIT 1');
        $findLead->execute([$email, $phone]);
        $lead = $findLead->fetch();
        if ($lead) {
            $leadId = (int) $lead['id'];
            $update = db()->prepare('UPDATE leads SET quote_id = COALESCE(quote_id, ?), name = ?, phone = COALESCE(NULLIF(?, \'\'), phone), note = COALESCE(note, ?) WHERE id = ?');
            $update->execute([$quoteId, $name, $phone, $message, $leadId]);
            $timeline = db()->prepare('INSERT INTO lead_timeline (lead_id, actor_user_id, event_type, event_note, metadata_json) VALUES (?, NULL, ?, ?, ?)');
            $timeline->execute([$leadId, 'quote_request_linked', 'Ajánlatkérés kapcsolva a leadhez.', json_encode(['quote_id' => $quoteId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            return;
        }

        $insert = db()->prepare('INSERT INTO leads (quote_id, name, email, phone, source, note, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$quoteId, $name, $email, $phone !== '' ? $phone : null, 'quote_request', $message !== '' ? $message : null, 'new']);
        $leadId = (int) db()->lastInsertId();
        $timeline = db()->prepare('INSERT INTO lead_timeline (lead_id, actor_user_id, event_type, event_note, metadata_json) VALUES (?, NULL, ?, ?, ?)');
        $timeline->execute([$leadId, 'created', 'Automatikus lead létrehozás ajánlatkérésből.', json_encode(['quote_id' => $quoteId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        error_log('Quote->Lead sync failed for quote #' . $quoteId . ': ' . $e->getMessage());
    }
}

function quote_list_item_payload(array $quote): array
{
    return [
        'id' => (int) $quote['id'],
        'name' => $quote['name'],
        'phone' => $quote['phone'],
        'email' => $quote['email'],
        'type' => $quote['work_type'],
        'message' => $quote['message'],
        'status' => $quote['status'],
        'date' => substr((string) $quote['created_at'], 0, 10),
        'created_at' => $quote['created_at'],
        'admin_reply' => $quote['admin_reply'],
        'replied_at' => $quote['replied_at'],
        'last_reply_at' => $quote['last_reply_at'] ?? $quote['replied_at'],
        'reply_count' => (int) ($quote['reply_count'] ?? 0),
        'is_new' => $quote['status'] === 'new',
    ];
}

function quote_detail_payload(int $quoteId): ?array
{
    $stmt = db()->prepare(
        'SELECT q.id, q.name, q.phone, q.email, q.work_type, q.message, q.status, q.created_at, q.admin_reply, q.replied_at,
                COUNT(qr.id) AS reply_count,
                MAX(qr.created_at) AS last_reply_at
         FROM quotes q
         LEFT JOIN quote_replies qr ON qr.quote_id = q.id
         WHERE q.id = ?
         GROUP BY q.id, q.name, q.phone, q.email, q.work_type, q.message, q.status, q.created_at, q.admin_reply, q.replied_at
         LIMIT 1'
    );
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch();
    if (!$quote) {
        return null;
    }

    $replyStmt = db()->prepare(
        'SELECT qr.id, qr.quote_id, qr.admin_user_id, qr.reply_message, qr.created_at, u.name AS admin_name
         FROM quote_replies qr
         LEFT JOIN users u ON u.id = qr.admin_user_id
         WHERE qr.quote_id = ?
         ORDER BY qr.created_at ASC, qr.id ASC'
    );
    $replyStmt->execute([$quoteId]);
    $replies = array_map(static function (array $reply): array {
        return [
            'id' => (int) $reply['id'],
            'quote_id' => (int) $reply['quote_id'],
            'admin_user_id' => (int) $reply['admin_user_id'],
            'admin_name' => $reply['admin_name'] ?: 'Adminisztrátor',
            'reply_message' => $reply['reply_message'],
            'created_at' => $reply['created_at'],
        ];
    }, $replyStmt->fetchAll());

    return quote_list_item_payload($quote) + [
        'replies' => $replies,
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$action = clean_string($_GET['action'] ?? ($payload['action'] ?? ''));

if ($method === 'POST') {
    validate_csrf_token();
}

if ($method === 'POST' && ($action === '' || $action === 'create')) {
    $name = clean_string($payload['name'] ?? '', 120);
    $phone = clean_string($payload['phone'] ?? '', 40);
    $email = clean_string($payload['email'] ?? '', 190);
    $workType = clean_string($payload['work_type'] ?? ($payload['type'] ?? ''), 100);
    $message = clean_string($payload['message'] ?? '', 2000);

    if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $workType === '' || $message === '') {
        send_json(['ok' => false, 'error' => 'Hiányos ajánlatkérési adatok.'], 422);
    }

    $stmt = db()->prepare('INSERT INTO quotes (name, phone, email, work_type, message, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $email, $workType, $message, 'new']);
    $quoteId = (int) db()->lastInsertId();
    sync_quote_to_lead($quoteId, $name, $email, $phone, $message);
    send_json(['ok' => true, 'id' => $quoteId], 201);
}

if ($method === 'GET') {
    require_admin();
    $quoteId = (int) ($_GET['id'] ?? 0);
    if ($quoteId > 0) {
        $detail = quote_detail_payload($quoteId);
        if ($detail === null) {
            send_json(['ok' => false, 'error' => 'Ajánlatkérés nem található.'], 404);
        }
        send_json(['ok' => true, 'quote' => $detail]);
    }

    $status = clean_string($_GET['status'] ?? '', 30);
    $search = clean_string($_GET['search'] ?? '', 120);
    $where = [];
    $params = [];

    if ($status !== '' && $status !== 'all') {
        if (!is_valid_quote_status($status)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen státuszszűrő.'], 422);
        }
        $where[] = 'q.status = ?';
        $params[] = $status;
    }

    if ($search !== '') {
        $where[] = '(q.name LIKE ? OR q.email LIKE ? OR q.phone LIKE ? OR q.message LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = 'SELECT q.id, q.name, q.phone, q.email, q.work_type, q.message, q.status, q.created_at, q.admin_reply, q.replied_at,
                   COUNT(qr.id) AS reply_count,
                   MAX(qr.created_at) AS last_reply_at
            FROM quotes q
            LEFT JOIN quote_replies qr ON qr.quote_id = q.id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " GROUP BY q.id, q.name, q.phone, q.email, q.work_type, q.message, q.status, q.created_at, q.admin_reply, q.replied_at
              ORDER BY FIELD(q.status, 'new', 'in_progress', 'answered', 'closed'), q.created_at DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $quotes = array_map('quote_list_item_payload', $stmt->fetchAll());

    send_json(['ok' => true, 'quotes' => $quotes]);
}

if ($method === 'POST') {
    if ($action === 'update_status') {
        require_admin();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlatkérés.'], 422);
        }
        $status = clean_string($payload['status'] ?? '', 30);
        if (!is_valid_quote_status($status)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen státusz.'], 422);
        }

        $stmt = db()->prepare('UPDATE quotes SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM quotes WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                send_json(['ok' => false, 'error' => 'Ajánlatkérés nem található.'], 404);
            }
        }
        send_json(['ok' => true, 'quote' => quote_detail_payload($id)]);
    }

    if ($action === 'reply') {
        $admin = require_admin();
        $id = (int) ($payload['id'] ?? 0);
        $replyMessage = clean_string($payload['reply_message'] ?? '', 5000);
        $requestedStatus = clean_string($payload['status'] ?? '', 30);

        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlatkérés.'], 422);
        }
        if ($replyMessage === '') {
            send_json(['ok' => false, 'error' => 'Az admin válasz nem lehet üres.'], 422);
        }
        if ($requestedStatus !== '' && !in_array($requestedStatus, ['answered', 'closed'], true)) {
            send_json(['ok' => false, 'error' => 'A válasz küldésekor csak megválaszolt vagy lezárt státusz állítható be.'], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $quoteStmt = $pdo->prepare('SELECT id, name, email, work_type, message, status FROM quotes WHERE id = ? LIMIT 1 FOR UPDATE');
            $quoteStmt->execute([$id]);
            $quote = $quoteStmt->fetch();
            if (!$quote) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                send_json(['ok' => false, 'error' => 'Ajánlatkérés nem található.'], 404);
            }
            if (!filter_var($quote['email'], FILTER_VALIDATE_EMAIL)) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                send_json(['ok' => false, 'error' => 'A mentett ügyfél e-mail címe érvénytelen.'], 422);
            }

            $nextStatus = $requestedStatus !== ''
                ? $requestedStatus
                : (($quote['status'] ?? 'new') === 'closed' ? 'closed' : 'answered');

            $appName = app_config()['app_name'];
            $subject = sprintf('%s - válasz az ajánlatkérésére', $appName);
            $htmlBody = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#0f172a">'
                . '<p>Tisztelt ' . htmlspecialchars($quote['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '!</p>'
                . '<p>Köszönjük az ajánlatkérését a következő témában: <strong>' . htmlspecialchars($quote['work_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>'
                . '<p><strong>Admin válasz:</strong><br>' . format_html_email($replyMessage) . '</p>'
                . '<p>Üdvözlettel,<br>' . htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '</div>';
            $textBody = "Tisztelt {$quote['name']}!\n\n"
                . "Köszönjük az ajánlatkérését a következő témában: {$quote['work_type']}.\n\n"
                . "Admin válasz:\n{$replyMessage}\n\n"
                . "Üdvözlettel,\n{$appName}";

            $mailResult = send_app_mail($quote['email'], $quote['name'], $subject, $htmlBody, $textBody);

            $insertReplyStmt = $pdo->prepare('INSERT INTO quote_replies (quote_id, admin_user_id, reply_message) VALUES (?, ?, ?)');
            $insertReplyStmt->execute([$id, (int) $admin['id'], $replyMessage]);

            $updateQuoteStmt = $pdo->prepare('UPDATE quotes SET status = ?, admin_reply = ?, replied_at = NOW() WHERE id = ?');
            $updateQuoteStmt->execute([$nextStatus, $replyMessage, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            send_json(['ok' => false, 'error' => 'Az admin válasz mentése vagy kiküldése sikertelen.'], 500);
        }

        $response = ['ok' => true, 'quote' => quote_detail_payload($id)];
        if (($mailResult['mode'] ?? '') === 'dev_log' && app_is_development()) {
            $response['dev_email'] = $mailResult['dev'];
        }
        send_json($response);
    }

    if ($action === 'delete') {
        require_admin();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlatkérés.'], 422);
        }
        $stmt = db()->prepare('DELETE FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Ajánlatkérés nem található.'], 404);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
