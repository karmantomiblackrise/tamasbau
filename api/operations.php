<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function operations_roles_with_access(): array
{
    return ['admin', 'superadmin', 'project_manager', 'field_worker', 'service_agent', 'support_agent', 'quote_manager', 'content_manager'];
}

function operations_admin_roles(): array
{
    return ['admin', 'superadmin', 'project_manager', 'service_agent', 'support_agent', 'quote_manager'];
}

function is_operations_admin(array $user): bool
{
    return in_array((string) ($user['role'] ?? 'user'), operations_admin_roles(), true);
}

function require_operations_backoffice(?array $allowedRoles = null): array
{
    $user = require_login();
    $role = (string) ($user['role'] ?? 'user');
    if (!in_array($role, operations_roles_with_access(), true)) {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága az admin modulhoz.'], 403);
    }
    if (is_array($allowedRoles) && $allowedRoles && !in_array($role, $allowedRoles, true)) {
        send_json(['ok' => false, 'error' => 'A szerepkör nem jogosult erre a műveletre.'], 403);
    }
    return $user;
}

function parse_int_nullable($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $result = (int) $value;
    return $result > 0 ? $result : null;
}

function parse_datetime_nullable($value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        send_json(['ok' => false, 'error' => 'Érvénytelen dátum/idő formátum.'], 422);
    }
    return date('Y-m-d H:i:s', $ts);
}

function parse_date_nullable($value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        send_json(['ok' => false, 'error' => 'Érvénytelen dátum formátum.'], 422);
    }
    return date('Y-m-d', $ts);
}

function operations_create_notification(?int $userId, string $audience, string $title, ?string $message = null, ?string $link = null): void
{
    try {
        $exists = db()->prepare('SELECT id FROM in_app_notifications WHERE audience = ? AND ((user_id IS NULL AND ? IS NULL) OR user_id = ?) AND title = ? AND ((message IS NULL AND ? IS NULL) OR message = ?) AND DATE(created_at) = CURDATE() LIMIT 1');
        $exists->execute([$audience, $userId, $userId, clean_string($title, 160), $message, $message !== null ? clean_string($message, 1000) : null]);
        if ($exists->fetch()) {
            return;
        }
        $stmt = db()->prepare('INSERT INTO in_app_notifications (user_id, audience, title, message, link_url) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $audience, clean_string($title, 160), $message !== null ? clean_string($message, 1000) : null, $link !== null ? clean_string($link, 500) : null]);
    } catch (Throwable $e) {
    }
}

function operations_mail_safely(string $email, string $name, string $subject, string $html, ?string $text = null): ?string
{
    try {
        send_app_mail($email, $name, $subject, $html, $text);
        return null;
    } catch (Throwable $e) {
        return 'SMTP küldés kihagyva: ' . $e->getMessage();
    }
}

function lead_statuses(): array
{
    return ['new', 'contacted', 'qualified', 'quote_sent', 'won', 'lost', 'archived'];
}

function crm_quote_statuses(): array
{
    return ['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'cancelled'];
}

function project_statuses(): array
{
    return ['draft', 'survey_scheduled', 'quoted', 'approved', 'scheduled', 'in_progress', 'on_hold', 'completed', 'cancelled'];
}

function task_statuses(): array
{
    return ['todo', 'in_progress', 'blocked', 'done', 'cancelled'];
}

function task_priorities(): array
{
    return ['low', 'normal', 'high', 'urgent'];
}

function appointment_types(): array
{
    return ['site_survey', 'troubleshooting', 'installation', 'maintenance', 'emergency'];
}

function appointment_statuses(): array
{
    return ['requested', 'confirmed', 'rescheduled', 'completed', 'cancelled', 'no_show'];
}

function service_ticket_statuses(): array
{
    return ['open', 'triaged', 'scheduled', 'in_progress', 'waiting_customer', 'resolved', 'closed', 'rejected'];
}

function service_ticket_priorities(): array
{
    return ['low', 'normal', 'high', 'emergency'];
}

function require_enum_value(string $value, array $allowed, string $label): string
{
    if (!in_array($value, $allowed, true)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen ' . $label . '.'], 422);
    }
    return $value;
}

function insert_lead_timeline(int $leadId, ?int $actorUserId, string $eventType, ?string $note = null, ?array $metadata = null): void
{
    $stmt = db()->prepare('INSERT INTO lead_timeline (lead_id, actor_user_id, event_type, event_note, metadata_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$leadId, $actorUserId, clean_string($eventType, 60), $note !== null ? clean_string($note, 5000) : null, $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
}

function insert_project_timeline(int $projectId, ?int $actorUserId, string $eventType, ?string $note = null, ?string $relatedType = null, ?int $relatedId = null): void
{
    $stmt = db()->prepare('INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note, related_type, related_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$projectId, $actorUserId, clean_string($eventType, 40), $note !== null ? clean_string($note, 5000) : null, $relatedType !== null ? clean_string($relatedType, 40) : null, $relatedId]);
}

function insert_service_ticket_history(int $ticketId, ?int $actorUserId, string $eventType, ?string $fromValue = null, ?string $toValue = null, ?string $note = null): void
{
    $stmt = db()->prepare('INSERT INTO service_ticket_history (ticket_id, actor_user_id, event_type, from_value, to_value, note) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$ticketId, $actorUserId, clean_string($eventType, 60), $fromValue !== null ? clean_string($fromValue, 80) : null, $toValue !== null ? clean_string($toValue, 80) : null, $note !== null ? clean_string($note, 500) : null]);
}

function ensure_no_appointment_conflict(?int $assignedToUserId, string $startsAt, string $endsAt, ?int $excludeId = null): void
{
    if (!$assignedToUserId) {
        return;
    }
    $sql = 'SELECT id FROM appointments WHERE assigned_to_user_id = ? AND status NOT IN (\'cancelled\',\'no_show\') AND starts_at < ? AND ends_at > ?';
    $args = [$assignedToUserId, $endsAt, $startsAt];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $args[] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    if ($stmt->fetch()) {
        send_json(['ok' => false, 'error' => 'Időpont ütközés: ugyanarra a munkatársra ebben az idősávban már van foglalás.'], 409);
    }
}

function list_crm(array $user): void
{
    require_operations_backoffice();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT l.*, a.name AS assigned_name, u.name AS customer_name FROM leads l LEFT JOIN users a ON a.id = l.assigned_to_user_id LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $lead = $stmt->fetch();
        if (!$lead) {
            send_json(['ok' => false, 'error' => 'Lead nem található.'], 404);
        }
        $timelineStmt = db()->prepare('SELECT lt.id, lt.event_type, lt.event_note, lt.metadata_json, lt.created_at, au.name AS actor_name FROM lead_timeline lt LEFT JOIN users au ON au.id = lt.actor_user_id WHERE lt.lead_id = ? ORDER BY lt.created_at DESC, lt.id DESC');
        $timelineStmt->execute([$id]);
        $quotesStmt = db()->prepare('SELECT id, title, status, amount_cents, currency, next_action_at, next_action_note, valid_until, created_at, updated_at FROM crm_quotes WHERE lead_id = ? ORDER BY created_at DESC');
        $quotesStmt->execute([$id]);
        send_json(['ok' => true, 'lead' => $lead, 'timeline' => $timelineStmt->fetchAll(), 'quotes' => $quotesStmt->fetchAll()]);
    }

    $status = clean_string($_GET['status'] ?? '', 40);
    $assignedTo = (int) ($_GET['assigned_to'] ?? 0);
    $search = clean_string($_GET['search'] ?? '', 120);
    $followup = clean_string($_GET['followup'] ?? '', 20);

    $where = [];
    $args = [];
    if ($status !== '' && $status !== 'all') {
        require_enum_value($status, lead_statuses(), 'lead státusz');
        $where[] = 'l.status = ?';
        $args[] = $status;
    }
    if ($assignedTo > 0) {
        $where[] = 'l.assigned_to_user_id = ?';
        $args[] = $assignedTo;
    }
    if ($search !== '') {
        $where[] = '(l.name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.company LIKE ? OR l.source LIKE ?)';
        $like = '%' . $search . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if ($followup === 'due') {
        $where[] = 'l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at <= NOW()';
    }

    $sql = 'SELECT l.id, l.user_id, l.quote_id, l.name, l.email, l.phone, l.company, l.source, l.address, l.note, l.status, l.assigned_to_user_id, l.next_follow_up_at, l.created_at, l.updated_at,
                   a.name AS assigned_name, u.name AS customer_name,
                   (SELECT COUNT(*) FROM crm_quotes cq WHERE cq.lead_id = l.id) AS quotes_count
            FROM leads l
            LEFT JOIN users a ON a.id = l.assigned_to_user_id
            LEFT JOIN users u ON u.id = l.user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY FIELD(l.status, 'new','contacted','qualified','quote_sent','won','lost','archived'), l.next_follow_up_at IS NULL, l.next_follow_up_at ASC, l.created_at DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($args);

    $dueStmt = db()->query("SELECT l.id, l.name, l.email, l.next_follow_up_at, l.status FROM leads l WHERE l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at <= NOW() AND l.status NOT IN ('won','lost','archived') ORDER BY l.next_follow_up_at ASC LIMIT 50");
    send_json(['ok' => true, 'leads' => $stmt->fetchAll(), 'due_followups' => $dueStmt->fetchAll()]);
}

function post_crm(array $user, array $payload): void
{
    $admin = require_operations_backoffice();
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'create_lead' || $action === 'update_lead') {
        $id = (int) ($payload['id'] ?? 0);
        $name = clean_string($payload['name'] ?? '', 120);
        $email = clean_string($payload['email'] ?? '', 190);
        $phone = clean_string($payload['phone'] ?? '', 40);
        $company = clean_string($payload['company'] ?? '', 160);
        $source = clean_string($payload['source'] ?? 'manual', 80);
        $address = clean_string($payload['address'] ?? '', 255);
        $note = clean_string($payload['note'] ?? '', 5000);
        $status = clean_string($payload['status'] ?? 'new', 40);
        $assignedToUserId = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $userId = parse_int_nullable($payload['user_id'] ?? null);
        $quoteId = parse_int_nullable($payload['quote_id'] ?? null);
        $nextFollowupAt = parse_datetime_nullable($payload['next_follow_up_at'] ?? null);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            send_json(['ok' => false, 'error' => 'A lead név és érvényes e-mail kötelező.'], 422);
        }
        require_enum_value($status, lead_statuses(), 'lead státusz');

        if ($action === 'create_lead') {
            $stmt = db()->prepare('INSERT INTO leads (user_id, quote_id, name, email, phone, company, source, address, note, status, assigned_to_user_id, next_follow_up_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $quoteId, $name, $email, $phone !== '' ? $phone : null, $company !== '' ? $company : null, $source !== '' ? $source : 'manual', $address !== '' ? $address : null, $note !== '' ? $note : null, $status, $assignedToUserId, $nextFollowupAt]);
            $leadId = (int) db()->lastInsertId();
            insert_lead_timeline($leadId, (int) $admin['id'], 'created', 'Lead létrehozva.');
            log_admin_activity((int) $admin['id'], 'lead_created', 'lead', $leadId, ['status' => $status]);
            send_json(['ok' => true, 'id' => $leadId], 201);
        }

        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen lead azonosító.'], 422);
        }

        $stmt = db()->prepare('UPDATE leads SET user_id = ?, quote_id = ?, name = ?, email = ?, phone = ?, company = ?, source = ?, address = ?, note = ?, status = ?, assigned_to_user_id = ?, next_follow_up_at = ? WHERE id = ?');
        $stmt->execute([$userId, $quoteId, $name, $email, $phone !== '' ? $phone : null, $company !== '' ? $company : null, $source !== '' ? $source : 'manual', $address !== '' ? $address : null, $note !== '' ? $note : null, $status, $assignedToUserId, $nextFollowupAt, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM leads WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                send_json(['ok' => false, 'error' => 'Lead nem található.'], 404);
            }
        }
        insert_lead_timeline($id, (int) $admin['id'], 'updated', 'Lead adatok frissítve.');
        log_admin_activity((int) $admin['id'], 'lead_updated', 'lead', $id, ['status' => $status]);
        send_json(['ok' => true]);
    }

    if ($action === 'update_status') {
        $leadId = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 40);
        $note = clean_string($payload['note'] ?? '', 500);
        if ($leadId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen lead.'], 422);
        }
        require_enum_value($status, lead_statuses(), 'lead státusz');
        $stmt = db()->prepare('SELECT status FROM leads WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
        $row = $stmt->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Lead nem található.'], 404);
        }
        $prev = (string) $row['status'];
        $update = db()->prepare('UPDATE leads SET status = ? WHERE id = ?');
        $update->execute([$status, $leadId]);
        insert_lead_timeline($leadId, (int) $admin['id'], 'status_change', $note !== '' ? $note : null, ['from' => $prev, 'to' => $status]);
        log_admin_activity((int) $admin['id'], 'lead_status_changed', 'lead', $leadId, ['from' => $prev, 'to' => $status]);
        send_json(['ok' => true]);
    }

    if ($action === 'assign' || $action === 'schedule_followup') {
        $leadId = (int) ($payload['id'] ?? 0);
        if ($leadId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen lead.'], 422);
        }
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $nextFollowupAt = parse_datetime_nullable($payload['next_follow_up_at'] ?? null);
        $update = db()->prepare('UPDATE leads SET assigned_to_user_id = ?, next_follow_up_at = ? WHERE id = ?');
        $update->execute([$assignedTo, $nextFollowupAt, $leadId]);
        insert_lead_timeline($leadId, (int) $admin['id'], $action === 'assign' ? 'assigned' : 'follow_up_scheduled', clean_string($payload['note'] ?? '', 500));
        log_admin_activity((int) $admin['id'], 'lead_assignment_followup', 'lead', $leadId, ['assigned_to' => $assignedTo, 'next_follow_up_at' => $nextFollowupAt]);
        send_json(['ok' => true]);
    }

    if ($action === 'add_timeline_note') {
        $leadId = (int) ($payload['id'] ?? 0);
        $note = clean_string($payload['note'] ?? '', 2000);
        if ($leadId <= 0 || $note === '') {
            send_json(['ok' => false, 'error' => 'Lead és megjegyzés szükséges.'], 422);
        }
        insert_lead_timeline($leadId, (int) $admin['id'], 'note', $note);
        send_json(['ok' => true]);
    }

    if ($action === 'create_quote') {
        $leadId = parse_int_nullable($payload['lead_id'] ?? null);
        $userId = parse_int_nullable($payload['user_id'] ?? null);
        $projectId = parse_int_nullable($payload['project_id'] ?? null);
        $title = clean_string($payload['title'] ?? '', 180);
        $body = clean_string($payload['body'] ?? '', 10000);
        $amount = parse_int_nullable($payload['amount_cents'] ?? null);
        $currency = strtoupper(clean_string($payload['currency'] ?? 'HUF', 8));
        $status = clean_string($payload['status'] ?? 'draft', 30);
        $nextActionAt = parse_datetime_nullable($payload['next_action_at'] ?? null);
        $nextActionNote = clean_string($payload['next_action_note'] ?? '', 500);
        $template = clean_string($payload['followup_template'] ?? '', 120);
        $validUntil = parse_date_nullable($payload['valid_until'] ?? null);
        $printPayload = clean_string($payload['print_payload'] ?? '', 16000);

        if ($title === '') {
            send_json(['ok' => false, 'error' => 'Ajánlat cím kötelező.'], 422);
        }
        require_enum_value($status, crm_quote_statuses(), 'ajánlat státusz');

        $stmt = db()->prepare('INSERT INTO crm_quotes (lead_id, user_id, project_id, title, body, amount_cents, currency, status, next_action_at, next_action_note, followup_template, valid_until, print_payload, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$leadId, $userId, $projectId, $title, $body !== '' ? $body : null, $amount, $currency !== '' ? $currency : 'HUF', $status, $nextActionAt, $nextActionNote !== '' ? $nextActionNote : null, $template !== '' ? $template : null, $validUntil, $printPayload !== '' ? $printPayload : null, (int) $admin['id']]);
        $quoteId = (int) db()->lastInsertId();

        $statusStmt = db()->prepare('INSERT INTO crm_quote_status_logs (crm_quote_id, from_status, to_status, changed_by_user_id, note) VALUES (?, ?, ?, ?, ?)');
        $statusStmt->execute([$quoteId, 'draft', $status, (int) $admin['id'], 'Ajánlat létrehozva']);

        if ($leadId) {
            insert_lead_timeline($leadId, (int) $admin['id'], 'quote_created', $title, ['crm_quote_id' => $quoteId]);
        }
        if ($projectId) {
            insert_project_timeline($projectId, (int) $admin['id'], 'quote', $title, 'crm_quote', $quoteId);
        }

        log_admin_activity((int) $admin['id'], 'crm_quote_created', 'crm_quote', $quoteId, ['status' => $status]);
        send_json(['ok' => true, 'id' => $quoteId], 201);
    }

    if ($action === 'update_quote_status') {
        $quoteId = (int) ($payload['id'] ?? 0);
        $nextStatus = clean_string($payload['status'] ?? '', 30);
        $note = clean_string($payload['note'] ?? '', 500);
        if ($quoteId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlat.'], 422);
        }
        require_enum_value($nextStatus, crm_quote_statuses(), 'ajánlat státusz');

        $stmt = db()->prepare('SELECT id, lead_id, project_id, status, title FROM crm_quotes WHERE id = ? LIMIT 1');
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch();
        if (!$quote) {
            send_json(['ok' => false, 'error' => 'Ajánlat nem található.'], 404);
        }

        $update = db()->prepare('UPDATE crm_quotes SET status = ? WHERE id = ?');
        $update->execute([$nextStatus, $quoteId]);
        $log = db()->prepare('INSERT INTO crm_quote_status_logs (crm_quote_id, from_status, to_status, changed_by_user_id, note) VALUES (?, ?, ?, ?, ?)');
        $log->execute([$quoteId, (string) $quote['status'], $nextStatus, (int) $admin['id'], $note !== '' ? $note : null]);

        if ((int) ($quote['lead_id'] ?? 0) > 0) {
            insert_lead_timeline((int) $quote['lead_id'], (int) $admin['id'], 'quote_status_change', $note !== '' ? $note : null, ['from' => $quote['status'], 'to' => $nextStatus, 'crm_quote_id' => $quoteId]);
        }
        if ((int) ($quote['project_id'] ?? 0) > 0) {
            insert_project_timeline((int) $quote['project_id'], (int) $admin['id'], 'status_change', 'Ajánlat státusz: ' . $nextStatus, 'crm_quote', $quoteId);
        }

        log_admin_activity((int) $admin['id'], 'crm_quote_status_changed', 'crm_quote', $quoteId, ['from' => $quote['status'], 'to' => $nextStatus]);
        send_json(['ok' => true]);
    }

    if ($action === 'send_quote_reminder') {
        $quoteId = (int) ($payload['id'] ?? 0);
        if ($quoteId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlat azonosító.'], 422);
        }

        $stmt = db()->prepare('SELECT cq.id, cq.title, cq.status, cq.body, cq.amount_cents, cq.currency, cq.next_action_note, l.email, l.name FROM crm_quotes cq LEFT JOIN leads l ON l.id = cq.lead_id WHERE cq.id = ? LIMIT 1');
        $stmt->execute([$quoteId]);
        $row = $stmt->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Ajánlat nem található.'], 404);
        }
        if (!filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            send_json(['ok' => false, 'error' => 'Az ajánlathoz nincs érvényes lead e-mail cím kapcsolva.'], 422);
        }

        $subject = 'Ajánlat emlékeztető: ' . (string) $row['title'];
        $amountText = ($row['amount_cents'] !== null) ? number_format(((int) $row['amount_cents']) / 100, 0, ',', ' ') . ' ' . ($row['currency'] ?: 'HUF') : 'egyeztetés szerint';
        $html = '<p>Tisztelt ' . htmlspecialchars((string) ($row['name'] ?? 'Ügyfelünk'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '!</p>'
            . '<p>Ez egy emlékeztető a következő ajánlatról: <strong>' . htmlspecialchars((string) $row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>'
            . '<p>Összeg: <strong>' . htmlspecialchars($amountText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>'
            . '<p>' . format_html_email((string) ($row['next_action_note'] ?? 'Kérjük jelezze vissza döntését.')) . '</p>';

        $warning = operations_mail_safely((string) $row['email'], (string) ($row['name'] ?? ''), $subject, $html);
        if ($warning === null && (string) $row['status'] === 'draft') {
            $update = db()->prepare('UPDATE crm_quotes SET status = ? WHERE id = ?');
            $update->execute(['sent', $quoteId]);
            $log = db()->prepare('INSERT INTO crm_quote_status_logs (crm_quote_id, from_status, to_status, changed_by_user_id, note) VALUES (?, ?, ?, ?, ?)');
            $log->execute([$quoteId, 'draft', 'sent', (int) $admin['id'], 'Emlékeztető küldése']);
        }
        send_json(['ok' => true, 'warning' => $warning]);
    }

    if ($action === 'pending_reminders' || $action === 'dispatch_pending_reminders') {
        $dispatch = $action === 'dispatch_pending_reminders';
        $rows = [];

        $leadStmt = db()->query("SELECT id, name, email, next_follow_up_at FROM leads WHERE next_follow_up_at IS NOT NULL AND next_follow_up_at <= NOW() AND status NOT IN ('won','lost','archived') ORDER BY next_follow_up_at ASC LIMIT 100");
        foreach ($leadStmt->fetchAll() as $lead) {
            $rows[] = ['type' => 'lead_followup', 'id' => (int) $lead['id'], 'title' => $lead['name'], 'at' => $lead['next_follow_up_at']];
            if ($dispatch) {
                operations_create_notification(null, 'admin', 'Lejárt lead follow-up', 'Lead #' . (int) $lead['id'] . ' - ' . (string) $lead['name'], '/admin#leads');
            }
        }

        $quoteStmt = db()->query("SELECT id, title, next_action_at FROM crm_quotes WHERE next_action_at IS NOT NULL AND next_action_at <= NOW() AND status IN ('draft','sent','viewed') ORDER BY next_action_at ASC LIMIT 100");
        foreach ($quoteStmt->fetchAll() as $quote) {
            $rows[] = ['type' => 'quote_followup', 'id' => (int) $quote['id'], 'title' => $quote['title'], 'at' => $quote['next_action_at']];
            if ($dispatch) {
                operations_create_notification(null, 'admin', 'Ajánlat follow-up esedékes', 'Ajánlat #' . (int) $quote['id'] . ' - ' . (string) $quote['title'], '/admin#crm-quotes');
            }
        }

        send_json(['ok' => true, 'pending' => $rows, 'dispatched' => $dispatch]);
    }

    send_json(['ok' => false, 'error' => 'Nem támogatott CRM művelet.'], 405);
}

function list_projects_endpoint(array $user): void
{
    require_operations_backoffice();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT p.*, u.name AS customer_name, a.name AS assigned_name FROM projects p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN users a ON a.id = p.assigned_to_user_id WHERE p.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) {
            send_json(['ok' => false, 'error' => 'Projekt nem található.'], 404);
        }

        $timelineStmt = db()->prepare('SELECT pt.*, au.name AS actor_name FROM project_timeline pt LEFT JOIN users au ON au.id = pt.actor_user_id WHERE pt.project_id = ? ORDER BY pt.created_at DESC, pt.id DESC');
        $timelineStmt->execute([$id]);
        $workOrderStmt = db()->prepare('SELECT id, title, status, priority, due_at, assigned_to_user_id, created_at FROM work_orders WHERE project_id = ? ORDER BY created_at DESC');
        $workOrderStmt->execute([$id]);
        send_json(['ok' => true, 'project' => $project, 'timeline' => $timelineStmt->fetchAll(), 'work_orders' => $workOrderStmt->fetchAll()]);
    }

    $status = clean_string($_GET['status'] ?? '', 40);
    $search = clean_string($_GET['search'] ?? '', 140);

    $where = [];
    $args = [];
    if ($status !== '' && $status !== 'all') {
        require_enum_value($status, project_statuses(), 'projekt státusz');
        $where[] = 'p.status = ?';
        $args[] = $status;
    }
    if ($search !== '') {
        $where[] = '(p.title LIKE ? OR p.address LIKE ? OR p.project_type LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }

    $sql = 'SELECT p.id, p.user_id, p.lead_id, p.title, p.address, p.project_type, p.description, p.budget_cents, p.planned_start_date, p.planned_end_date, p.assigned_to_user_id, p.status, p.internal_note, p.created_at, p.updated_at,
                   u.name AS customer_name, u.email AS customer_email, a.name AS assigned_name,
                   (SELECT COUNT(*) FROM work_orders wo WHERE wo.project_id = p.id) AS work_order_count
            FROM projects p
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN users a ON a.id = p.assigned_to_user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    send_json(['ok' => true, 'projects' => $stmt->fetchAll()]);
}

function post_projects_endpoint(array $payload): void
{
    $admin = require_operations_backoffice();
    $action = clean_string($payload['action'] ?? '', 40);

    if ($action === 'create_project' || $action === 'update_project') {
        $id = (int) ($payload['id'] ?? 0);
        $userId = parse_int_nullable($payload['user_id'] ?? null);
        $leadId = parse_int_nullable($payload['lead_id'] ?? null);
        $title = clean_string($payload['title'] ?? '', 180);
        $address = clean_string($payload['address'] ?? '', 255);
        $type = clean_string($payload['project_type'] ?? '', 80);
        $description = clean_string($payload['description'] ?? '', 10000);
        $budget = parse_int_nullable($payload['budget_cents'] ?? null);
        $plannedStart = parse_date_nullable($payload['planned_start_date'] ?? null);
        $plannedEnd = parse_date_nullable($payload['planned_end_date'] ?? null);
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $status = clean_string($payload['status'] ?? 'draft', 40);
        $internalNote = clean_string($payload['internal_note'] ?? '', 5000);

        if ($title === '') {
            send_json(['ok' => false, 'error' => 'Projekt cím kötelező.'], 422);
        }
        require_enum_value($status, project_statuses(), 'projekt státusz');

        if ($action === 'create_project') {
            $stmt = db()->prepare('INSERT INTO projects (user_id, lead_id, title, address, project_type, description, budget_cents, planned_start_date, planned_end_date, assigned_to_user_id, status, internal_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $leadId, $title, $address !== '' ? $address : null, $type !== '' ? $type : null, $description !== '' ? $description : null, $budget, $plannedStart, $plannedEnd, $assignedTo, $status, $internalNote !== '' ? $internalNote : null]);
            $projectId = (int) db()->lastInsertId();
            insert_project_timeline($projectId, (int) $admin['id'], 'created', 'Projekt létrehozva.');
            log_admin_activity((int) $admin['id'], 'project_created', 'project', $projectId, ['status' => $status]);
            send_json(['ok' => true, 'id' => $projectId], 201);
        }

        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen projekt.'], 422);
        }

        $stmt = db()->prepare('UPDATE projects SET user_id = ?, lead_id = ?, title = ?, address = ?, project_type = ?, description = ?, budget_cents = ?, planned_start_date = ?, planned_end_date = ?, assigned_to_user_id = ?, status = ?, internal_note = ? WHERE id = ?');
        $stmt->execute([$userId, $leadId, $title, $address !== '' ? $address : null, $type !== '' ? $type : null, $description !== '' ? $description : null, $budget, $plannedStart, $plannedEnd, $assignedTo, $status, $internalNote !== '' ? $internalNote : null, $id]);
        insert_project_timeline($id, (int) $admin['id'], 'note', 'Projekt adatok frissítve.');
        log_admin_activity((int) $admin['id'], 'project_updated', 'project', $id, ['status' => $status]);
        send_json(['ok' => true]);
    }

    if ($action === 'update_status') {
        $projectId = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 40);
        $note = clean_string($payload['note'] ?? '', 500);
        if ($projectId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen projekt.'], 422);
        }
        require_enum_value($status, project_statuses(), 'projekt státusz');

        $prevStmt = db()->prepare('SELECT status FROM projects WHERE id = ? LIMIT 1');
        $prevStmt->execute([$projectId]);
        $row = $prevStmt->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Projekt nem található.'], 404);
        }
        $update = db()->prepare('UPDATE projects SET status = ? WHERE id = ?');
        $update->execute([$status, $projectId]);

        insert_project_timeline($projectId, (int) $admin['id'], 'status_change', $note !== '' ? $note : null, null, null);
        log_admin_activity((int) $admin['id'], 'project_status_changed', 'project', $projectId, ['from' => $row['status'], 'to' => $status]);
        send_json(['ok' => true]);
    }

    if ($action === 'add_timeline_event') {
        $projectId = (int) ($payload['id'] ?? 0);
        $eventType = clean_string($payload['event_type'] ?? 'note', 40);
        $note = clean_string($payload['event_note'] ?? '', 5000);
        $relatedType = clean_string($payload['related_type'] ?? '', 40);
        $relatedId = parse_int_nullable($payload['related_id'] ?? null);

        require_enum_value($eventType, ['created', 'quote', 'status_change', 'task', 'photo', 'document', 'work_order', 'note'], 'projekt esemény');
        if ($projectId <= 0 || $note === '') {
            send_json(['ok' => false, 'error' => 'Projekt és esemény leírás kötelező.'], 422);
        }

        insert_project_timeline($projectId, (int) $admin['id'], $eventType, $note, $relatedType !== '' ? $relatedType : null, $relatedId);
        send_json(['ok' => true]);
    }

    send_json(['ok' => false, 'error' => 'Nem támogatott projekt művelet.'], 405);
}

function list_work_orders_endpoint(array $user): void
{
    $meOnly = clean_string($_GET['scope'] ?? '', 20) === 'me';
    if ($meOnly) {
        $user = require_login();
    } else {
        $user = require_operations_backoffice();
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT wo.*, p.title AS project_title, a.name AS assigned_name, u.name AS customer_name FROM work_orders wo LEFT JOIN projects p ON p.id = wo.project_id LEFT JOIN users a ON a.id = wo.assigned_to_user_id LEFT JOIN users u ON u.id = wo.user_id WHERE wo.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $workOrder = $stmt->fetch();
        if (!$workOrder) {
            send_json(['ok' => false, 'error' => 'Munkalap nem található.'], 404);
        }

        if ($meOnly || !is_operations_admin($user)) {
            $uid = (int) $user['id'];
            if (
                $uid !== (int) ($workOrder['assigned_to_user_id'] ?? 0)
                && $uid !== (int) ($workOrder['user_id'] ?? 0)
            ) {
                send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a munkalaphoz.'], 403);
            }
        }

        $taskStmt = db()->prepare('SELECT wt.*, u.name AS assigned_name FROM work_order_tasks wt LEFT JOIN users u ON u.id = wt.assigned_to_user_id WHERE wt.work_order_id = ? ORDER BY wt.sort_order ASC, wt.id ASC');
        $taskStmt->execute([$id]);
        $checklistStmt = db()->prepare('SELECT id, item_text, is_done, created_at, updated_at FROM work_order_checklists WHERE work_order_id = ? ORDER BY id ASC');
        $checklistStmt->execute([$id]);

        send_json(['ok' => true, 'work_order' => $workOrder, 'tasks' => $taskStmt->fetchAll(), 'checklist' => $checklistStmt->fetchAll()]);
    }

    $where = [];
    $args = [];
    if ($meOnly || !is_operations_admin($user)) {
        $where[] = '(wo.assigned_to_user_id = ? OR wo.user_id = ?)';
        $args[] = (int) $user['id'];
        $args[] = (int) $user['id'];
    }

    $status = clean_string($_GET['status'] ?? '', 20);
    $priority = clean_string($_GET['priority'] ?? '', 20);
    $search = clean_string($_GET['search'] ?? '', 140);

    if ($status !== '' && $status !== 'all') {
        require_enum_value($status, task_statuses(), 'munkalap státusz');
        $where[] = 'wo.status = ?';
        $args[] = $status;
    }
    if ($priority !== '' && $priority !== 'all') {
        require_enum_value($priority, task_priorities(), 'prioritás');
        $where[] = 'wo.priority = ?';
        $args[] = $priority;
    }
    if ($search !== '') {
        $where[] = '(wo.title LIKE ? OR wo.location LIKE ? OR wo.work_type LIKE ? OR wo.description LIKE ?)';
        $like = '%' . $search . '%';
        array_push($args, $like, $like, $like, $like);
    }

    $sql = 'SELECT wo.id, wo.project_id, wo.user_id, wo.title, wo.location, wo.work_type, wo.description, wo.assigned_to_user_id, wo.status, wo.priority, wo.planned_minutes, wo.actual_minutes, wo.due_at, wo.handover_ready, wo.client_signature_name, wo.closed_at, wo.created_at, wo.updated_at,
                   p.title AS project_title, a.name AS assigned_name, u.name AS customer_name,
                   (SELECT COUNT(*) FROM work_order_tasks wt WHERE wt.work_order_id = wo.id) AS tasks_count
            FROM work_orders wo
            LEFT JOIN projects p ON p.id = wo.project_id
            LEFT JOIN users a ON a.id = wo.assigned_to_user_id
            LEFT JOIN users u ON u.id = wo.user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY wo.due_at IS NULL, wo.due_at ASC, wo.updated_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    send_json(['ok' => true, 'work_orders' => $stmt->fetchAll()]);
}

function post_work_orders_endpoint(array $payload): void
{
    $user = require_operations_backoffice();
    $action = clean_string($payload['action'] ?? '', 50);

    if ($action === 'create_work_order' || $action === 'update_work_order') {
        $id = (int) ($payload['id'] ?? 0);
        $projectId = parse_int_nullable($payload['project_id'] ?? null);
        $userId = parse_int_nullable($payload['user_id'] ?? null);
        $title = clean_string($payload['title'] ?? '', 180);
        $location = clean_string($payload['location'] ?? '', 255);
        $workType = clean_string($payload['work_type'] ?? '', 80);
        $description = clean_string($payload['description'] ?? '', 10000);
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $status = clean_string($payload['status'] ?? 'todo', 20);
        $priority = clean_string($payload['priority'] ?? 'normal', 20);
        $plannedMinutes = parse_int_nullable($payload['planned_minutes'] ?? null);
        $actualMinutes = parse_int_nullable($payload['actual_minutes'] ?? null);
        $dueAt = parse_datetime_nullable($payload['due_at'] ?? null);
        $handoverReady = !empty($payload['handover_ready']) ? 1 : 0;
        $clientSignature = clean_string($payload['client_signature_name'] ?? '', 120);

        if ($title === '') {
            send_json(['ok' => false, 'error' => 'Munkalap cím kötelező.'], 422);
        }
        require_enum_value($status, task_statuses(), 'munkalap státusz');
        require_enum_value($priority, task_priorities(), 'munkalap prioritás');

        if ($action === 'create_work_order') {
            $stmt = db()->prepare('INSERT INTO work_orders (project_id, user_id, title, location, work_type, description, assigned_to_user_id, status, priority, planned_minutes, actual_minutes, due_at, handover_ready, client_signature_name, closed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $closedAt = $status === 'done' || $status === 'cancelled' ? date('Y-m-d H:i:s') : null;
            $stmt->execute([$projectId, $userId, $title, $location !== '' ? $location : null, $workType !== '' ? $workType : null, $description !== '' ? $description : null, $assignedTo, $status, $priority, $plannedMinutes, $actualMinutes, $dueAt, $handoverReady, $clientSignature !== '' ? $clientSignature : null, $closedAt]);
            $id = (int) db()->lastInsertId();
            if ($projectId) {
                insert_project_timeline($projectId, (int) $user['id'], 'work_order', 'Munkalap létrehozva: ' . $title, 'work_order', $id);
            }
            log_admin_activity((int) $user['id'], 'work_order_created', 'work_order', $id, ['status' => $status, 'priority' => $priority]);
            send_json(['ok' => true, 'id' => $id], 201);
        }

        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen munkalap.'], 422);
        }

        $existingStmt = db()->prepare('SELECT status, closed_at FROM work_orders WHERE id = ? LIMIT 1');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            send_json(['ok' => false, 'error' => 'Munkalap nem található.'], 404);
        }
        $wasClosed = in_array((string) ($existing['status'] ?? ''), ['done', 'cancelled'], true);
        $willBeClosed = in_array($status, ['done', 'cancelled'], true);
        if ($willBeClosed) {
            $closedAt = $existing['closed_at'] ?: date('Y-m-d H:i:s');
        } elseif ($wasClosed) {
            $closedAt = null;
        } else {
            $closedAt = $existing['closed_at'];
        }
        $stmt = db()->prepare('UPDATE work_orders SET project_id = ?, user_id = ?, title = ?, location = ?, work_type = ?, description = ?, assigned_to_user_id = ?, status = ?, priority = ?, planned_minutes = ?, actual_minutes = ?, due_at = ?, handover_ready = ?, client_signature_name = ?, closed_at = ? WHERE id = ?');
        $stmt->execute([$projectId, $userId, $title, $location !== '' ? $location : null, $workType !== '' ? $workType : null, $description !== '' ? $description : null, $assignedTo, $status, $priority, $plannedMinutes, $actualMinutes, $dueAt, $handoverReady, $clientSignature !== '' ? $clientSignature : null, $closedAt, $id]);
        log_admin_activity((int) $user['id'], 'work_order_updated', 'work_order', $id, ['status' => $status, 'priority' => $priority]);
        send_json(['ok' => true]);
    }

    if ($action === 'quick_action') {
        $id = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 20);
        $assignedTo = array_key_exists('assigned_to_user_id', $payload) ? parse_int_nullable($payload['assigned_to_user_id']) : null;
        $dueAt = array_key_exists('due_at', $payload) ? parse_datetime_nullable($payload['due_at']) : null;
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen munkalap.'], 422);
        }
        $currentStatus = null;
        $currentClosedAt = null;
        if ($status !== '') {
            $currentStmt = db()->prepare('SELECT status, closed_at FROM work_orders WHERE id = ? LIMIT 1');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                send_json(['ok' => false, 'error' => 'Munkalap nem található.'], 404);
            }
            $currentStatus = (string) ($current['status'] ?? '');
            $currentClosedAt = $current['closed_at'] ?? null;
        }

        $changes = [];
        $args = [];
        if ($status !== '') {
            require_enum_value($status, task_statuses(), 'munkalap státusz');
            $changes[] = 'status = ?';
            $args[] = $status;
            if (in_array($status, ['done', 'cancelled'], true)) {
                if (!in_array($currentStatus, ['done', 'cancelled'], true) || $currentClosedAt === null) {
                    $changes[] = 'closed_at = NOW()';
                }
            } else {
                $changes[] = 'closed_at = NULL';
            }
        }
        if (array_key_exists('assigned_to_user_id', $payload)) {
            $changes[] = 'assigned_to_user_id = ?';
            $args[] = $assignedTo;
        }
        if (array_key_exists('due_at', $payload)) {
            $changes[] = 'due_at = ?';
            $args[] = $dueAt;
        }
        if (!$changes) {
            send_json(['ok' => false, 'error' => 'Nincs módosítandó mező.'], 422);
        }

        $sql = 'UPDATE work_orders SET ' . implode(', ', $changes) . ' WHERE id = ?';
        $args[] = $id;
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        log_admin_activity((int) $user['id'], 'work_order_quick_action', 'work_order', $id, ['status' => $status, 'assigned_to' => $assignedTo, 'due_at' => $dueAt]);
        send_json(['ok' => true]);
    }

    if ($action === 'add_task') {
        $workOrderId = (int) ($payload['work_order_id'] ?? 0);
        $title = clean_string($payload['title'] ?? '', 180);
        $description = clean_string($payload['description'] ?? '', 4000);
        $status = clean_string($payload['status'] ?? 'todo', 20);
        $priority = clean_string($payload['priority'] ?? 'normal', 20);
        $dueAt = parse_datetime_nullable($payload['due_at'] ?? null);
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);

        if ($workOrderId <= 0 || $title === '') {
            send_json(['ok' => false, 'error' => 'Munkalap és feladat cím kötelező.'], 422);
        }
        require_enum_value($status, task_statuses(), 'feladat státusz');
        require_enum_value($priority, task_priorities(), 'feladat prioritás');

        $sortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM work_order_tasks WHERE work_order_id = ?');
        $sortStmt->execute([$workOrderId]);
        $nextSort = (int) (($sortStmt->fetch()['next_sort'] ?? 1));

        $stmt = db()->prepare('INSERT INTO work_order_tasks (work_order_id, title, description, status, priority, due_at, assigned_to_user_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$workOrderId, $title, $description !== '' ? $description : null, $status, $priority, $dueAt, $assignedTo, $nextSort]);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    if ($action === 'update_task_status') {
        $taskId = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['status'] ?? '', 20);
        if ($taskId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen feladat.'], 422);
        }
        require_enum_value($status, task_statuses(), 'feladat státusz');

        $stmt = db()->prepare('UPDATE work_order_tasks SET status = ? WHERE id = ?');
        $stmt->execute([$status, $taskId]);
        send_json(['ok' => true]);
    }

    if ($action === 'add_checklist_item') {
        $workOrderId = (int) ($payload['work_order_id'] ?? 0);
        $text = clean_string($payload['item_text'] ?? '', 255);
        if ($workOrderId <= 0 || $text === '') {
            send_json(['ok' => false, 'error' => 'Munkalap és ellenőrzőlista tétel kötelező.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO work_order_checklists (work_order_id, item_text, is_done) VALUES (?, ?, 0)');
        $stmt->execute([$workOrderId, $text]);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    if ($action === 'toggle_checklist_item') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen checklist elem.'], 422);
        }
        $done = !empty($payload['is_done']) ? 1 : 0;
        $stmt = db()->prepare('UPDATE work_order_checklists SET is_done = ? WHERE id = ?');
        $stmt->execute([$done, $id]);
        send_json(['ok' => true]);
    }

    send_json(['ok' => false, 'error' => 'Nem támogatott munkalap művelet.'], 405);
}

function list_appointments_endpoint(array $user): void
{
    $isAdminView = clean_string($_GET['scope'] ?? '', 20) !== 'me';
    if ($isAdminView) {
        $user = require_operations_backoffice();
    } else {
        $user = require_login();
    }

    $where = [];
    $args = [];
    if (!$isAdminView || !is_operations_admin($user)) {
        $where[] = '(a.user_id = ? OR a.assigned_to_user_id = ?)';
        $args[] = (int) $user['id'];
        $args[] = (int) $user['id'];
    }

    $status = clean_string($_GET['status'] ?? '', 20);
    $assigned = (int) ($_GET['assigned_to_user_id'] ?? 0);
    $from = parse_date_nullable($_GET['from'] ?? null);
    $to = parse_date_nullable($_GET['to'] ?? null);

    if ($status !== '' && $status !== 'all') {
        require_enum_value($status, appointment_statuses(), 'időpont státusz');
        $where[] = 'a.status = ?';
        $args[] = $status;
    }
    if ($assigned > 0) {
        $where[] = 'a.assigned_to_user_id = ?';
        $args[] = $assigned;
    }
    if ($from !== null) {
        $where[] = 'a.starts_at >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $where[] = 'a.starts_at <= ?';
        $args[] = $to . ' 23:59:59';
    }

    $sql = 'SELECT a.*, au.name AS assigned_name, u.name AS customer_name, u.email AS customer_email, p.title AS project_title, wo.title AS work_order_title
            FROM appointments a
            LEFT JOIN users au ON au.id = a.assigned_to_user_id
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN projects p ON p.id = a.project_id
            LEFT JOIN work_orders wo ON wo.id = a.work_order_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY a.starts_at ASC, a.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    send_json(['ok' => true, 'appointments' => $stmt->fetchAll()]);
}

function post_appointments_endpoint(array $payload): void
{
    $admin = require_operations_backoffice();
    $action = clean_string($payload['action'] ?? '', 40);

    if ($action === 'create_appointment' || $action === 'reschedule_appointment' || $action === 'update_appointment') {
        $id = (int) ($payload['id'] ?? 0);
        $type = clean_string($payload['appointment_type'] ?? 'site_survey', 30);
        $status = clean_string($payload['status'] ?? 'requested', 20);
        $startsAt = parse_datetime_nullable($payload['starts_at'] ?? null);
        $endsAt = parse_datetime_nullable($payload['ends_at'] ?? null);
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $userId = parse_int_nullable($payload['user_id'] ?? null);
        $leadId = parse_int_nullable($payload['lead_id'] ?? null);
        $projectId = parse_int_nullable($payload['project_id'] ?? null);
        $workOrderId = parse_int_nullable($payload['work_order_id'] ?? null);
        $title = clean_string($payload['title'] ?? '', 160);
        $note = clean_string($payload['note'] ?? '', 5000);
        $location = clean_string($payload['location'] ?? '', 255);

        require_enum_value($type, appointment_types(), 'időpont típus');
        require_enum_value($status, appointment_statuses(), 'időpont státusz');
        if ($startsAt === null || $endsAt === null || strtotime($startsAt) >= strtotime($endsAt)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen kezdő/befejező időpont.'], 422);
        }

        ensure_no_appointment_conflict($assignedTo, $startsAt, $endsAt, $id > 0 ? $id : null);

        if ($action === 'create_appointment') {
            $stmt = db()->prepare('INSERT INTO appointments (appointment_type, status, starts_at, ends_at, assigned_to_user_id, user_id, lead_id, project_id, work_order_id, title, note, location, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$type, $status, $startsAt, $endsAt, $assignedTo, $userId, $leadId, $projectId, $workOrderId, $title !== '' ? $title : null, $note !== '' ? $note : null, $location !== '' ? $location : null, (int) $admin['id']]);
            $appointmentId = (int) db()->lastInsertId();
            if ($projectId) {
                insert_project_timeline($projectId, (int) $admin['id'], 'note', 'Új időpont létrehozva: ' . date('Y-m-d H:i', strtotime($startsAt)), 'appointment', $appointmentId);
            }
            log_admin_activity((int) $admin['id'], 'appointment_created', 'appointment', $appointmentId, ['status' => $status, 'type' => $type]);
            send_json(['ok' => true, 'id' => $appointmentId], 201);
        }

        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen időpont.'], 422);
        }

        $existingStmt = db()->prepare('SELECT starts_at, ends_at FROM appointments WHERE id = ? LIMIT 1');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            send_json(['ok' => false, 'error' => 'Időpont nem található.'], 404);
        }
        $resetReminder = $action === 'reschedule_appointment'
            || (string) ($existing['starts_at'] ?? '') !== $startsAt
            || (string) ($existing['ends_at'] ?? '') !== $endsAt;

        $sql = 'UPDATE appointments SET appointment_type = ?, status = ?, starts_at = ?, ends_at = ?, assigned_to_user_id = ?, user_id = ?, lead_id = ?, project_id = ?, work_order_id = ?, title = ?, note = ?, location = ?';
        if ($resetReminder) {
            $sql .= ', reminder_sent_at = NULL';
        }
        $sql .= ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute([$type, $status, $startsAt, $endsAt, $assignedTo, $userId, $leadId, $projectId, $workOrderId, $title !== '' ? $title : null, $note !== '' ? $note : null, $location !== '' ? $location : null, $id]);
        log_admin_activity((int) $admin['id'], 'appointment_updated', 'appointment', $id, ['status' => $status, 'type' => $type]);
        send_json(['ok' => true]);
    }

    if ($action === 'pending_reminders' || $action === 'dispatch_pending_reminders') {
        $dispatch = $action === 'dispatch_pending_reminders';
        $stmt = db()->query("SELECT a.id, a.title, a.starts_at, a.status, u.email, u.name FROM appointments a LEFT JOIN users u ON u.id = a.user_id WHERE a.status IN ('requested','confirmed','rescheduled') AND a.created_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND a.starts_at >= NOW() AND a.starts_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR) AND (a.reminder_sent_at IS NULL OR a.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 12 HOUR)) ORDER BY a.starts_at ASC LIMIT 100");
        $pending = [];
        foreach ($stmt->fetchAll() as $row) {
            $pending[] = $row;
            if ($dispatch) {
                operations_create_notification(null, 'admin', 'Közelgő időpont emlékeztető', 'Időpont #' . (int) $row['id'] . ' - ' . ((string) $row['title'] ?: 'Nincs cím'), '/admin#appointments');
                if (filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    $subject = 'Időpont emlékeztető';
                    $html = '<p>Tisztelt ' . htmlspecialchars((string) ($row['name'] ?? 'Ügyfelünk'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '!</p>'
                        . '<p>Emlékeztető: időpontja ' . htmlspecialchars(date('Y-m-d H:i', strtotime((string) $row['starts_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' időpontra van rögzítve.</p>';
                    operations_mail_safely((string) $row['email'], (string) ($row['name'] ?? ''), $subject, $html);
                }

                $mark = db()->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?');
                $mark->execute([(int) $row['id']]);
            }
        }
        send_json(['ok' => true, 'pending' => $pending, 'dispatched' => $dispatch]);
    }

    send_json(['ok' => false, 'error' => 'Nem támogatott időpont művelet.'], 405);
}

function list_service_endpoint(array $user): void
{
    $isAdmin = is_operations_admin($user);
    $id = (int) ($_GET['id'] ?? 0);

    if ($id > 0) {
        $where = 'st.id = ?';
        $args = [$id];
        if (!$isAdmin) {
            $where .= ' AND st.user_id = ?';
            $args[] = (int) $user['id'];
        }

        $stmt = db()->prepare('SELECT st.*, u.name AS customer_name, u.email AS customer_email, a.name AS assigned_name, p.title AS project_title, wo.title AS work_order_title FROM service_tickets st LEFT JOIN users u ON u.id = st.user_id LEFT JOIN users a ON a.id = st.assigned_to_user_id LEFT JOIN projects p ON p.id = st.project_id LEFT JOIN work_orders wo ON wo.id = st.work_order_id WHERE ' . $where . ' LIMIT 1');
        $stmt->execute($args);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            send_json(['ok' => false, 'error' => 'Ticket nem található.'], 404);
        }

        $msgStmt = db()->prepare('SELECT stm.id, stm.author_user_id, stm.author_type, stm.message, stm.is_internal, stm.created_at, u.name AS author_name FROM service_ticket_messages stm LEFT JOIN users u ON u.id = stm.author_user_id WHERE stm.ticket_id = ? ' . (!$isAdmin ? 'AND stm.is_internal = 0 ' : '') . ' ORDER BY stm.created_at ASC, stm.id ASC');
        $msgStmt->execute([$id]);
        $historyStmt = db()->prepare('SELECT sth.*, u.name AS actor_name FROM service_ticket_history sth LEFT JOIN users u ON u.id = sth.actor_user_id WHERE sth.ticket_id = ? ORDER BY sth.created_at DESC, sth.id DESC');
        $historyStmt->execute([$id]);

        send_json(['ok' => true, 'ticket' => $ticket, 'messages' => $msgStmt->fetchAll(), 'history' => $historyStmt->fetchAll()]);
    }

    $where = [];
    $args = [];
    if (!$isAdmin) {
        $where[] = 'st.user_id = ?';
        $args[] = (int) $user['id'];
    }

    $status = clean_string($_GET['status'] ?? '', 30);
    $priority = clean_string($_GET['priority'] ?? '', 20);
    $search = clean_string($_GET['search'] ?? '', 140);

    if ($status !== '' && $status !== 'all') {
        require_enum_value($status, service_ticket_statuses(), 'ticket státusz');
        $where[] = 'st.status = ?';
        $args[] = $status;
    }
    if ($priority !== '' && $priority !== 'all') {
        require_enum_value($priority, service_ticket_priorities(), 'ticket prioritás');
        $where[] = 'st.priority = ?';
        $args[] = $priority;
    }
    if ($search !== '') {
        $where[] = '(st.subject LIKE ? OR st.description LIKE ? OR st.location LIKE ?)';
        $like = '%' . $search . '%';
        array_push($args, $like, $like, $like);
    }

    $sql = 'SELECT st.id, st.user_id, st.project_id, st.work_order_id, st.subject, st.description, st.location, st.priority, st.status, st.warranty_active, st.warranty_start, st.warranty_end, st.installation_reference, st.assigned_to_user_id, st.sla_due_at, st.follow_up_at, st.reopened_until, st.closed_at, st.created_by_customer, st.created_at, st.updated_at,
                   u.name AS customer_name, u.email AS customer_email, a.name AS assigned_name,
                   (SELECT COUNT(*) FROM service_ticket_messages stm WHERE stm.ticket_id = st.id AND stm.is_internal = 0) AS message_count
            FROM service_tickets st
            LEFT JOIN users u ON u.id = st.user_id
            LEFT JOIN users a ON a.id = st.assigned_to_user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY FIELD(st.priority, 'emergency','high','normal','low'), st.updated_at DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    send_json(['ok' => true, 'tickets' => $stmt->fetchAll()]);
}

function post_service_endpoint(array $user, array $payload): void
{
    $isAdmin = is_operations_admin($user);
    $action = clean_string($payload['action'] ?? '', 50);

    if ($action === 'create_ticket') {
        enforce_rate_limit('service_create_ticket', 20, 3600);
        $subject = clean_string($payload['subject'] ?? '', 180);
        $description = clean_string($payload['description'] ?? '', 10000);
        $location = clean_string($payload['location'] ?? '', 255);
        $priority = clean_string($payload['priority'] ?? 'normal', 20);
        $status = clean_string($payload['status'] ?? 'open', 30);
        $userId = parse_int_nullable($payload['user_id'] ?? null);
        $projectId = parse_int_nullable($payload['project_id'] ?? null);
        $workOrderId = parse_int_nullable($payload['work_order_id'] ?? null);
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $warrantyActive = !empty($payload['warranty_active']) ? 1 : 0;
        $warrantyStart = parse_date_nullable($payload['warranty_start'] ?? null);
        $warrantyEnd = parse_date_nullable($payload['warranty_end'] ?? null);
        $installationRef = clean_string($payload['installation_reference'] ?? '', 190);
        $slaDueAt = parse_datetime_nullable($payload['sla_due_at'] ?? null);
        $followUpAt = parse_datetime_nullable($payload['follow_up_at'] ?? null);
        $reopenedUntil = parse_datetime_nullable($payload['reopened_until'] ?? null);

        if ($subject === '' || $description === '') {
            send_json(['ok' => false, 'error' => 'Ticket tárgy és leírás kötelező.'], 422);
        }
        require_enum_value($priority, service_ticket_priorities(), 'ticket prioritás');
        require_enum_value($status, service_ticket_statuses(), 'ticket státusz');

        if (!$isAdmin) {
            $userId = (int) $user['id'];
            $status = 'open';
            $assignedTo = null;
        }

        $stmt = db()->prepare('INSERT INTO service_tickets (user_id, project_id, work_order_id, subject, description, location, priority, status, warranty_active, warranty_start, warranty_end, installation_reference, assigned_to_user_id, sla_due_at, follow_up_at, reopened_until, closed_at, created_by_customer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $closedAt = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$userId, $projectId, $workOrderId, $subject, $description, $location !== '' ? $location : null, $priority, $status, $warrantyActive, $warrantyStart, $warrantyEnd, $installationRef !== '' ? $installationRef : null, $assignedTo, $slaDueAt, $followUpAt, $reopenedUntil, $closedAt, $isAdmin ? 0 : 1]);
        $ticketId = (int) db()->lastInsertId();

        $msgStmt = db()->prepare('INSERT INTO service_ticket_messages (ticket_id, author_user_id, author_type, message, is_internal) VALUES (?, ?, ?, ?, 0)');
        $msgStmt->execute([$ticketId, (int) $user['id'], $isAdmin ? 'admin' : 'customer', $description]);

        insert_service_ticket_history($ticketId, (int) $user['id'], 'created', null, $status, null);
        operations_create_notification(null, 'admin', 'Új szerviz ticket', '#' . $ticketId . ' - ' . $subject, '/admin#service');

        if ($userId) {
            operations_create_notification((int) $userId, 'user', 'Szerviz ticket rögzítve', 'Ticket #' . $ticketId . ': ' . $subject, '/account#service');
        }

        send_json(['ok' => true, 'id' => $ticketId], 201);
    }

    if ($action === 'update_ticket') {
        if (!$isAdmin) {
            send_json(['ok' => false, 'error' => 'Nincs jogosultság ticket admin módosításra.'], 403);
        }
        $ticketId = (int) ($payload['id'] ?? 0);
        if ($ticketId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ticket azonosító.'], 422);
        }

        $status = clean_string($payload['status'] ?? '', 30);
        $priority = clean_string($payload['priority'] ?? '', 20);
        $assignedTo = parse_int_nullable($payload['assigned_to_user_id'] ?? null);
        $followUpAt = array_key_exists('follow_up_at', $payload) ? parse_datetime_nullable($payload['follow_up_at']) : null;
        $slaDueAt = array_key_exists('sla_due_at', $payload) ? parse_datetime_nullable($payload['sla_due_at']) : null;
        $note = clean_string($payload['note'] ?? '', 500);

        $stmt = db()->prepare('SELECT id, user_id, status, priority, assigned_to_user_id FROM service_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            send_json(['ok' => false, 'error' => 'Ticket nem található.'], 404);
        }

        $changes = [];
        $args = [];
        if ($status !== '') {
            require_enum_value($status, service_ticket_statuses(), 'ticket státusz');
            $changes[] = 'status = ?';
            $args[] = $status;
            $changes[] = 'closed_at = ?';
            $args[] = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null;
        }
        if ($priority !== '') {
            require_enum_value($priority, service_ticket_priorities(), 'ticket prioritás');
            $changes[] = 'priority = ?';
            $args[] = $priority;
        }
        if (array_key_exists('assigned_to_user_id', $payload)) {
            $changes[] = 'assigned_to_user_id = ?';
            $args[] = $assignedTo;
        }
        if (array_key_exists('follow_up_at', $payload)) {
            $changes[] = 'follow_up_at = ?';
            $args[] = $followUpAt;
        }
        if (array_key_exists('sla_due_at', $payload)) {
            $changes[] = 'sla_due_at = ?';
            $args[] = $slaDueAt;
        }
        if (!$changes) {
            send_json(['ok' => false, 'error' => 'Nincs módosítandó mező.'], 422);
        }

        $sql = 'UPDATE service_tickets SET ' . implode(', ', $changes) . ' WHERE id = ?';
        $args[] = $ticketId;
        $update = db()->prepare($sql);
        $update->execute($args);

        if ($status !== '' && $status !== (string) $ticket['status']) {
            insert_service_ticket_history($ticketId, (int) $user['id'], 'status_change', (string) $ticket['status'], $status, $note !== '' ? $note : null);
        }
        if (array_key_exists('assigned_to_user_id', $payload) && (int) $ticket['assigned_to_user_id'] !== (int) ($assignedTo ?? 0)) {
            insert_service_ticket_history($ticketId, (int) $user['id'], 'assignment_change', (string) $ticket['assigned_to_user_id'], (string) ($assignedTo ?? ''), $note !== '' ? $note : null);
        }

        if ((int) ($ticket['user_id'] ?? 0) > 0) {
            operations_create_notification((int) $ticket['user_id'], 'user', 'Szerviz ticket frissítve', 'Ticket #' . $ticketId . ' állapota frissült.', '/account#service');
        }

        send_json(['ok' => true]);
    }

    if ($action === 'add_message') {
        enforce_rate_limit('service_add_message', 60, 3600);
        $ticketId = (int) ($payload['ticket_id'] ?? 0);
        $message = clean_string($payload['message'] ?? '', 12000);
        $isInternal = !empty($payload['is_internal']) ? 1 : 0;
        if ($ticketId <= 0 || $message === '') {
            send_json(['ok' => false, 'error' => 'Ticket és üzenet kötelező.'], 422);
        }

        $stmt = db()->prepare('SELECT id, user_id, status FROM service_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            send_json(['ok' => false, 'error' => 'Ticket nem található.'], 404);
        }

        if (!$isAdmin && (int) ($ticket['user_id'] ?? 0) !== (int) $user['id']) {
            send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a tickethez.'], 403);
        }
        if (!$isAdmin) {
            $isInternal = 0;
        }

        $insert = db()->prepare('INSERT INTO service_ticket_messages (ticket_id, author_user_id, author_type, message, is_internal) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$ticketId, (int) $user['id'], $isAdmin ? 'admin' : 'customer', $message, $isInternal]);

        $targetAudience = $isAdmin ? 'user' : 'admin';
        operations_create_notification($isAdmin ? (int) ($ticket['user_id'] ?? 0) : null, $targetAudience, 'Új ticket üzenet', 'Ticket #' . $ticketId . ' új üzenetet kapott.', $isAdmin ? '/account#service' : '/admin#service');

        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    if ($action === 'reopen_ticket') {
        $ticketId = (int) ($payload['id'] ?? 0);
        if ($ticketId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ticket azonosító.'], 422);
        }

        $stmt = db()->prepare('SELECT id, user_id, status, reopened_until FROM service_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            send_json(['ok' => false, 'error' => 'Ticket nem található.'], 404);
        }

        $isOwner = (int) ($ticket['user_id'] ?? 0) === (int) $user['id'];
        if (!$isAdmin && !$isOwner) {
            send_json(['ok' => false, 'error' => 'Nincs jogosultsága a ticket újranyitására.'], 403);
        }

        if (!$isAdmin) {
            $window = $ticket['reopened_until'] ? strtotime((string) $ticket['reopened_until']) : false;
            if ($window === false || $window < time()) {
                send_json(['ok' => false, 'error' => 'A ticket újranyitási ablaka lejárt.'], 422);
            }
        }
        if (!in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed'], true)) {
            send_json(['ok' => false, 'error' => 'Csak resolved vagy closed ticket nyitható újra.'], 422);
        }

        $update = db()->prepare('UPDATE service_tickets SET status = ?, closed_at = NULL, reopened_until = NULL WHERE id = ?');
        $update->execute(['open', $ticketId]);
        insert_service_ticket_history($ticketId, (int) $user['id'], 'reopened', (string) $ticket['status'], 'open', null);
        operations_create_notification(null, 'admin', 'Ticket újranyitva', 'Ticket #' . $ticketId . ' újranyitva.', '/admin#service');
        send_json(['ok' => true]);
    }

    send_json(['ok' => false, 'error' => 'Nem támogatott szerviz művelet.'], 405);
}

function list_files_endpoint(array $user): void
{
    $isAdmin = is_operations_admin($user);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $workOrderId = (int) ($_GET['work_order_id'] ?? 0);
    $category = clean_string($_GET['category'] ?? '', 20);

    $where = [];
    $args = [];
    if ($projectId > 0) {
        $where[] = 'pf.project_id = ?';
        $args[] = $projectId;
    }
    if ($workOrderId > 0) {
        $where[] = 'pf.work_order_id = ?';
        $args[] = $workOrderId;
    }
    if ($category !== '' && $category !== 'all') {
        require_enum_value($category, ['before', 'during', 'after', 'issue', 'document'], 'fájl kategória');
        $where[] = 'pf.category = ?';
        $args[] = $category;
    }

    if (!$isAdmin) {
        $uid = (int) $user['id'];
        $role = (string) ($user['role'] ?? 'user');
        if ($role === 'field_worker') {
            $where[] = '(pf.user_id = ? OR p.user_id = ? OR wo.user_id = ? OR pwo.user_id = ? OR wo.assigned_to_user_id = ?)';
            array_push($args, $uid, $uid, $uid, $uid, $uid);
        } else {
            $where[] = '(pf.user_id = ? OR p.user_id = ? OR wo.user_id = ? OR pwo.user_id = ?)';
            array_push($args, $uid, $uid, $uid, $uid);
        }
    }

    $sql = 'SELECT pf.id, pf.project_id, pf.work_order_id, pf.user_id, pf.category, pf.original_name, pf.storage_path, pf.mime_type, pf.file_size, pf.title, pf.description, pf.uploaded_by_user_id, pf.created_at,
                   p.title AS project_title, wo.title AS work_order_title, u.name AS uploader_name
            FROM project_files pf
            LEFT JOIN projects p ON p.id = pf.project_id
            LEFT JOIN work_orders wo ON wo.id = pf.work_order_id
            LEFT JOIN projects pwo ON pwo.id = wo.project_id
            LEFT JOIN users u ON u.id = pf.uploaded_by_user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY pf.created_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    send_json(['ok' => true, 'files' => $stmt->fetchAll()]);
}

function post_files_endpoint(array $user, array $payload): void
{
    $user = require_operations_backoffice();
    $action = clean_string($payload['action'] ?? ($_POST['action'] ?? 'upload'), 40);

    if ($action !== 'upload') {
        send_json(['ok' => false, 'error' => 'Nem támogatott fájl művelet.'], 405);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        send_json(['ok' => false, 'error' => 'Hiányzó fájl feltöltés.'], 422);
    }

    $file = $_FILES['file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        send_json(['ok' => false, 'error' => 'A fájl feltöltése sikertelen.'], 422);
    }

    $maxSize = 10 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        send_json(['ok' => false, 'error' => 'A fájl mérete nem megfelelő (max 10 MB).'], 422);
    }

    $category = clean_string($_POST['category'] ?? 'document', 20);
    require_enum_value($category, ['before', 'during', 'after', 'issue', 'document'], 'fájl kategória');

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];

    $originalName = clean_string((string) ($file['name'] ?? 'file'), 255);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        send_json(['ok' => false, 'error' => 'Nem engedélyezett fájl kiterjesztés.'], 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    if (!in_array($mime, $allowedMime, true)) {
        send_json(['ok' => false, 'error' => 'Nem engedélyezett MIME típus.'], 422);
    }

    $projectId = parse_int_nullable($_POST['project_id'] ?? null);
    $workOrderId = parse_int_nullable($_POST['work_order_id'] ?? null);
    $userId = parse_int_nullable($_POST['user_id'] ?? null);
    $title = clean_string($_POST['title'] ?? '', 180);
    $description = clean_string($_POST['description'] ?? '', 3000);

    $baseDir = dirname(__DIR__) . '/uploads/project-files/' . date('Y/m');
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        send_json(['ok' => false, 'error' => 'A célmappa nem hozható létre.'], 500);
    }

    $htaccessContent = "Options -Indexes\nphp_flag engine off\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n  Deny from all\n</FilesMatch>\n";
    $htaccessRootPath = dirname(__DIR__) . '/uploads/project-files/.htaccess';
    if (!is_file($htaccessRootPath)) {
        @file_put_contents($htaccessRootPath, $htaccessContent);
    }
    $htaccessPath = $baseDir . '/.htaccess';
    if (!is_file($htaccessPath)) {
        @file_put_contents($htaccessPath, $htaccessContent);
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetAbs = $baseDir . '/' . $safeName;
    if (!move_uploaded_file($tmpPath, $targetAbs)) {
        send_json(['ok' => false, 'error' => 'A fájl mentése sikertelen.'], 500);
    }

    $relativePath = 'uploads/project-files/' . date('Y/m') . '/' . $safeName;
    $stmt = db()->prepare('INSERT INTO project_files (project_id, work_order_id, user_id, category, original_name, storage_path, mime_type, file_size, title, description, uploaded_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$projectId, $workOrderId, $userId, $category, $originalName, $relativePath, $mime, $size, $title !== '' ? $title : null, $description !== '' ? $description : null, (int) $user['id']]);
    $id = (int) db()->lastInsertId();

    if ($projectId) {
        insert_project_timeline($projectId, (int) $user['id'], in_array($category, ['before', 'during', 'after', 'issue'], true) ? 'photo' : 'document', $title !== '' ? $title : $originalName, 'project_file', $id);
    }

    log_admin_activity((int) $user['id'], 'project_file_uploaded', 'project_file', $id, ['category' => $category, 'mime' => $mime, 'size' => $size]);
    send_json(['ok' => true, 'id' => $id, 'path' => $relativePath], 201);
}

function list_dashboard_endpoint(): void
{
    require_operations_backoffice();

    $counts = [
        'new_leads' => (int) db()->query("SELECT COUNT(*) AS c FROM leads WHERE status = 'new'")->fetch()['c'],
        'open_quotes' => (int) db()->query("SELECT COUNT(*) AS c FROM crm_quotes WHERE status IN ('draft','sent','viewed')")->fetch()['c'],
        'due_followups' => (int) db()->query("SELECT COUNT(*) AS c FROM leads WHERE next_follow_up_at IS NOT NULL AND next_follow_up_at <= NOW() AND status NOT IN ('won','lost','archived')")->fetch()['c'],
        'active_projects' => (int) db()->query("SELECT COUNT(*) AS c FROM projects WHERE status IN ('approved','scheduled','in_progress','on_hold')")->fetch()['c'],
        'urgent_open_tickets' => (int) db()->query("SELECT COUNT(*) AS c FROM service_tickets WHERE status NOT IN ('resolved','closed','rejected') AND priority IN ('high','emergency')")->fetch()['c'],
        'today_appointments' => (int) db()->query("SELECT COUNT(*) AS c FROM appointments WHERE DATE(starts_at) = CURDATE() AND status IN ('requested','confirmed','rescheduled')")->fetch()['c'],
    ];

    $report = [
        'lead_conversion_rate' => (float) db()->query("SELECT IFNULL(ROUND((SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 2), 0) AS value FROM leads")->fetch()['value'],
        'quote_acceptance_rate' => (float) db()->query("SELECT IFNULL(ROUND((SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 2), 0) AS value FROM crm_quotes")->fetch()['value'],
        'avg_project_cycle_days' => (float) db()->query("SELECT IFNULL(ROUND(AVG(TIMESTAMPDIFF(DAY, created_at, updated_at)), 2), 0) AS value FROM projects WHERE status IN ('completed','cancelled')")->fetch()['value'],
        'avg_ticket_resolution_hours' => (float) db()->query("SELECT IFNULL(ROUND(AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, updated_at))), 2), 0) AS value FROM service_tickets WHERE status IN ('resolved','closed')")->fetch()['value'],
    ];

    send_json(['ok' => true, 'kpi' => $counts, 'reports' => $report]);
}

function list_notifications_endpoint(array $user): void
{
    $audience = is_operations_admin($user) ? 'admin' : 'user';
    $stmt = db()->prepare('SELECT id, user_id, audience, title, message, link_url, is_read, created_at, read_at FROM in_app_notifications WHERE audience = ? AND (user_id IS NULL OR user_id = ?) ORDER BY created_at DESC LIMIT 100');
    $stmt->execute([$audience, (int) $user['id']]);
    $notifications = $stmt->fetchAll();

    $countStmt = db()->prepare('SELECT COUNT(*) AS unread_count FROM in_app_notifications WHERE audience = ? AND (user_id IS NULL OR user_id = ?) AND is_read = 0');
    $countStmt->execute([$audience, (int) $user['id']]);
    $unread = (int) (($countStmt->fetch()['unread_count'] ?? 0));

    send_json(['ok' => true, 'notifications' => $notifications, 'unread_count' => $unread]);
}

function post_notifications_endpoint(array $user, array $payload): void
{
    $id = (int) ($payload['id'] ?? 0);
    if ($id <= 0) {
        send_json(['ok' => false, 'error' => 'Érvénytelen értesítés azonosító.'], 422);
    }

    $audience = is_operations_admin($user) ? 'admin' : 'user';
    $stmt = db()->prepare('UPDATE in_app_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND audience = ? AND (user_id IS NULL OR user_id = ?)');
    $stmt->execute([$id, $audience, (int) $user['id']]);
    send_json(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$module = clean_string($_GET['module'] ?? ($payload['module'] ?? ''), 40);
$user = require_login();

if ($method === 'POST') {
    validate_csrf_token();
}

if ($module === '') {
    send_json(['ok' => false, 'error' => 'Hiányzó modul paraméter.'], 422);
}

if ($method === 'GET') {
    if ($module === 'crm') {
        list_crm($user);
    }
    if ($module === 'projects') {
        list_projects_endpoint($user);
    }
    if ($module === 'work_orders') {
        list_work_orders_endpoint($user);
    }
    if ($module === 'appointments') {
        list_appointments_endpoint($user);
    }
    if ($module === 'service') {
        list_service_endpoint($user);
    }
    if ($module === 'files') {
        list_files_endpoint($user);
    }
    if ($module === 'dashboard') {
        list_dashboard_endpoint();
    }
    if ($module === 'notifications') {
        list_notifications_endpoint($user);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott modul.'], 405);
}

if ($method === 'POST') {
    if ($module === 'crm') {
        post_crm($user, $payload);
    }
    if ($module === 'projects') {
        post_projects_endpoint($payload);
    }
    if ($module === 'work_orders') {
        post_work_orders_endpoint($payload);
    }
    if ($module === 'appointments') {
        post_appointments_endpoint($payload);
    }
    if ($module === 'service') {
        post_service_endpoint($user, $payload);
    }
    if ($module === 'files') {
        post_files_endpoint($user, $payload);
    }
    if ($module === 'notifications') {
        post_notifications_endpoint($user, $payload);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott modul.'], 405);
}

send_json(['ok' => false, 'error' => 'Nem támogatott kérés.'], 405);
