<?php
declare(strict_types=1);

/*
 * Ügyfélértékelés: lezárás utáni automatikus értékeléskérés (tokenes link),
 * negatív értékelés follow-up, moderáció (pending/approved/rejected).
 */

const TB_REVIEW_REQUEST_DAYS = 30;

/**
 * E-mail küldési hiba értékeléskérésnél: a workflow újrapróbálja, az admin felület
 * kézi továbbításhoz megkapja a linket.
 */
final class TbReviewMailException extends RuntimeException
{
    public function __construct(string $message, public readonly string $manualUrl)
    {
        parent::__construct($message);
    }
}

function tb_review_recipient(string $sourceType, int $sourceId): ?array
{
    if ($sourceType === 'project') {
        $stmt = db()->prepare('SELECT p.id, p.user_id, p.title, COALESCE(u.email, l.email) AS email, COALESCE(u.name, l.name) AS name FROM projects p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN leads l ON l.id = p.lead_id WHERE p.id = ? LIMIT 1');
    } elseif ($sourceType === 'work_order') {
        $stmt = db()->prepare('SELECT wo.id, COALESCE(wo.user_id, p.user_id) AS user_id, wo.title, u.email, u.name FROM work_orders wo LEFT JOIN projects p ON p.id = wo.project_id LEFT JOIN users u ON u.id = COALESCE(wo.user_id, p.user_id) WHERE wo.id = ? LIMIT 1');
    } elseif ($sourceType === 'service_ticket') {
        $stmt = db()->prepare('SELECT st.id, st.user_id, st.subject AS title, u.email, u.name FROM service_tickets st LEFT JOIN users u ON u.id = st.user_id WHERE st.id = ? LIMIT 1');
    } else {
        return null;
    }
    $stmt->execute([$sourceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tb_review_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Értékeléskérés létrehozása + e-mail. Idempotens (forrásonként egy kérés).
 * E-mail hiba esetén kivételt dob, hogy a workflow újrapróbálja.
 */
function tb_review_request_create(string $sourceType, int $sourceId): array
{
    if (!in_array($sourceType, ['project', 'work_order', 'service_ticket'], true) || $sourceId <= 0) {
        throw new TbWorkflowPermanentError('Érvénytelen értékelés forrás.');
    }
    $recipient = tb_review_recipient($sourceType, $sourceId);
    if (!$recipient) {
        throw new TbWorkflowPermanentError('Az értékelés forrása nem található.');
    }
    $email = (string) ($recipient['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['skipped' => 'no_recipient_email'];
    }
    $existing = db()->prepare('SELECT id, status FROM review_requests WHERE source_type = ? AND source_id = ? LIMIT 1');
    $existing->execute([$sourceType, $sourceId]);
    $row = $existing->fetch();
    if ($row && in_array((string) $row['status'], ['sent', 'completed', 'expired'], true)) {
        return ['review_request_id' => (int) $row['id'], 'skipped' => 'already_' . $row['status']];
    }
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', time() + TB_REVIEW_REQUEST_DAYS * 86400);
    if ($row) {
        $requestId = (int) $row['id'];
        db()->prepare("UPDATE review_requests SET token_hash = ?, expires_at = ?, status = 'pending' WHERE id = ?")->execute([tb_review_token_hash($token), $expires, $requestId]);
    } else {
        $insert = db()->prepare("INSERT INTO review_requests (source_type, source_id, user_id, recipient_email, recipient_name, token_hash, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
        $insert->execute([$sourceType, $sourceId, $recipient['user_id'] ?? null, $email, clean_string((string) ($recipient['name'] ?? ''), 120), tb_review_token_hash($token), $expires]);
        $requestId = (int) db()->lastInsertId();
    }
    $url = app_config()['app_url'] . '/review.html?token=' . rawurlencode($token);
    $name = (string) ($recipient['name'] ?? 'Ügyfelünk');
    $text = 'Tisztelt ' . $name . "!\n\n"
        . 'Köszönjük, hogy minket választott (' . (string) ($recipient['title'] ?? '') . ").\n"
        . "Kérjük, értékelje munkánkat 1-5 csillaggal, ez mindössze egy perc:\n" . $url . "\n\n"
        . 'A link ' . TB_REVIEW_REQUEST_DAYS . " napig érvényes.\n\nÜdvözlettel:\n" . app_config()['app_name'];
    try {
        send_app_mail($email, $name, 'Hogyan értékeli munkánkat?', format_html_email($text), $text);
        db()->prepare("UPDATE review_requests SET status = 'sent', sent_at = NOW(), email_error = NULL WHERE id = ?")->execute([$requestId]);
    } catch (Throwable $e) {
        $error = clean_string(redact_secrets($e->getMessage()), 480);
        db()->prepare("UPDATE review_requests SET status = 'send_failed', email_error = ? WHERE id = ?")->execute([$error, $requestId]);
        throw new TbReviewMailException('Értékeléskérő e-mail küldése sikertelen: ' . $error, $url);
    }
    return ['review_request_id' => $requestId, 'status' => 'sent'];
}

function tb_review_request_by_token(string $token, bool $forUpdate = false): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $sql = "SELECT id, source_type, source_id, user_id, recipient_name, status, expires_at FROM review_requests WHERE token_hash = ? AND status IN ('pending','sent','send_failed') AND expires_at > NOW() LIMIT 1";
    $stmt = db()->prepare($sql . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([tb_review_token_hash($token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tb_review_submit_by_token(string $token, int $rating, string $feedback, bool $publicConsent): int
{
    if ($rating < 1 || $rating > 5) {
        throw new InvalidArgumentException('A csillag érték 1 és 5 között lehet.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $request = tb_review_request_by_token($token, true);
        if (!$request) {
            throw new InvalidArgumentException('Az értékelő link érvénytelen, lejárt vagy már felhasználták.');
        }
        $projectId = $request['source_type'] === 'project' ? (int) $request['source_id'] : null;
        $workOrderId = $request['source_type'] === 'work_order' ? (int) $request['source_id'] : null;
        $ticketId = $request['source_type'] === 'service_ticket' ? (int) $request['source_id'] : null;
        $insert = $pdo->prepare("INSERT INTO customer_reviews (user_id, project_id, work_order_id, service_ticket_id, rating, feedback, moderation_status, public_visible, public_consent, source, review_request_id) VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?, 'email_link', ?)");
        $insert->execute([$request['user_id'], $projectId, $workOrderId, $ticketId, $rating, $feedback !== '' ? $feedback : null, $publicConsent ? 1 : 0, (int) $request['id']]);
        $reviewId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE review_requests SET status = 'completed', completed_at = NOW(), review_id = ? WHERE id = ?")->execute([$reviewId, (int) $request['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    tb_review_after_submit($reviewId, $rating);
    return $reviewId;
}

function tb_review_after_submit(int $reviewId, int $rating): void
{
    tb_notify(null, 'admin', 'Új értékelés moderálásra vár', 'Értékelés #' . $reviewId . ' – ' . $rating . ' csillag.', '/admin-center.html#reviews');
    if ($rating < 3) {
        $queued = tb_workflow_enqueue('negative_review_followup', 'negative_review_followup:review:' . $reviewId, ['review_id' => $reviewId]);
        if (!$queued) {
            try {
                tb_review_negative_followup($reviewId);
            } catch (Throwable $e) {
                app_log_error('negative_review_followup_failed', $e);
            }
        }
    }
}

/**
 * Negatív (<3) értékelés belső follow-up: riasztás + idővonal bejegyzés. Csak egyszer fut le.
 */
function tb_review_negative_followup(int $reviewId): array
{
    $stmt = db()->prepare('SELECT id, user_id, project_id, rating, feedback FROM customer_reviews WHERE id = ? LIMIT 1');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch();
    if (!$review) {
        throw new TbWorkflowPermanentError('Értékelés nem található: #' . $reviewId);
    }
    if ((int) $review['rating'] >= 3) {
        return ['skipped' => 'not_negative'];
    }
    $mark = db()->prepare("UPDATE customer_reviews SET follow_up_status = 'open' WHERE id = ? AND (follow_up_status IS NULL OR follow_up_status = 'none')");
    $mark->execute([$reviewId]);
    if ($mark->rowCount() === 0) {
        return ['skipped' => 'already_open'];
    }
    $msg = 'Értékelés #' . $reviewId . ': ' . (int) $review['rating'] . ' csillag. Kérjük, vegye fel a kapcsolatot az ügyféllel 24 órán belül.';
    tb_notify(null, 'admin', 'Negatív értékelés – follow-up szükséges', $msg, '/admin-center.html#reviews', true);
    tb_alert_email('Negatív ügyfélértékelés (#' . $reviewId . ')', $msg . "\n\nVisszajelzés:\n" . (string) ($review['feedback'] ?? '-'));
    if ((int) ($review['user_id'] ?? 0) > 0) {
        db()->prepare('INSERT INTO communication_timeline (user_id, source_type, source_id, title, summary, is_sensitive) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([(int) $review['user_id'], 'review', $reviewId, 'Negatív értékelés – belső follow-up', clean_string($msg, 1000)]);
    }
    if ((int) ($review['project_id'] ?? 0) > 0) {
        db()->prepare('INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note, related_type, related_id) VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([(int) $review['project_id'], 'note', 'Negatív ügyfélértékelés érkezett, follow-up feladat nyitva.', 'customer_review', $reviewId]);
    }
    return ['follow_up' => 'open'];
}

function tb_reviews_public(int $limit = 20, int $offset = 0): array
{
    $stmt = db()->prepare("SELECT cr.id, cr.rating, cr.feedback, cr.created_at, u.name FROM customer_reviews cr LEFT JOIN users u ON u.id = cr.user_id WHERE cr.moderation_status = 'approved' AND cr.public_visible = 1 ORDER BY cr.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
    $stmt->bindValue(2, max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $parts = preg_split('/\s+/u', $name) ?: [];
        $display = $parts && $parts[0] !== '' ? $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '') : 'Ellenőrzött ügyfél';
        $rows[] = ['id' => (int) $row['id'], 'rating' => (int) $row['rating'], 'feedback' => $row['feedback'], 'display_name' => $display, 'created_at' => $row['created_at']];
    }
    $avg = db()->query("SELECT COUNT(*) AS c, AVG(rating) AS a FROM customer_reviews WHERE moderation_status = 'approved' AND public_visible = 1")->fetch();
    return ['reviews' => $rows, 'count' => (int) ($avg['c'] ?? 0), 'average' => $avg['a'] !== null ? round((float) $avg['a'], 2) : null];
}
