<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function platform_admin_roles(): array
{
    return ['admin', 'superadmin', 'project_manager', 'service_agent', 'support_agent', 'quote_manager'];
}

function platform_worker_roles(): array
{
    return ['admin', 'superadmin', 'project_manager', 'service_agent', 'support_agent', 'quote_manager', 'field_worker'];
}

function platform_is_admin(array $user): bool
{
    return in_array((string) ($user['role'] ?? 'user'), platform_admin_roles(), true);
}

function platform_require_roles(array $allowed): array
{
    $user = require_login();
    if (!in_array((string) ($user['role'] ?? 'user'), $allowed, true)) {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a művelethez.'], 403);
    }
    return $user;
}

function platform_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $salt = (string) env_or_fallback(['TB_IP_HASH_SALT', 'TB_APP_NAME'], 'tamasbau');
    return hash('sha256', $salt . '|' . $ip);
}

function platform_enum(string $value, array $allowed, string $label): string
{
    if (!in_array($value, $allowed, true)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen ' . $label . '.'], 422);
    }

    function platform_create_notification(?int $userId, string $audience, string $title, ?string $message = null, ?string $link = null): void
    {
        try {
            $stmt = db()->prepare('INSERT INTO in_app_notifications (user_id, audience, title, message, link_url) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $audience, clean_string($title, 160), $message !== null ? clean_string($message, 1000) : null, $link !== null ? clean_string($link, 500) : null]);
        } catch (Throwable $e) {
        }
    }
    return $value;
}

function platform_datetime_nullable($value): ?string
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    if ($ts === false) {
        send_json(['ok' => false, 'error' => 'Érvénytelen dátum/idő formátum.'], 422);
    }
    return date('Y-m-d H:i:s', $ts);
}

function platform_int_nullable($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $v = (int) $value;
    return $v > 0 ? $v : null;
}

function platform_store_idempotency(string $key, int $workOrderId, int $userId, string $status, ?array $payload = null, ?array $snapshot = null): void
{
    $stmt = db()->prepare('INSERT INTO work_order_sync_operations (idempotency_key, work_order_id, actor_user_id, operation_type, payload_json, operation_status, conflict_snapshot, applied_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $key,
        $workOrderId,
        $userId,
        'mobile_update',
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $status,
        $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $status === 'applied' ? date('Y-m-d H:i:s') : null,
    ]);
}

function platform_totp_secret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $random = random_bytes($length);
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[ord($random[$i]) % strlen($alphabet)];
    }
    return $secret;
}

function platform_totp_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
    foreach (str_split($secret) as $char) {
        $val = strpos($alphabet, $char);
        if ($val === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $output;
}

function platform_totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D+/', '', $code ?? '') ?? '';
    if (strlen($code) !== 6) {
        return false;
    }
    $secretBin = platform_totp_decode($secret);
    if ($secretBin === '') {
        return false;
    }
    $time = (int) floor(time() / 30);
    for ($offset = -$window; $offset <= $window; $offset++) {
        $counter = pack('N*', 0) . pack('N*', $time + $offset);
        $hash = hash_hmac('sha1', $counter, $secretBin, true);
        $pos = ord(substr($hash, -1)) & 0x0F;
        $truncated = ((ord($hash[$pos]) & 0x7F) << 24)
            | ((ord($hash[$pos + 1]) & 0xFF) << 16)
            | ((ord($hash[$pos + 2]) & 0xFF) << 8)
            | (ord($hash[$pos + 3]) & 0xFF);
        $otp = str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
        if (hash_equals($otp, $code)) {
            return true;
        }
    }
    return false;
}

function platform_issue_recovery_codes(int $userId): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $plain = strtoupper(bin2hex(random_bytes(4)));
        $codes[] = $plain;
        $stmt = db()->prepare('INSERT INTO user_recovery_codes (user_id, code_hash, is_used) VALUES (?, ?, 0)');
        $stmt->execute([$userId, hash('sha256', $plain)]);
    }
    return $codes;
}

function platform_list_mobile(array $user): void
{
    $where = [];
    $args = [];
    if (!platform_is_admin($user)) {
        $where[] = '(wo.assigned_to_user_id = ? OR wo.user_id = ?)';
        $args[] = (int) $user['id'];
        $args[] = (int) $user['id'];
    }
    $status = clean_string($_GET['status'] ?? '', 20);
    if ($status !== '' && $status !== 'all') {
        platform_enum($status, ['todo', 'in_progress', 'blocked', 'done', 'cancelled'], 'munkalap státusz');
        $where[] = 'wo.status = ?';
        $args[] = $status;
    }
    $sql = 'SELECT wo.id, wo.project_id, wo.title, wo.location, wo.description, wo.status, wo.priority, wo.actual_minutes, wo.updated_at, wo.assigned_to_user_id, p.title AS project_title
            FROM work_orders wo
            LEFT JOIN projects p ON p.id = wo.project_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY wo.updated_at DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    send_json(['ok' => true, 'work_orders' => $stmt->fetchAll()]);
}

function platform_post_mobile(array $user, array $payload): void
{
    $action = clean_string($payload['action'] ?? '', 60);
    if ($action !== 'sync_work_order') {
        send_json(['ok' => false, 'error' => 'Nem támogatott mobil művelet.'], 405);
    }
    $id = (int) ($payload['id'] ?? 0);
    $expectedUpdatedAt = clean_string($payload['expected_updated_at'] ?? '', 30);
    $idempotencyKey = clean_string($payload['idempotency_key'] ?? '', 120);
    if ($id <= 0 || $idempotencyKey === '') {
        send_json(['ok' => false, 'error' => 'Munkalap azonosító és idempotency key kötelező.'], 422);
    }

    $seen = db()->prepare('SELECT id, operation_status FROM work_order_sync_operations WHERE idempotency_key = ? LIMIT 1');
    $seen->execute([$idempotencyKey]);
    $existing = $seen->fetch();
    if ($existing) {
        send_json(['ok' => true, 'duplicate' => true, 'status' => $existing['operation_status']]);
    }

    $stmt = db()->prepare('SELECT id, user_id, assigned_to_user_id, status, actual_minutes, updated_at FROM work_orders WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        send_json(['ok' => false, 'error' => 'Munkalap nem található.'], 404);
    }

    $uid = (int) $user['id'];
    if (!platform_is_admin($user) && $uid !== (int) ($row['assigned_to_user_id'] ?? 0) && $uid !== (int) ($row['user_id'] ?? 0)) {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a munkalaphoz.'], 403);
    }

    if ($expectedUpdatedAt !== '' && (string) $row['updated_at'] !== $expectedUpdatedAt) {
        platform_store_idempotency($idempotencyKey, $id, $uid, 'conflict', $payload, $row);
        send_json(['ok' => false, 'error' => 'Szinkron ütközés történt. Kérjük frissítse az adatokat.', 'conflict' => $row], 409);
    }

    $status = clean_string($payload['status'] ?? (string) $row['status'], 20);
    platform_enum($status, ['todo', 'in_progress', 'blocked', 'done', 'cancelled'], 'munkalap státusz');
    $actualMinutes = platform_int_nullable($payload['actual_minutes'] ?? $row['actual_minutes']);
    $note = clean_string($payload['note'] ?? '', 2000);
    $handoverReady = !empty($payload['handover_ready']) ? 1 : 0;

    $update = db()->prepare('UPDATE work_orders SET status = ?, actual_minutes = ?, handover_ready = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([$status, $actualMinutes, $handoverReady, $id]);
    if ($note !== '') {
        $timeline = db()->prepare('INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note, related_type, related_id) SELECT project_id, ?, ?, ?, ?, ? FROM work_orders WHERE id = ?');
        $timeline->execute([$uid, 'note', '[Mobil] ' . $note, 'work_order', $id, $id]);
    }
    platform_store_idempotency($idempotencyKey, $id, $uid, 'applied', $payload);
    log_admin_activity($uid, 'mobile_work_order_sync', 'work_order', $id, ['status' => $status, 'actual_minutes' => $actualMinutes]);
    send_json(['ok' => true, 'id' => $id]);
}

function platform_list_signatures(array $user): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT id, document_type, document_id, document_version, signer_name, signer_email, declaration_text, signature_svg, status, revoked_reason, signed_at, revoked_at, created_at FROM digital_signatures WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Aláírás nem található.'], 404);
        }
        $printable = '<html lang="hu"><meta charset="utf-8"><body><h2>Digitális aláírás</h2><p>Dokumentum: ' . htmlspecialchars($row['document_type'] . '#' . $row['document_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p>Aláíró: ' . htmlspecialchars($row['signer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (' . htmlspecialchars($row['signer_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</p><p>Időbélyeg: ' . htmlspecialchars((string) $row['signed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p>Nyilatkozat: ' . nl2br(htmlspecialchars((string) $row['declaration_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p><hr>' . $row['signature_svg'] . '</body></html>';
        send_json(['ok' => true, 'signature' => $row, 'printable_html' => $printable]);
    }

    if (!platform_is_admin($user)) {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága aláírások listázásához.'], 403);
    }
    $stmt = db()->query('SELECT id, document_type, document_id, document_version, signer_name, signer_email, status, signed_at, created_at FROM digital_signatures ORDER BY created_at DESC LIMIT 200');
    send_json(['ok' => true, 'signatures' => $stmt->fetchAll()]);
}

function platform_post_signatures(array $user, array $payload): void
{
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'create') {
        enforce_rate_limit('signature_create', 40, 3600);
        $documentType = clean_string($payload['document_type'] ?? '', 20);
        $documentId = (int) ($payload['document_id'] ?? 0);
        $documentVersion = clean_string($payload['document_version'] ?? '', 80);
        $signerName = clean_string($payload['signer_name'] ?? '', 120);
        $signerEmail = clean_string($payload['signer_email'] ?? '', 190);
        $declarationText = clean_string($payload['declaration_text'] ?? '', 4000);
        $signatureSvg = trim((string) ($payload['signature_svg'] ?? ''));
        $ipCaptureMode = clean_string($payload['ip_capture_mode'] ?? 'hash', 10);
        platform_enum($documentType, ['quote', 'project', 'work_order'], 'dokumentum típus');
        platform_enum($ipCaptureMode, ['hash', 'omitted'], 'IP rögzítési mód');
        if ($documentId <= 0 || $documentVersion === '' || $signerName === '' || !filter_var($signerEmail, FILTER_VALIDATE_EMAIL) || $declarationText === '' || $signatureSvg === '') {
            send_json(['ok' => false, 'error' => 'Hiányos aláírás adatok.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO digital_signatures (document_type, document_id, document_version, signer_name, signer_email, declaration_text, signature_svg, signer_ip_hash, ip_capture_mode, status, signed_at, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
        $stmt->execute([
            $documentType,
            $documentId,
            $documentVersion,
            $signerName,
            $signerEmail,
            $declarationText,
            $signatureSvg,
            $ipCaptureMode === 'hash' ? platform_ip_hash() : null,
            $ipCaptureMode,
            'active',
            (int) $user['id'],
        ]);
        $newId = (int) db()->lastInsertId();
        log_admin_activity((int) $user['id'], 'digital_signature_created', $documentType, $documentId, ['signature_id' => $newId]);
        send_json(['ok' => true, 'id' => $newId], 201);
    }

    if ($action === 'revoke') {
        $admin = platform_require_roles(platform_admin_roles());
        $id = (int) ($payload['id'] ?? 0);
        $reason = clean_string($payload['reason'] ?? '', 255);
        if ($id <= 0 || $reason === '') {
            send_json(['ok' => false, 'error' => 'Aláírás azonosító és indoklás kötelező.'], 422);
        }
        $stmt = db()->prepare('UPDATE digital_signatures SET status = ?, revoked_reason = ?, revoked_at = NOW() WHERE id = ?');
        $stmt->execute(['revoked', $reason, $id]);
        log_admin_activity((int) $admin['id'], 'digital_signature_revoked', 'digital_signature', $id, ['reason' => $reason]);
        send_json(['ok' => true]);
    }

    send_json(['ok' => false, 'error' => 'Nem támogatott aláírás művelet.'], 405);
}

function platform_list_costs(array $user): void
{
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $workOrderId = (int) ($_GET['work_order_id'] ?? 0);
    $where = [];
    $args = [];
    if ($projectId > 0) {
        $where[] = 'ce.project_id = ?';
        $args[] = $projectId;
    }
    if ($workOrderId > 0) {
        $where[] = 'ce.work_order_id = ?';
        $args[] = $workOrderId;
    }
    $sql = 'SELECT ce.id, ce.project_id, ce.work_order_id, ce.entry_type, ce.title, ce.quantity, ce.unit, ce.unit_price_cents, ce.internal_unit_cost_cents, ce.planned_quantity, ce.planned_unit_price_cents, ce.travel_km, ce.note, ce.billable_to_customer, ce.created_at, ce.updated_at
            FROM work_order_cost_entries ce';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ce.created_at DESC LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $entries = $stmt->fetchAll();

    $sumSql = 'SELECT
        COALESCE(SUM(quantity * unit_price_cents), 0) AS actual_revenue_cents,
        COALESCE(SUM(COALESCE(planned_quantity, quantity) * COALESCE(planned_unit_price_cents, unit_price_cents)), 0) AS planned_revenue_cents,
        COALESCE(SUM(quantity * COALESCE(internal_unit_cost_cents, unit_price_cents)), 0) AS actual_cost_cents,
        COALESCE(SUM(COALESCE(planned_quantity, quantity) * COALESCE(internal_unit_cost_cents, planned_unit_price_cents, unit_price_cents)), 0) AS planned_cost_cents
        FROM work_order_cost_entries';
    if ($where) {
        $sumSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sumStmt = db()->prepare($sumSql);
    $sumStmt->execute($args);
    $sum = $sumStmt->fetch() ?: [];
    $plannedRevenue = (int) ($sum['planned_revenue_cents'] ?? 0);
    $actualRevenue = (int) ($sum['actual_revenue_cents'] ?? 0);
    $plannedCost = (int) ($sum['planned_cost_cents'] ?? 0);
    $actualCost = (int) ($sum['actual_cost_cents'] ?? 0);
    $summary = [
        'planned_revenue_cents' => $plannedRevenue,
        'actual_revenue_cents' => $actualRevenue,
        'planned_cost_cents' => $plannedCost,
        'actual_cost_cents' => $actualCost,
        'planned_margin_cents' => $plannedRevenue - $plannedCost,
        'actual_margin_cents' => $actualRevenue - $actualCost,
    ];
    if (!platform_is_admin($user)) {
        foreach ($entries as &$entry) {
            unset($entry['internal_unit_cost_cents']);
        }
        unset($entry);
        unset($summary['planned_margin_cents'], $summary['actual_margin_cents'], $summary['planned_cost_cents'], $summary['actual_cost_cents']);
    }
    send_json(['ok' => true, 'entries' => $entries, 'summary' => $summary]);
}

function platform_post_costs(array $user, array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'add' || $action === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        $projectId = platform_int_nullable($payload['project_id'] ?? null);
        $workOrderId = platform_int_nullable($payload['work_order_id'] ?? null);
        $entryType = clean_string($payload['entry_type'] ?? '', 20);
        $title = clean_string($payload['title'] ?? '', 180);
        $quantity = (float) ($payload['quantity'] ?? 1);
        $unit = clean_string($payload['unit'] ?? '', 20);
        $unitPrice = max(0, (int) ($payload['unit_price_cents'] ?? 0));
        $internalCost = array_key_exists('internal_unit_cost_cents', $payload) ? max(0, (int) $payload['internal_unit_cost_cents']) : null;
        $plannedQuantity = array_key_exists('planned_quantity', $payload) ? (float) $payload['planned_quantity'] : null;
        $plannedUnitPrice = array_key_exists('planned_unit_price_cents', $payload) ? max(0, (int) $payload['planned_unit_price_cents']) : null;
        $travelKm = array_key_exists('travel_km', $payload) ? (float) $payload['travel_km'] : null;
        $note = clean_string($payload['note'] ?? '', 2000);
        $billable = !empty($payload['billable_to_customer']) ? 1 : 0;
        platform_enum($entryType, ['material', 'labor', 'travel', 'external'], 'költség típus');
        if ($title === '' || ($projectId === null && $workOrderId === null)) {
            send_json(['ok' => false, 'error' => 'Cím és projekt/munkalap kapcsolat kötelező.'], 422);
        }
        if ($action === 'add') {
            $stmt = db()->prepare('INSERT INTO work_order_cost_entries (project_id, work_order_id, entry_type, title, quantity, unit, unit_price_cents, internal_unit_cost_cents, planned_quantity, planned_unit_price_cents, travel_km, note, billable_to_customer, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$projectId, $workOrderId, $entryType, $title, $quantity, $unit !== '' ? $unit : null, $unitPrice, $internalCost, $plannedQuantity, $plannedUnitPrice, $travelKm, $note !== '' ? $note : null, $billable, (int) $admin['id']]);
            send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
        }
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen költség azonosító.'], 422);
        }
        $stmt = db()->prepare('UPDATE work_order_cost_entries SET project_id = ?, work_order_id = ?, entry_type = ?, title = ?, quantity = ?, unit = ?, unit_price_cents = ?, internal_unit_cost_cents = ?, planned_quantity = ?, planned_unit_price_cents = ?, travel_km = ?, note = ?, billable_to_customer = ? WHERE id = ?');
        $stmt->execute([$projectId, $workOrderId, $entryType, $title, $quantity, $unit !== '' ? $unit : null, $unitPrice, $internalCost, $plannedQuantity, $plannedUnitPrice, $travelKm, $note !== '' ? $note : null, $billable, $id]);
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen költség azonosító.'], 422);
        }
        $stmt = db()->prepare('DELETE FROM work_order_cost_entries WHERE id = ?');
        $stmt->execute([$id]);
        send_json(['ok' => true]);
    }
    if ($action === 'export_csv') {
        $projectId = (int) ($payload['project_id'] ?? 0);
        $workOrderId = (int) ($payload['work_order_id'] ?? 0);
        $where = [];
        $args = [];
        if ($projectId > 0) {
            $where[] = 'project_id = ?';
            $args[] = $projectId;
        }
        if ($workOrderId > 0) {
            $where[] = 'work_order_id = ?';
            $args[] = $workOrderId;
        }
        $sql = 'SELECT id, entry_type, title, quantity, unit, unit_price_cents, planned_quantity, planned_unit_price_cents, travel_km, created_at FROM work_order_cost_entries';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $csv = "id;entry_type;title;quantity;unit;unit_price_cents;planned_quantity;planned_unit_price_cents;travel_km;created_at\n";
        foreach ($rows as $r) {
            $csv .= implode(';', [
                (string) $r['id'],
                (string) $r['entry_type'],
                str_replace(';', ',', (string) $r['title']),
                (string) $r['quantity'],
                (string) ($r['unit'] ?? ''),
                (string) $r['unit_price_cents'],
                (string) ($r['planned_quantity'] ?? ''),
                (string) ($r['planned_unit_price_cents'] ?? ''),
                (string) ($r['travel_km'] ?? ''),
                (string) $r['created_at'],
            ]) . "\n";
        }
        send_json(['ok' => true, 'csv' => $csv, 'row_count' => count($rows)]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott költség művelet.'], 405);
}

function platform_list_workflows(array $user): void
{
    platform_require_roles(platform_admin_roles());
    $rules = db()->query('SELECT id, rule_key, title, is_enabled, config_json, created_at, updated_at FROM workflow_rules ORDER BY id ASC')->fetchAll();
    $jobs = db()->query("SELECT id, rule_id, idempotency_key, trigger_type, status, retries, max_retries, last_error, created_at, processed_at FROM workflow_jobs WHERE status IN ('pending','failed') ORDER BY created_at ASC LIMIT 200")->fetchAll();
    send_json(['ok' => true, 'rules' => $rules, 'pending_jobs' => $jobs]);
}

function platform_post_workflows(array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'queue_job') {
        $ruleKey = clean_string($payload['rule_key'] ?? '', 80);
        $idempotencyKey = clean_string($payload['idempotency_key'] ?? '', 120);
        $triggerType = clean_string($payload['trigger_type'] ?? 'manual', 80);
        $eventPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        if ($ruleKey === '' || $idempotencyKey === '') {
            send_json(['ok' => false, 'error' => 'Szabály kulcs és idempotency kulcs kötelező.'], 422);
        }
        $ruleStmt = db()->prepare('SELECT id, is_enabled FROM workflow_rules WHERE rule_key = ? LIMIT 1');
        $ruleStmt->execute([$ruleKey]);
        $rule = $ruleStmt->fetch();
        if (!$rule) {
            send_json(['ok' => false, 'error' => 'Workflow szabály nem található.'], 404);
        }
        $stmt = db()->prepare('INSERT IGNORE INTO workflow_jobs (rule_id, idempotency_key, trigger_type, payload_json, status, retries, max_retries) VALUES (?, ?, ?, ?, ?, 0, 3)');
        $stmt->execute([(int) $rule['id'], $idempotencyKey, $triggerType, json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'pending']);
        send_json(['ok' => true, 'queued' => $stmt->rowCount() > 0]);
    }
    if ($action === 'toggle_rule') {
        $ruleId = (int) ($payload['id'] ?? 0);
        $isEnabled = !empty($payload['is_enabled']) ? 1 : 0;
        if ($ruleId <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen workflow szabály.'], 422);
        }
        $stmt = db()->prepare('UPDATE workflow_rules SET is_enabled = ? WHERE id = ?');
        $stmt->execute([$isEnabled, $ruleId]);
        send_json(['ok' => true]);
    }
    if ($action === 'run_pending') {
        $jobs = db()->query("SELECT j.id, j.rule_id, j.idempotency_key, j.payload_json, r.rule_key, r.title, r.is_enabled FROM workflow_jobs j LEFT JOIN workflow_rules r ON r.id = j.rule_id WHERE j.status = 'pending' ORDER BY j.created_at ASC LIMIT 100")->fetchAll();
        $processed = [];
        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $enabled = (int) ($job['is_enabled'] ?? 0) === 1;
            if (!$enabled) {
                $skip = db()->prepare("UPDATE workflow_jobs SET status = 'failed', retries = retries + 1, last_error = ? WHERE id = ?");
                $skip->execute(['A workflow szabály le van tiltva.', $jobId]);
                $processed[] = ['id' => $jobId, 'status' => 'failed', 'reason' => 'rule_disabled'];
                continue;
            }
            $upd = db()->prepare("UPDATE workflow_jobs SET status = 'done', processed_at = NOW(), last_error = NULL WHERE id = ?");
            $upd->execute([$jobId]);
            $processed[] = ['id' => $jobId, 'status' => 'done', 'rule_key' => $job['rule_key']];
        }
        log_admin_activity((int) $admin['id'], 'workflow_run_pending', 'workflow_jobs', null, ['processed_count' => count($processed)]);
        send_json(['ok' => true, 'processed' => $processed]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott workflow művelet.'], 405);
}

function platform_list_security(array $user): void
{
    $uid = (int) $user['id'];
    $totp = db()->prepare('SELECT user_id, secret_hint, is_enabled, enabled_at, last_verified_at, updated_at FROM user_totp_settings WHERE user_id = ? LIMIT 1');
    $totp->execute([$uid]);
    $sessions = db()->prepare('SELECT id, user_agent, is_revoked, last_seen_at, created_at, revoked_at FROM user_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
    $sessions->execute([$uid]);
    $logins = db()->prepare('SELECT id, is_success, risk_level, created_at, failure_reason FROM user_login_events WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
    $logins->execute([$uid]);
    send_json([
        'ok' => true,
        'totp' => $totp->fetch() ?: ['is_enabled' => 0],
        'sessions' => $sessions->fetchAll(),
        'login_events' => $logins->fetchAll(),
    ]);
}

function platform_post_security(array $user, array $payload): void
{
    $uid = (int) $user['id'];
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'setup_totp') {
        $secret = platform_totp_secret(32);
        $secretHint = substr($secret, 0, 4) . '...' . substr($secret, -4);
        $enc = base64_encode($secret);
        $stmt = db()->prepare('INSERT INTO user_totp_settings (user_id, secret_encrypted, secret_hint, is_enabled, enabled_at, last_verified_at) VALUES (?, ?, ?, 0, NULL, NULL) ON DUPLICATE KEY UPDATE secret_encrypted = VALUES(secret_encrypted), secret_hint = VALUES(secret_hint), is_enabled = 0, enabled_at = NULL, last_verified_at = NULL');
        $stmt->execute([$uid, $enc, $secretHint]);
        $clearCodes = platform_issue_recovery_codes($uid);
        $otpauth = 'otpauth://totp/' . rawurlencode('TamasBau:' . ($user['email'] ?? $uid)) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode('TamasBau');
        send_json(['ok' => true, 'secret' => $secret, 'secret_hint' => $secretHint, 'otpauth_uri' => $otpauth, 'recovery_codes' => $clearCodes]);
    }
    if ($action === 'confirm_totp') {
        $code = clean_string($payload['code'] ?? '', 10);
        $stmt = db()->prepare('SELECT secret_encrypted FROM user_totp_settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Nincs előkészített TOTP beállítás.'], 404);
        }
        $secret = base64_decode((string) $row['secret_encrypted'], true) ?: '';
        if (!platform_totp_verify($secret, $code)) {
            send_json(['ok' => false, 'error' => 'Hibás TOTP kód.'], 422);
        }
        $update = db()->prepare('UPDATE user_totp_settings SET is_enabled = 1, enabled_at = NOW(), last_verified_at = NOW() WHERE user_id = ?');
        $update->execute([$uid]);
        send_json(['ok' => true]);
    }
    if ($action === 'disable_totp') {
        $stmt = db()->prepare('UPDATE user_totp_settings SET is_enabled = 0 WHERE user_id = ?');
        $stmt->execute([$uid]);
        send_json(['ok' => true]);
    }
    if ($action === 'use_recovery_code') {
        $code = strtoupper(clean_string($payload['code'] ?? '', 40));
        if ($code === '') {
            send_json(['ok' => false, 'error' => 'Recovery kód kötelező.'], 422);
        }
        $codeHash = hash('sha256', $code);
        $stmt = db()->prepare('UPDATE user_recovery_codes SET is_used = 1, used_at = NOW() WHERE user_id = ? AND code_hash = ? AND is_used = 0');
        $stmt->execute([$uid, $codeHash]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen vagy felhasznált recovery kód.'], 422);
        }
        send_json(['ok' => true]);
    }
    if ($action === 'revoke_all_sessions') {
        $stmt = db()->prepare('UPDATE user_sessions SET is_revoked = 1, revoked_at = NOW() WHERE user_id = ?');
        $stmt->execute([$uid]);
        send_json(['ok' => true]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott biztonsági művelet.'], 405);
}

function platform_list_reviews(array $user): void
{
    if (platform_is_admin($user)) {
        $stmt = db()->query('SELECT cr.*, u.name AS customer_name FROM customer_reviews cr LEFT JOIN users u ON u.id = cr.user_id ORDER BY cr.created_at DESC LIMIT 200');
        send_json(['ok' => true, 'reviews' => $stmt->fetchAll()]);
    }
    $stmt = db()->prepare('SELECT id, rating, feedback, moderation_status, public_visible, created_at FROM customer_reviews WHERE (user_id = ? OR (moderation_status = ? AND public_visible = 1)) ORDER BY created_at DESC LIMIT 200');
    $stmt->execute([(int) $user['id'], 'approved']);
    send_json(['ok' => true, 'reviews' => $stmt->fetchAll()]);
}

function platform_post_reviews(array $user, array $payload): void
{
    $action = clean_string($payload['action'] ?? 'submit', 40);
    if ($action === 'submit') {
        enforce_rate_limit('review_submit', 20, 3600);
        $rating = (int) ($payload['rating'] ?? 0);
        $feedback = clean_string($payload['feedback'] ?? '', 4000);
        $projectId = platform_int_nullable($payload['project_id'] ?? null);
        $workOrderId = platform_int_nullable($payload['work_order_id'] ?? null);
        $serviceTicketId = platform_int_nullable($payload['service_ticket_id'] ?? null);
        if ($rating < 1 || $rating > 5) {
            send_json(['ok' => false, 'error' => 'A csillag érték 1 és 5 között lehet.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO customer_reviews (user_id, project_id, work_order_id, service_ticket_id, rating, feedback, moderation_status, public_visible, source) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)');
        $stmt->execute([(int) $user['id'], $projectId, $workOrderId, $serviceTicketId, $rating, $feedback !== '' ? $feedback : null, 'pending', 'portal']);
        if ($rating <= 2) {
            platform_create_notification(null, 'admin', 'Negatív értékelés érkezett', 'Új értékelés moderálása szükséges.', '/admin#reviews');
        }
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    if ($action === 'moderate') {
        $admin = platform_require_roles(platform_admin_roles());
        $id = (int) ($payload['id'] ?? 0);
        $status = clean_string($payload['moderation_status'] ?? '', 20);
        $publicVisible = !empty($payload['public_visible']) ? 1 : 0;
        platform_enum($status, ['pending', 'approved', 'rejected'], 'moderációs státusz');
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen értékelés azonosító.'], 422);
        }
        $stmt = db()->prepare('UPDATE customer_reviews SET moderation_status = ?, public_visible = ?, moderated_at = NOW(), moderated_by_user_id = ? WHERE id = ?');
        $stmt->execute([$status, $publicVisible, (int) $admin['id'], $id]);
        send_json(['ok' => true]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott értékelés művelet.'], 405);
}

function platform_list_partners(): void
{
    platform_require_roles(platform_admin_roles());
    $partners = db()->query('SELECT id, name, partner_type, status, contact_name, contact_email, contact_phone, service_type, contract_reference, metadata_json, created_at, updated_at FROM partners ORDER BY updated_at DESC LIMIT 300')->fetchAll();
    $assignments = db()->query('SELECT pa.id, pa.partner_id, pa.project_id, pa.work_order_id, pa.assigned_note, pa.assignment_status, pa.external_cost_cents, pa.created_at, p.name AS partner_name FROM partner_assignments pa LEFT JOIN partners p ON p.id = pa.partner_id ORDER BY pa.created_at DESC LIMIT 300')->fetchAll();
    send_json(['ok' => true, 'partners' => $partners, 'assignments' => $assignments]);
}

function platform_post_partners(array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'create' || $action === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        $name = clean_string($payload['name'] ?? '', 180);
        $type = clean_string($payload['partner_type'] ?? 'partner', 20);
        $status = clean_string($payload['status'] ?? 'active', 20);
        $contactName = clean_string($payload['contact_name'] ?? '', 120);
        $contactEmail = clean_string($payload['contact_email'] ?? '', 190);
        $contactPhone = clean_string($payload['contact_phone'] ?? '', 40);
        $serviceType = clean_string($payload['service_type'] ?? '', 120);
        $contractRef = clean_string($payload['contract_reference'] ?? '', 120);
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        platform_enum($type, ['partner', 'subcontractor', 'supplier'], 'partner típus');
        platform_enum($status, ['active', 'inactive', 'blocked'], 'partner státusz');
        if ($name === '') {
            send_json(['ok' => false, 'error' => 'Partner név kötelező.'], 422);
        }
        if ($action === 'create') {
            $stmt = db()->prepare('INSERT INTO partners (name, partner_type, status, contact_name, contact_email, contact_phone, service_type, contract_reference, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $type, $status, $contactName !== '' ? $contactName : null, $contactEmail !== '' ? $contactEmail : null, $contactPhone !== '' ? $contactPhone : null, $serviceType !== '' ? $serviceType : null, $contractRef !== '' ? $contractRef : null, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
        }
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen partner azonosító.'], 422);
        }
        $stmt = db()->prepare('UPDATE partners SET name = ?, partner_type = ?, status = ?, contact_name = ?, contact_email = ?, contact_phone = ?, service_type = ?, contract_reference = ?, metadata_json = ? WHERE id = ?');
        $stmt->execute([$name, $type, $status, $contactName !== '' ? $contactName : null, $contactEmail !== '' ? $contactEmail : null, $contactPhone !== '' ? $contactPhone : null, $serviceType !== '' ? $serviceType : null, $contractRef !== '' ? $contractRef : null, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        send_json(['ok' => true]);
    }
    if ($action === 'assign') {
        $partnerId = (int) ($payload['partner_id'] ?? 0);
        $projectId = platform_int_nullable($payload['project_id'] ?? null);
        $workOrderId = platform_int_nullable($payload['work_order_id'] ?? null);
        $note = clean_string($payload['assigned_note'] ?? '', 500);
        $status = clean_string($payload['assignment_status'] ?? 'assigned', 20);
        $cost = array_key_exists('external_cost_cents', $payload) ? max(0, (int) $payload['external_cost_cents']) : null;
        platform_enum($status, ['assigned', 'in_progress', 'done', 'cancelled'], 'hozzárendelés státusz');
        if ($partnerId <= 0 || ($projectId === null && $workOrderId === null)) {
            send_json(['ok' => false, 'error' => 'Partner és projekt/munkalap kapcsolat kötelező.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO partner_assignments (partner_id, project_id, work_order_id, assigned_note, assignment_status, external_cost_cents, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$partnerId, $projectId, $workOrderId, $note !== '' ? $note : null, $status, $cost, (int) $admin['id']]);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott partner művelet.'], 405);
}

function platform_list_search(array $user): void
{
    $q = clean_string($_GET['q'] ?? '', 120);
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $isAdmin = platform_is_admin($user);
    if ($q === '') {
        send_json(['ok' => true, 'results' => []]);
    }
    $like = '%' . $q . '%';
    $results = [];
    $sources = [
        ['table' => 'users', 'id' => 'id', 'title' => 'name', 'extra' => 'email', 'type' => 'customer', 'where' => $isAdmin ? '1=1' : 'id = ?'],
        ['table' => 'leads', 'id' => 'id', 'title' => 'name', 'extra' => 'email', 'type' => 'lead', 'where' => '1=1'],
        ['table' => 'projects', 'id' => 'id', 'title' => 'title', 'extra' => 'status', 'type' => 'project', 'where' => $isAdmin ? '1=1' : 'user_id = ?'],
        ['table' => 'work_orders', 'id' => 'id', 'title' => 'title', 'extra' => 'status', 'type' => 'work_order', 'where' => $isAdmin ? '1=1' : '(user_id = ? OR assigned_to_user_id = ?)'],
        ['table' => 'service_tickets', 'id' => 'id', 'title' => 'subject', 'extra' => 'status', 'type' => 'ticket', 'where' => $isAdmin ? '1=1' : 'user_id = ?'],
        ['table' => 'products', 'id' => 'id', 'title' => 'name', 'extra' => 'description', 'type' => 'product', 'where' => '1=1'],
    ];
    foreach ($sources as $src) {
        $sql = sprintf(
            'SELECT %s AS entity_id, ? AS entity_type, %s AS title, %s AS extra, created_at FROM %s WHERE %s AND (%s LIKE ? OR %s LIKE ?) ORDER BY created_at DESC LIMIT %d',
            $src['id'],
            $src['title'],
            $src['extra'],
            $src['table'],
            $src['where'],
            $src['title'],
            $src['extra'],
            $limit
        );
        $args = [$src['type']];
        if (!$isAdmin && $src['where'] !== '1=1') {
            $args[] = (int) $user['id'];
            if (str_contains($src['where'], 'assigned_to_user_id')) {
                $args[] = (int) $user['id'];
            }
        }
        $args[] = $like;
        $args[] = $like;
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = $row;
        }
    }
    usort($results, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    $paged = array_slice($results, $offset, $limit);
    send_json(['ok' => true, 'results' => $paged, 'total' => count($results), 'limit' => $limit, 'offset' => $offset]);
}

function platform_list_timeline(array $user): void
{
    $entityType = clean_string($_GET['entity_type'] ?? 'user', 20);
    $entityId = (int) ($_GET['entity_id'] ?? 0);
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    if ($entityId <= 0) {
        send_json(['ok' => false, 'error' => 'Hiányzó idővonal azonosító.'], 422);
    }
    $isAdmin = platform_is_admin($user);
    if (!$isAdmin && $entityType === 'user' && $entityId !== (int) $user['id']) {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez az idővonalhoz.'], 403);
    }
    $where = $entityType === 'lead' ? 'lead_id = ?' : 'user_id = ?';
    $stmt = db()->prepare('SELECT id, source_type, source_id, title, summary, is_sensitive, metadata_json, created_at FROM communication_timeline WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $entityId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$isAdmin) {
        foreach ($rows as &$row) {
            if ((int) ($row['is_sensitive'] ?? 0) === 1) {
                $row['summary'] = '*** maszkolt tartalom ***';
                $row['metadata_json'] = null;
            }
        }
        unset($row);
    }
    send_json(['ok' => true, 'timeline' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

function platform_post_timeline(array $user, array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $entityType = clean_string($payload['entity_type'] ?? 'user', 20);
    $entityId = (int) ($payload['entity_id'] ?? 0);
    $title = clean_string($payload['title'] ?? '', 180);
    $summary = clean_string($payload['summary'] ?? '', 1000);
    $sourceType = clean_string($payload['source_type'] ?? 'manual', 50);
    $sourceId = platform_int_nullable($payload['source_id'] ?? null);
    $isSensitive = !empty($payload['is_sensitive']) ? 1 : 0;
    $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
    if ($entityId <= 0 || $title === '') {
        send_json(['ok' => false, 'error' => 'Entity és cím kötelező.'], 422);
    }
    if (!in_array($entityType, ['user', 'lead'], true)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen entity típus.'], 422);
    }
    $userId = $entityType === 'user' ? $entityId : null;
    $leadId = $entityType === 'lead' ? $entityId : null;
    $stmt = db()->prepare('INSERT INTO communication_timeline (user_id, lead_id, source_type, source_id, title, summary, is_sensitive, metadata_json, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $leadId, $sourceType, $sourceId, $title, $summary !== '' ? $summary : null, $isSensitive, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $admin['id']]);
    send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
}

function platform_list_org(): void
{
    platform_require_roles(platform_admin_roles());
    $sites = db()->query('SELECT id, name, region_code, is_default, status, created_at FROM sites ORDER BY is_default DESC, id ASC')->fetchAll();
    $teams = db()->query('SELECT id, site_id, name, status, created_at FROM teams ORDER BY id ASC')->fetchAll();
    $regions = db()->query('SELECT id, name, code, status, created_at FROM regions ORDER BY id ASC')->fetchAll();
    $access = db()->query('SELECT id, user_id, site_id, team_id, region_id, created_at FROM user_region_access ORDER BY id ASC')->fetchAll();
    send_json(['ok' => true, 'sites' => $sites, 'teams' => $teams, 'regions' => $regions, 'access' => $access]);
}

function platform_post_org(array $payload): void
{
    platform_require_roles(platform_admin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'create_site') {
        $name = clean_string($payload['name'] ?? '', 120);
        $regionCode = clean_string($payload['region_code'] ?? '', 20);
        $isDefault = !empty($payload['is_default']) ? 1 : 0;
        if ($name === '') {
            send_json(['ok' => false, 'error' => 'Telephely név kötelező.'], 422);
        }
        if ($isDefault === 1) {
            db()->exec('UPDATE sites SET is_default = 0');
        }
        $stmt = db()->prepare('INSERT INTO sites (name, region_code, is_default, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $regionCode !== '' ? $regionCode : null, $isDefault, 'active']);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    if ($action === 'create_team') {
        $siteId = platform_int_nullable($payload['site_id'] ?? null);
        $name = clean_string($payload['name'] ?? '', 120);
        if ($name === '') {
            send_json(['ok' => false, 'error' => 'Csapatnév kötelező.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO teams (site_id, name, status) VALUES (?, ?, ?)');
        $stmt->execute([$siteId, $name, 'active']);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    if ($action === 'create_region') {
        $name = clean_string($payload['name'] ?? '', 120);
        $code = strtoupper(clean_string($payload['code'] ?? '', 20));
        if ($name === '' || $code === '') {
            send_json(['ok' => false, 'error' => 'Régió név és kód kötelező.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO regions (name, code, status) VALUES (?, ?, ?)');
        $stmt->execute([$name, $code, 'active']);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }
    if ($action === 'grant_access') {
        $userId = (int) ($payload['user_id'] ?? 0);
        $siteId = platform_int_nullable($payload['site_id'] ?? null);
        $teamId = platform_int_nullable($payload['team_id'] ?? null);
        $regionId = platform_int_nullable($payload['region_id'] ?? null);
        if ($userId <= 0) {
            send_json(['ok' => false, 'error' => 'Felhasználó azonosító kötelező.'], 422);
        }
        $stmt = db()->prepare('INSERT IGNORE INTO user_region_access (user_id, site_id, team_id, region_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $siteId, $teamId, $regionId]);
        send_json(['ok' => true]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott szervezeti művelet.'], 405);
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
    if ($module === 'mobile') {
        platform_require_roles(platform_worker_roles());
        platform_list_mobile($user);
    }
    if ($module === 'signatures') {
        platform_list_signatures($user);
    }
    if ($module === 'costs') {
        platform_list_costs($user);
    }
    if ($module === 'workflows') {
        platform_list_workflows($user);
    }
    if ($module === 'security') {
        platform_list_security($user);
    }
    if ($module === 'reviews') {
        platform_list_reviews($user);
    }
    if ($module === 'partners') {
        platform_list_partners();
    }
    if ($module === 'search') {
        platform_list_search($user);
    }
    if ($module === 'timeline') {
        platform_list_timeline($user);
    }
    if ($module === 'org') {
        platform_list_org();
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott modul.'], 405);
}

if ($method === 'POST') {
    if ($module === 'mobile') {
        platform_require_roles(platform_worker_roles());
        platform_post_mobile($user, $payload);
    }
    if ($module === 'signatures') {
        platform_post_signatures($user, $payload);
    }
    if ($module === 'costs') {
        platform_post_costs($user, $payload);
    }
    if ($module === 'workflows') {
        platform_post_workflows($payload);
    }
    if ($module === 'security') {
        platform_post_security($user, $payload);
    }
    if ($module === 'reviews') {
        platform_post_reviews($user, $payload);
    }
    if ($module === 'partners') {
        platform_post_partners($payload);
    }
    if ($module === 'timeline') {
        platform_post_timeline($user, $payload);
    }
    if ($module === 'org') {
        platform_post_org($payload);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott modul.'], 405);
}

send_json(['ok' => false, 'error' => 'Nem támogatott kérés.'], 405);
