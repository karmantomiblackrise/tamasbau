<?php
declare(strict_types=1);

/*
 * Platform API router (mobil PWA, aláírás, profit, workflow, 2FA/biztonság, értékelés,
 * SLA, számlázás, diagnosztika, globális kereső, partnerek, idővonal, szervezet).
 * Az üzleti logika az api/lib/*.php könyvtárakban van; itt csak jogosultság, CSRF,
 * input validáció és HTTP válasz.
 */

$platformModule = (string) ($_GET['module'] ?? '');
if ($platformModule === 'security') {
    // A 2FA beállító oldalt a kötelező 2FA policy mellett is el kell érni.
    define('TB_TWO_FACTOR_SETUP_CONTEXT', true);
}

require_once __DIR__ . '/platform-lib.php';

function platform_admin_roles(): array
{
    return tb_backoffice_roles();
}

function platform_worker_roles(): array
{
    return array_merge(tb_backoffice_roles(), ['field_worker']);
}

function platform_superadmin_roles(): array
{
    return ['admin', 'superadmin'];
}

function platform_is_admin(array $user): bool
{
    return tb_is_backoffice($user);
}

function platform_require_roles(array $allowed): array
{
    $user = require_login();
    if (!in_array((string) ($user['role'] ?? 'user'), $allowed, true)) {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a művelethez.'], 403);
    }
    return $user;
}

function platform_enum(string $value, array $allowed, string $label): string
{
    if (!in_array($value, $allowed, true)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen ' . $label . '.'], 422);
    }
    return $value;
}

function platform_int_nullable($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $v = (int) $value;
    return $v > 0 ? $v : null;
}

function platform_fail(Throwable $e): void
{
    $status = tb_exception_http_status($e);
    if ($status === 500) {
        $errorId = app_log_error('platform_api', $e);
        send_json(['ok' => false, 'error' => 'Szerverhiba történt. Hibaazonosító: ' . $errorId, 'error_id' => $errorId], 500);
    }
    send_json(['ok' => false, 'error' => $e->getMessage()], $status);
}

/* ---------------- A) Mobil PWA ---------------- */

function platform_get_mobile(array $user): void
{
    platform_require_roles(platform_worker_roles());
    $view = (string) ($_GET['view'] ?? 'list');
    if ($view === 'detail') {
        $detail = tb_mobile_detail($user, (int) ($_GET['id'] ?? 0));
        if ($detail === null) {
            send_json(['ok' => false, 'error' => 'A munkalap nem található.'], 404);
        }
        send_json(['ok' => true] + $detail);
    }
    if ($view === 'conflicts') {
        send_json(['ok' => true, 'conflicts' => tb_mobile_conflicts($user)]);
    }
    send_json(['ok' => true, 'work_orders' => tb_mobile_list($user, clean_string((string) ($_GET['status'] ?? ''), 20)), 'server_time' => date('c')]);
}

function platform_mobile_sync_one(array $user, array $op): array
{
    try {
        $result = tb_mobile_sync($user, $op);
        if ($result['http'] === 200 && empty($result['body']['duplicate']) && ($op['op_type'] ?? '') === 'status' && (($op['data']['status'] ?? '') === 'done')) {
            tb_safe_event('work_order_closed', ['work_order_id' => (int) $op['work_order_id']]);
        }
        return $result;
    } catch (Throwable $e) {
        $status = tb_exception_http_status($e);
        if ($status === 500) {
            $errorId = app_log_error('mobile_sync', $e);
            return ['http' => 500, 'body' => ['ok' => false, 'code' => 'server_error', 'error' => 'Szerverhiba (' . $errorId . '), később újrapróbáljuk.']];
        }
        $codes = [422 => 'validation', 409 => 'conflict', 404 => 'not_found', 403 => 'forbidden'];
        return ['http' => $status, 'body' => ['ok' => false, 'code' => $codes[$status] ?? 'error', 'error' => $e->getMessage()]];
    }
}

function platform_post_mobile(array $user, array $payload): void
{
    platform_require_roles(platform_worker_roles());
    $action = clean_string($payload['action'] ?? 'sync', 40);
    if ($action === 'sync') {
        enforce_rate_limit('mobile_sync', 600, 600);
        $result = platform_mobile_sync_one($user, $payload);
        send_json($result['body'], $result['http']);
    }
    if ($action === 'sync_batch') {
        enforce_rate_limit('mobile_sync', 600, 600);
        $ops = is_array($payload['operations'] ?? null) ? array_slice(array_values($payload['operations']), 0, 50) : [];
        $results = [];
        foreach ($ops as $op) {
            $op = is_array($op) ? $op : [];
            $r = platform_mobile_sync_one($user, $op);
            $results[] = ['idempotency_key' => (string) ($op['idempotency_key'] ?? ''), 'http' => $r['http']] + $r['body'];
        }
        send_json(['ok' => true, 'results' => $results]);
    }
    if ($action === 'resolve_conflict') {
        $resolution = clean_string($payload['resolution'] ?? '', 20);
        $result = tb_mobile_resolve_conflict($user, (int) ($payload['operation_id'] ?? 0), $resolution);
        log_admin_activity((int) $user['id'], 'mobile_conflict_resolved', 'work_order_sync_operation', (int) ($payload['operation_id'] ?? 0), ['resolution' => $resolution]);
        send_json(['ok' => true, 'result' => $result]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott mobil művelet.'], 405);
}

/* ---------------- B) Digitális aláírás ---------------- */

function platform_get_signatures(array $user): void
{
    $view = (string) ($_GET['view'] ?? 'list');
    if ($view === 'document') {
        $type = clean_string((string) ($_GET['document_type'] ?? ''), 20);
        $document = in_array($type, tb_signature_document_types(), true) ? tb_signature_document($type, (int) ($_GET['document_id'] ?? 0)) : null;
        if (!$document) {
            send_json(['ok' => false, 'error' => 'A dokumentum nem található.'], 404);
        }
        if (!tb_signature_can_access($user, $document)) {
            send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a dokumentumhoz.'], 403);
        }
        $existing = db()->prepare('SELECT id, signer_name, signed_at, status, document_hash FROM digital_signatures WHERE document_type = ? AND document_id = ? ORDER BY id DESC LIMIT 20');
        $existing->execute([$type, (int) $document['id']]);
        send_json(['ok' => true, 'document' => [
            'type' => $document['type'], 'id' => $document['id'], 'status' => $document['status'], 'snapshot' => $document['snapshot'],
            'hash' => $document['hash'], 'version' => $document['version'], 'label' => tb_signature_labels()[$type] ?? '',
            'default_signer' => ['name' => $document['owner']['name'] ?? '', 'email' => $document['owner']['email'] ?? ''],
        ], 'signatures' => $existing->fetchAll()]);
    }
    if ($view === 'print' || $view === 'detail') {
        $row = tb_signature_get((int) ($_GET['id'] ?? 0));
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Az aláírás nem található.'], 404);
        }
        $document = tb_signature_document((string) $row['document_type'], (int) $row['document_id']);
        $allowed = tb_is_backoffice($user)
            || ($document && tb_signature_can_access($user, $document))
            || strcasecmp((string) $row['signer_email'], (string) ($user['email'] ?? '')) === 0;
        if (!$allowed) {
            send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez az aláíráshoz.'], 403);
        }
        if ($view === 'print') {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, private');
            header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'");
            header('X-Content-Type-Options: nosniff');
            echo tb_signature_printable_html($row);
            exit;
        }
        unset($row['signature_svg']);
        $row['document_snapshot'] = tb_json_decode_array($row['document_snapshot']);
        send_json(['ok' => true, 'signature' => $row]);
    }
    $args = [];
    $sql = 'SELECT id, document_type, document_id, document_version, document_hash, signer_name, signer_email, signer_role, ip_capture_mode, status, revoked_reason, signed_at, revoked_at, email_status, email_error FROM digital_signatures';
    $where = [];
    if (!tb_is_backoffice($user)) {
        $where[] = '(created_by_user_id = ? OR signer_email = ?)';
        $args[] = (int) $user['id'];
        $args[] = (string) ($user['email'] ?? '');
    }
    $status = clean_string((string) ($_GET['status'] ?? ''), 20);
    if (in_array($status, ['active', 'revoked', 'invalidated'], true)) {
        $where[] = 'status = ?';
        $args[] = $status;
    }
    $docType = clean_string((string) ($_GET['document_type'] ?? ''), 20);
    if (in_array($docType, tb_signature_document_types(), true)) {
        $where[] = 'document_type = ?';
        $args[] = $docType;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    [$limit, $offset] = tb_pagination();
    $stmt = db()->prepare($sql . ' ORDER BY signed_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute($args);
    send_json(['ok' => true, 'signatures' => $stmt->fetchAll(), 'limit' => $limit, 'offset' => $offset]);
}

function platform_post_signatures(array $user, array $payload): void
{
    $action = clean_string($payload['action'] ?? 'create', 40);
    if ($action === 'create') {
        enforce_rate_limit('signature_create', 30, 3600);
        $result = tb_signature_create($user, $payload);
        send_json(['ok' => true] + $result, 201);
    }
    if ($action === 'revoke' || $action === 'invalidate') {
        $admin = platform_require_roles(platform_superadmin_roles());
        tb_signature_revoke($admin, (int) ($payload['id'] ?? 0), clean_string((string) ($payload['reason'] ?? ''), 255), $action === 'revoke' ? 'revoked' : 'invalidated');
        send_json(['ok' => true]);
    }
    if ($action === 'resend_email') {
        $admin = platform_require_roles(platform_admin_roles());
        $id = (int) ($payload['id'] ?? 0);
        if (!tb_signature_get($id)) {
            send_json(['ok' => false, 'error' => 'Az aláírás nem található.'], 404);
        }
        $mail = tb_signature_send_copy($id);
        log_admin_activity((int) $admin['id'], 'digital_signature_email_resent', 'digital_signature', $id, ['status' => $mail['status']]);
        send_json(['ok' => $mail['status'] === 'sent', 'email_status' => $mail['status'], 'error' => $mail['error']], $mail['status'] === 'sent' ? 200 : 502);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott aláírás művelet.'], 405);
}

/* ---------------- C) Költség és profit ---------------- */

function platform_cost_scope(array $source): array
{
    $where = [];
    $args = [];
    $projectId = (int) ($source['project_id'] ?? 0);
    $workOrderId = (int) ($source['work_order_id'] ?? 0);
    if ($projectId > 0) {
        $where[] = 'project_id = ?';
        $args[] = $projectId;
    }
    if ($workOrderId > 0) {
        $where[] = 'work_order_id = ?';
        $args[] = $workOrderId;
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $args];
}

function platform_get_costs(array $user): void
{
    platform_require_roles(platform_admin_roles());
    $internal = tb_can_see_internal_costs($user);
    [$where, $args] = platform_cost_scope($_GET);
    $stmt = db()->prepare('SELECT id, project_id, work_order_id, entry_type, title, quantity, unit, unit_price_cents, internal_unit_cost_cents, planned_quantity, planned_unit_price_cents, travel_km, note, billable_to_customer, created_at, updated_at FROM work_order_cost_entries' . $where . ' ORDER BY created_at DESC LIMIT 500');
    $stmt->execute($args);
    $entries = $stmt->fetchAll();
    if (!$internal) {
        foreach ($entries as &$entry) {
            unset($entry['internal_unit_cost_cents']);
        }
        unset($entry);
    }
    $profit = null;
    if ((int) ($_GET['project_id'] ?? 0) > 0) {
        $profit = tb_profit_for('project', (int) $_GET['project_id']);
    } elseif ((int) ($_GET['work_order_id'] ?? 0) > 0) {
        $profit = tb_profit_for('work_order', (int) $_GET['work_order_id']);
    }
    if ($profit !== null && !$internal) {
        $profit = tb_profit_redact($profit);
    }
    send_json(['ok' => true, 'entries' => $entries, 'profit' => $profit, 'internal_visible' => $internal]);
}

function platform_post_costs(array $user, array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $internal = tb_can_see_internal_costs($admin);
    $action = clean_string($payload['action'] ?? '', 40);
    if ($action === 'add' || $action === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        $projectId = platform_int_nullable($payload['project_id'] ?? null);
        $workOrderId = platform_int_nullable($payload['work_order_id'] ?? null);
        $entryType = platform_enum(clean_string($payload['entry_type'] ?? '', 20), ['material', 'labor', 'travel', 'external'], 'költség típus');
        $title = clean_string($payload['title'] ?? '', 180);
        $quantity = round((float) ($payload['quantity'] ?? 1), 2);
        $unit = clean_string($payload['unit'] ?? '', 20);
        $unitPrice = max(0, (int) ($payload['unit_price_cents'] ?? 0));
        $internalCost = ($internal && isset($payload['internal_unit_cost_cents']) && $payload['internal_unit_cost_cents'] !== '') ? max(0, (int) $payload['internal_unit_cost_cents']) : null;
        $plannedQuantity = isset($payload['planned_quantity']) && $payload['planned_quantity'] !== '' ? round((float) $payload['planned_quantity'], 2) : null;
        $plannedUnitPrice = isset($payload['planned_unit_price_cents']) && $payload['planned_unit_price_cents'] !== '' ? max(0, (int) $payload['planned_unit_price_cents']) : null;
        $travelKm = isset($payload['travel_km']) && $payload['travel_km'] !== '' ? round((float) $payload['travel_km'], 2) : null;
        $note = clean_string($payload['note'] ?? '', 2000);
        $billable = !empty($payload['billable_to_customer']) ? 1 : 0;
        if ($title === '' || ($projectId === null && $workOrderId === null) || $quantity < 0 || $quantity > 1000000) {
            send_json(['ok' => false, 'error' => 'Cím, érvényes mennyiség és projekt/munkalap kapcsolat kötelező.'], 422);
        }
        if ($action === 'add') {
            $stmt = db()->prepare('INSERT INTO work_order_cost_entries (project_id, work_order_id, entry_type, title, quantity, unit, unit_price_cents, internal_unit_cost_cents, planned_quantity, planned_unit_price_cents, travel_km, note, billable_to_customer, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$projectId, $workOrderId, $entryType, $title, $quantity, $unit !== '' ? $unit : null, $unitPrice, $internalCost, $plannedQuantity, $plannedUnitPrice, $travelKm, $note !== '' ? $note : null, $billable, (int) $admin['id']]);
            $newId = (int) db()->lastInsertId();
            log_admin_activity((int) $admin['id'], 'cost_entry_created', 'work_order_cost_entry', $newId);
            send_json(['ok' => true, 'id' => $newId], 201);
        }
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen költség azonosító.'], 422);
        }
        // Belső költséget csak jogosult admin módosíthat; a többiek a meglévő értéket nem írják felül.
        $internalSql = $internal ? ', internal_unit_cost_cents = ?' : '';
        $params = [$projectId, $workOrderId, $entryType, $title, $quantity, $unit !== '' ? $unit : null, $unitPrice, $plannedQuantity, $plannedUnitPrice, $travelKm, $note !== '' ? $note : null, $billable];
        if ($internal) {
            $params[] = $internalCost;
        }
        $params[] = $id;
        $stmt = db()->prepare('UPDATE work_order_cost_entries SET project_id = ?, work_order_id = ?, entry_type = ?, title = ?, quantity = ?, unit = ?, unit_price_cents = ?, planned_quantity = ?, planned_unit_price_cents = ?, travel_km = ?, note = ?, billable_to_customer = ?' . $internalSql . ' WHERE id = ?');
        $stmt->execute($params);
        log_admin_activity((int) $admin['id'], 'cost_entry_updated', 'work_order_cost_entry', $id);
        send_json(['ok' => true]);
    }
    if ($action === 'delete') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen költség azonosító.'], 422);
        }
        db()->prepare('DELETE FROM work_order_cost_entries WHERE id = ?')->execute([$id]);
        log_admin_activity((int) $admin['id'], 'cost_entry_deleted', 'work_order_cost_entry', $id);
        send_json(['ok' => true]);
    }
    if ($action === 'export_csv') {
        [$where, $args] = platform_cost_scope($payload);
        $stmt = db()->prepare('SELECT id, project_id, work_order_id, entry_type, title, quantity, unit, unit_price_cents, internal_unit_cost_cents, planned_quantity, planned_unit_price_cents, travel_km, created_at FROM work_order_cost_entries' . $where . ' ORDER BY created_at DESC');
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $header = ['id', 'project_id', 'work_order_id', 'entry_type', 'title', 'quantity', 'unit', 'unit_price_cents'];
        if ($internal) {
            $header[] = 'internal_unit_cost_cents';
        }
        array_push($header, 'planned_quantity', 'planned_unit_price_cents', 'travel_km', 'created_at');
        $lines = [];
        foreach ($rows as $r) {
            $line = [$r['id'], $r['project_id'], $r['work_order_id'], $r['entry_type'], $r['title'], $r['quantity'], $r['unit'], $r['unit_price_cents']];
            if ($internal) {
                $line[] = $r['internal_unit_cost_cents'];
            }
            array_push($line, $r['planned_quantity'], $r['planned_unit_price_cents'], $r['travel_km'], $r['created_at']);
            $lines[] = $line;
        }
        send_json(['ok' => true, 'csv' => tb_csv_build($header, $lines), 'row_count' => count($rows)]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott költség művelet.'], 405);
}

function platform_get_profit(array $user): void
{
    platform_require_roles(platform_admin_roles());
    $internal = tb_can_see_internal_costs($user);
    $scope = ($_GET['scope'] ?? 'project') === 'work_order' ? 'work_order' : 'project';
    $view = (string) ($_GET['view'] ?? 'overview');
    if ($view === 'detail') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Hiányzó azonosító.'], 422);
        }
        $profit = tb_profit_for($scope, $id);
        send_json(['ok' => true, 'scope' => $scope, 'id' => $id, 'profit' => $internal ? $profit : tb_profit_redact($profit), 'internal_visible' => $internal]);
    }
    $overview = tb_profit_overview($internal, $scope, (int) ($_GET['limit'] ?? 100));
    if ($view === 'csv') {
        log_admin_activity((int) $user['id'], 'profit_csv_export', $scope, null, ['internal' => $internal]);
        tb_send_csv('profit-' . $scope . '-' . date('Ymd-His') . '.csv', tb_profit_csv($overview));
    }
    send_json(['ok' => true] + $overview);
}

/* ---------------- D) Workflow központ ---------------- */

function platform_get_workflows(array $user): void
{
    platform_require_roles(platform_admin_roles());
    $rules = db()->query('SELECT r.id, r.rule_key, r.title, r.is_enabled, r.config_json, r.updated_at,
        (SELECT COUNT(*) FROM workflow_jobs j WHERE j.rule_id = r.id AND j.status = \'pending\') AS pending_count,
        (SELECT COUNT(*) FROM workflow_jobs j WHERE j.rule_id = r.id AND j.status = \'failed\') AS failed_count
        FROM workflow_rules r ORDER BY r.id ASC')->fetchAll();
    $handlers = tb_workflow_handlers();
    foreach ($rules as &$rule) {
        $rule['config'] = tb_json_decode_array($rule['config_json']);
        $rule['has_handler'] = isset($handlers[$rule['rule_key']]);
        unset($rule['config_json']);
    }
    unset($rule);
    $where = [];
    $args = [];
    $status = clean_string((string) ($_GET['status'] ?? ''), 20);
    if (in_array($status, ['pending', 'processing', 'done', 'failed'], true)) {
        $where[] = 'j.status = ?';
        $args[] = $status;
    }
    $ruleKey = clean_string((string) ($_GET['rule_key'] ?? ''), 80);
    if ($ruleKey !== '') {
        $where[] = 'r.rule_key = ?';
        $args[] = $ruleKey;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    [$limit, $offset] = tb_pagination(50, 200);
    $count = db()->prepare('SELECT COUNT(*) AS c FROM workflow_jobs j LEFT JOIN workflow_rules r ON r.id = j.rule_id' . $whereSql);
    $count->execute($args);
    $jobs = db()->prepare('SELECT j.id, j.rule_id, r.rule_key, j.idempotency_key, j.trigger_type, j.status, j.retries, j.max_retries, j.last_error, j.next_attempt_at, j.started_at, j.processed_at, j.created_at, j.result_json FROM workflow_jobs j LEFT JOIN workflow_rules r ON r.id = j.rule_id' . $whereSql . ' ORDER BY j.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $jobs->execute($args);
    $stats = db()->query('SELECT status, COUNT(*) AS c FROM workflow_jobs GROUP BY status')->fetchAll();
    send_json(['ok' => true, 'rules' => $rules, 'jobs' => $jobs->fetchAll(), 'total' => (int) ($count->fetch()['c'] ?? 0), 'limit' => $limit, 'offset' => $offset, 'stats' => array_column($stats, 'c', 'status')]);
}

function platform_post_workflows(array $payload): void
{
    $admin = platform_require_roles(platform_superadmin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    $id = (int) ($payload['id'] ?? 0);
    if ($action === 'toggle_rule') {
        $enabled = !empty($payload['is_enabled']) ? 1 : 0;
        $stmt = db()->prepare('UPDATE workflow_rules SET is_enabled = ? WHERE id = ?');
        $stmt->execute([$enabled, $id]);
        log_admin_activity((int) $admin['id'], 'workflow_rule_toggled', 'workflow_rule', $id, ['is_enabled' => $enabled]);
        send_json(['ok' => true]);
    }
    if ($action === 'update_rule') {
        $config = $payload['config'] ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (!is_array($config)) {
            send_json(['ok' => false, 'error' => 'A konfiguráció csak érvényes JSON objektum lehet.'], 422);
        }
        $encoded = tb_json($config);
        if (strlen($encoded) > 8000) {
            send_json(['ok' => false, 'error' => 'A konfiguráció túl hosszú.'], 422);
        }
        $title = clean_string((string) ($payload['title'] ?? ''), 180);
        $stmt = db()->prepare('UPDATE workflow_rules SET config_json = ?, title = COALESCE(NULLIF(?, \'\'), title) WHERE id = ?');
        $stmt->execute([$encoded, $title, $id]);
        log_admin_activity((int) $admin['id'], 'workflow_rule_updated', 'workflow_rule', $id, ['config' => $config]);
        send_json(['ok' => true]);
    }
    if ($action === 'retry_job') {
        $stmt = db()->prepare("UPDATE workflow_jobs SET status = 'pending', next_attempt_at = NULL, max_retries = GREATEST(max_retries, retries + 1) WHERE id = ? AND status = 'failed'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Csak sikertelen job indítható újra.'], 409);
        }
        log_admin_activity((int) $admin['id'], 'workflow_job_retry', 'workflow_job', $id);
        $run = tb_workflow_run_pending(1, $id);
        send_json(['ok' => true, 'run' => $run]);
    }
    if ($action === 'run_job') {
        db()->prepare("UPDATE workflow_jobs SET next_attempt_at = NULL WHERE id = ? AND status = 'pending'")->execute([$id]);
        log_admin_activity((int) $admin['id'], 'workflow_job_run', 'workflow_job', $id);
        send_json(['ok' => true, 'run' => tb_workflow_run_pending(1, $id)]);
    }
    if ($action === 'run_pending') {
        tb_workflow_enqueue_scheduled();
        $run = tb_workflow_run_pending(max(1, min(100, (int) ($payload['limit'] ?? 25))));
        log_admin_activity((int) $admin['id'], 'workflow_run_pending', 'workflow_job', null, ['processed' => count($run['processed'])]);
        send_json(['ok' => true, 'run' => $run]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott workflow művelet.'], 405);
}

/* ---------------- E) Biztonság / 2FA ---------------- */

function platform_get_security(array $user): void
{
    $uid = (int) $user['id'];
    $view = (string) ($_GET['view'] ?? 'self');
    if ($view === 'risk') {
        platform_require_roles(platform_superadmin_roles());
        $events = db()->query("SELECT e.id, e.user_id, u.name AS user_name, e.email_attempt, e.is_success, e.risk_level, e.failure_reason, e.user_agent, e.created_at FROM user_login_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.risk_level IN ('medium','high') OR e.is_success = 0 ORDER BY e.id DESC LIMIT 200")->fetchAll();
        $admins = db()->query("SELECT u.id, u.name, u.email, u.role, COALESCE(t.is_enabled, 0) AS two_factor_enabled FROM users u LEFT JOIN user_totp_settings t ON t.user_id = u.id WHERE u.role IN ('admin','superadmin') AND u.is_active = 1 ORDER BY u.id")->fetchAll();
        send_json(['ok' => true, 'events' => $events, 'admins' => $admins, 'policy' => admin_two_factor_policy()]);
    }
    $settings = tb_totp_settings($uid);
    $codes = db()->prepare('SELECT COUNT(*) AS c FROM user_recovery_codes WHERE user_id = ? AND is_used = 0');
    $codes->execute([$uid]);
    $sessions = db()->prepare('SELECT id, user_agent, is_revoked, last_seen_at, created_at, revoked_at, session_token_hash = ? AS is_current FROM user_sessions WHERE user_id = ? ORDER BY is_revoked ASC, last_seen_at DESC LIMIT 50');
    $sessions->execute([auth_session_hash(), $uid]);
    $logins = db()->prepare('SELECT id, is_success, risk_level, created_at, failure_reason, user_agent FROM user_login_events WHERE user_id = ? ORDER BY id DESC LIMIT 50');
    $logins->execute([$uid]);
    send_json([
        'ok' => true,
        'totp' => [
            'is_enabled' => (int) ($settings['is_enabled'] ?? 0),
            'pending_setup' => $settings !== null && (int) $settings['is_enabled'] !== 1,
            'enabled_at' => $settings['enabled_at'] ?? null,
            'last_verified_at' => $settings['last_verified_at'] ?? null,
            'recovery_codes_left' => (int) ($codes->fetch()['c'] ?? 0),
        ],
        'policy' => admin_two_factor_policy(),
        'setup_required' => user_must_setup_two_factor($user),
        'sessions' => $sessions->fetchAll(),
        'login_events' => $logins->fetchAll(),
    ]);
}

function platform_post_security(array $user, array $payload): void
{
    $uid = (int) $user['id'];
    $action = clean_string($payload['action'] ?? '', 40);
    enforce_rate_limit('security_actions', 30, 900);
    if ($action === 'setup_totp') {
        $settings = tb_totp_settings($uid);
        if ($settings && (int) $settings['is_enabled'] === 1) {
            send_json(['ok' => false, 'error' => 'A kétlépcsős azonosítás már be van kapcsolva. Előbb kapcsolja ki.'], 409);
        }
        $secret = tb_totp_generate_secret();
        $stmt = db()->prepare('INSERT INTO user_totp_settings (user_id, secret_encrypted, secret_hint, is_enabled, enabled_at, last_verified_at, last_used_step) VALUES (?, ?, ?, 0, NULL, NULL, NULL) ON DUPLICATE KEY UPDATE secret_encrypted = VALUES(secret_encrypted), secret_hint = VALUES(secret_hint), is_enabled = 0, enabled_at = NULL, last_verified_at = NULL, last_used_step = NULL');
        $stmt->execute([$uid, tb_secret_encrypt($secret), substr($secret, 0, 4) . '…']);
        send_json(['ok' => true, 'secret' => $secret, 'otpauth_uri' => tb_totp_uri($secret, (string) ($user['email'] ?? ('user-' . $uid)), (string) app_config()['app_name']), 'app_key_configured' => tb_app_key_configured()]);
    }
    if ($action === 'confirm_totp') {
        $settings = tb_totp_settings($uid);
        if (!$settings) {
            send_json(['ok' => false, 'error' => 'Nincs előkészített 2FA beállítás.'], 404);
        }
        if ((int) $settings['is_enabled'] === 1) {
            send_json(['ok' => false, 'error' => 'A kétlépcsős azonosítás már aktív.'], 409);
        }
        if (!tb_verify_user_totp($uid, clean_string((string) ($payload['code'] ?? ''), 10), false)) {
            send_json(['ok' => false, 'error' => 'Hibás ellenőrző kód. Ellenőrizze a telefon pontos idejét.'], 422);
        }
        db()->prepare('UPDATE user_totp_settings SET is_enabled = 1, enabled_at = NOW() WHERE user_id = ?')->execute([$uid]);
        $codes = tb_issue_recovery_codes($uid);
        log_admin_activity($uid, 'two_factor_enabled', 'user', $uid);
        send_json(['ok' => true, 'recovery_codes' => $codes]);
    }
    if ($action === 'disable_totp' || $action === 'regenerate_recovery_codes') {
        $password = (string) ($payload['password'] ?? '');
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $hash = (string) (($stmt->fetch() ?: [])['password_hash'] ?? '');
        $code = clean_string((string) ($payload['code'] ?? ''), 10);
        if (!password_verify($password, $hash) || !tb_verify_user_totp($uid, $code)) {
            log_admin_activity($uid, 'two_factor_change_denied', 'user', $uid, ['action' => $action]);
            send_json(['ok' => false, 'error' => 'Hibás jelszó vagy ellenőrző kód.'], 422);
        }
        if ($action === 'regenerate_recovery_codes') {
            $codes = tb_issue_recovery_codes($uid);
            log_admin_activity($uid, 'two_factor_recovery_regenerated', 'user', $uid);
            send_json(['ok' => true, 'recovery_codes' => $codes]);
        }
        if (in_array((string) $user['role'], admin_two_factor_required_roles(), true) && admin_two_factor_policy() === 'required') {
            send_json(['ok' => false, 'error' => 'A 2FA a jelenlegi szabályzat szerint kötelező ennél a szerepkörnél.'], 409);
        }
        db()->prepare('DELETE FROM user_totp_settings WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$uid]);
        log_admin_activity($uid, 'two_factor_disabled', 'user', $uid);
        send_json(['ok' => true]);
    }
    if ($action === 'revoke_session') {
        $sessionId = (int) ($payload['session_id'] ?? 0);
        $stmt = db()->prepare('UPDATE user_sessions SET is_revoked = 1, revoked_at = NOW() WHERE id = ? AND user_id = ? AND is_revoked = 0 AND session_token_hash <> ?');
        $stmt->execute([$sessionId, $uid, auth_session_hash()]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'A munkamenet nem található, már vissza lett vonva, vagy ez az aktuális munkamenet.'], 404);
        }
        log_admin_activity($uid, 'session_revoked', 'user_session', $sessionId);
        send_json(['ok' => true]);
    }
    if ($action === 'revoke_other_sessions' || $action === 'revoke_all_sessions') {
        $stmt = db()->prepare('UPDATE user_sessions SET is_revoked = 1, revoked_at = NOW() WHERE user_id = ? AND is_revoked = 0 AND session_token_hash <> ?');
        $stmt->execute([$uid, auth_session_hash()]);
        log_admin_activity($uid, 'sessions_revoked', 'user', $uid, ['count' => $stmt->rowCount()]);
        send_json(['ok' => true, 'revoked' => $stmt->rowCount()]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott biztonsági művelet.'], 405);
}

/* ---------------- I) Értékelések ---------------- */

function platform_get_reviews(array $user): void
{
    if (!platform_is_admin($user)) {
        $stmt = db()->prepare('SELECT id, rating, feedback, moderation_status, public_visible, created_at FROM customer_reviews WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([(int) $user['id']]);
        send_json(['ok' => true, 'reviews' => $stmt->fetchAll()]);
    }
    $where = [];
    $args = [];
    $status = clean_string((string) ($_GET['status'] ?? ''), 20);
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'cr.moderation_status = ?';
        $args[] = $status;
    }
    $maxRating = (int) ($_GET['max_rating'] ?? 0);
    if ($maxRating >= 1 && $maxRating <= 5) {
        $where[] = 'cr.rating <= ?';
        $args[] = $maxRating;
    }
    $followUp = clean_string((string) ($_GET['follow_up'] ?? ''), 20);
    if (in_array($followUp, ['open', 'in_progress', 'resolved'], true)) {
        $where[] = 'cr.follow_up_status = ?';
        $args[] = $followUp;
    }
    $q = clean_string((string) ($_GET['q'] ?? ''), 100);
    if ($q !== '') {
        $where[] = '(cr.feedback LIKE ? OR u.name LIKE ?)';
        $args[] = tb_like($q);
        $args[] = tb_like($q);
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    [$limit, $offset] = tb_pagination(50, 200);
    $count = db()->prepare('SELECT COUNT(*) AS c FROM customer_reviews cr LEFT JOIN users u ON u.id = cr.user_id' . $whereSql);
    $count->execute($args);
    $stmt = db()->prepare('SELECT cr.id, cr.user_id, u.name AS customer_name, cr.project_id, cr.work_order_id, cr.service_ticket_id, cr.rating, cr.feedback, cr.moderation_status, cr.public_visible, cr.public_consent, cr.follow_up_status, cr.moderation_note, cr.source, cr.created_at, cr.moderated_at FROM customer_reviews cr LEFT JOIN users u ON u.id = cr.user_id' . $whereSql . ' ORDER BY cr.created_at DESC, cr.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute($args);
    $requests = db()->query("SELECT status, COUNT(*) AS c FROM review_requests GROUP BY status")->fetchAll();
    send_json(['ok' => true, 'reviews' => $stmt->fetchAll(), 'total' => (int) ($count->fetch()['c'] ?? 0), 'limit' => $limit, 'offset' => $offset, 'request_stats' => array_column($requests, 'c', 'status')]);
}

function platform_post_reviews(array $user, array $payload): void
{
    $action = clean_string($payload['action'] ?? 'submit', 40);
    if ($action === 'submit') {
        enforce_rate_limit('review_submit', 20, 3600);
        $rating = (int) ($payload['rating'] ?? 0);
        $feedback = clean_string($payload['feedback'] ?? '', 4000);
        if ($rating < 1 || $rating > 5) {
            send_json(['ok' => false, 'error' => 'A csillag érték 1 és 5 között lehet.'], 422);
        }
        $links = ['project_id' => 'projects', 'work_order_id' => 'work_orders', 'service_ticket_id' => 'service_tickets'];
        $values = [];
        foreach ($links as $field => $table) {
            $values[$field] = platform_int_nullable($payload[$field] ?? null);
            if ($values[$field] !== null && !platform_is_admin($user)) {
                $own = db()->prepare('SELECT id FROM ' . $table . ' WHERE id = ? AND user_id = ? LIMIT 1');
                $own->execute([$values[$field], (int) $user['id']]);
                if (!$own->fetch()) {
                    send_json(['ok' => false, 'error' => 'Csak saját munkát értékelhet.'], 403);
                }
            }
        }
        $stmt = db()->prepare("INSERT INTO customer_reviews (user_id, project_id, work_order_id, service_ticket_id, rating, feedback, moderation_status, public_visible, public_consent, source) VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?, 'portal')");
        $stmt->execute([(int) $user['id'], $values['project_id'], $values['work_order_id'], $values['service_ticket_id'], $rating, $feedback !== '' ? $feedback : null, !empty($payload['public_consent']) ? 1 : 0]);
        $reviewId = (int) db()->lastInsertId();
        tb_review_after_submit($reviewId, $rating);
        send_json(['ok' => true, 'id' => $reviewId], 201);
    }
    $admin = platform_require_roles(platform_admin_roles());
    $id = (int) ($payload['id'] ?? 0);
    if ($action === 'moderate') {
        $status = platform_enum(clean_string($payload['moderation_status'] ?? '', 20), ['pending', 'approved', 'rejected'], 'moderációs státusz');
        $note = clean_string((string) ($payload['moderation_note'] ?? ''), 500);
        $current = db()->prepare('SELECT id, moderation_status, public_visible, public_consent FROM customer_reviews WHERE id = ? LIMIT 1');
        $current->execute([$id]);
        $row = $current->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'Az értékelés nem található.'], 404);
        }
        // Publikus megjelenés csak jóváhagyott és hozzájárulással rendelkező értékelésnél.
        $publicVisible = ($status === 'approved' && !empty($payload['public_visible']) && (int) $row['public_consent'] === 1) ? 1 : 0;
        $stmt = db()->prepare('UPDATE customer_reviews SET moderation_status = ?, public_visible = ?, moderation_note = ?, moderated_at = NOW(), moderated_by_user_id = ? WHERE id = ?');
        $stmt->execute([$status, $publicVisible, $note !== '' ? $note : null, (int) $admin['id'], $id]);
        log_admin_activity((int) $admin['id'], 'review_moderated', 'customer_review', $id, ['from' => $row['moderation_status'], 'to' => $status, 'public_visible' => $publicVisible]);
        send_json(['ok' => true, 'public_visible' => $publicVisible, 'consent_missing' => !empty($payload['public_visible']) && (int) $row['public_consent'] !== 1]);
    }
    if ($action === 'follow_up') {
        $status = platform_enum(clean_string($payload['follow_up_status'] ?? '', 20), ['open', 'in_progress', 'resolved'], 'follow-up státusz');
        $stmt = db()->prepare('UPDATE customer_reviews SET follow_up_status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        log_admin_activity((int) $admin['id'], 'review_follow_up', 'customer_review', $id, ['status' => $status]);
        send_json(['ok' => true]);
    }
    if ($action === 'request_review') {
        $sourceType = platform_enum(clean_string($payload['source_type'] ?? '', 20), ['project', 'work_order', 'service_ticket'], 'forrás típus');
        $result = tb_review_request_create($sourceType, (int) ($payload['source_id'] ?? 0));
        log_admin_activity((int) $admin['id'], 'review_request_manual', $sourceType, (int) ($payload['source_id'] ?? 0));
        send_json(['ok' => true, 'result' => $result]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott értékelés művelet.'], 405);
}

/* ---------------- G) SLA ---------------- */

function platform_get_sla(array $user): void
{
    platform_require_roles(platform_admin_roles());
    $days = max(1, min(365, (int) ($_GET['days'] ?? 90)));
    $atRisk = db()->query("SELECT id, subject, priority, status, sla_due_at, escalation_level, created_at FROM service_tickets WHERE status NOT IN ('resolved','closed') AND sla_due_at IS NOT NULL ORDER BY sla_due_at ASC LIMIT 50")->fetchAll();
    send_json(['ok' => true, 'report' => tb_sla_report($days), 'open_tickets' => $atRisk, 'config' => tb_workflow_rule_config('service_sla_monitor')]);
}

function platform_post_sla(array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $result = tb_sla_run(tb_workflow_rule_config('service_sla_monitor'));
    log_admin_activity((int) $admin['id'], 'sla_manual_run', 'service_ticket', null, $result);
    send_json(['ok' => true, 'result' => $result]);
}

/* ---------------- H) Számlázás ---------------- */

function platform_get_invoices(array $user): void
{
    platform_require_roles(platform_superadmin_roles());
    $where = [];
    $args = [];
    $status = clean_string((string) ($_GET['status'] ?? ''), 20);
    if ($status !== '') {
        $where[] = 'status = ?';
        $args[] = $status;
    }
    [$limit, $offset] = tb_pagination(50, 200);
    $stmt = db()->prepare('SELECT id, source_type, source_id, provider, mode, status, customer_name, customer_email, total_cents, currency, external_id, error_message, attempts, manual_reference, created_at, updated_at FROM invoice_drafts' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute($args);
    send_json(['ok' => true, 'provider' => tb_invoice_provider_summary(), 'drafts' => $stmt->fetchAll(), 'limit' => $limit, 'offset' => $offset]);
}

function platform_post_invoices(array $payload): void
{
    $admin = platform_require_roles(platform_superadmin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    $id = (int) ($payload['id'] ?? 0);
    if ($action === 'prepare') {
        $sourceType = platform_enum(clean_string($payload['source_type'] ?? '', 20), ['order', 'project'], 'forrás típus');
        $result = tb_invoice_prepare($sourceType, (int) ($payload['source_id'] ?? 0));
        log_admin_activity((int) $admin['id'], 'invoice_prepare_manual', $sourceType, (int) ($payload['source_id'] ?? 0), $result);
        send_json(['ok' => true, 'result' => $result]);
    }
    $stmt = db()->prepare('SELECT id, source_type, source_id, status FROM invoice_drafts WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $draft = $stmt->fetch();
    if (!$draft) {
        send_json(['ok' => false, 'error' => 'A számlatervezet nem található.'], 404);
    }
    if ($action === 'retry') {
        $data = tb_invoice_build_draft((string) $draft['source_type'], (int) $draft['source_id']);
        $result = tb_invoice_send_draft($id, $data + ['source_type' => $draft['source_type'], 'source_id' => (int) $draft['source_id']]);
        log_admin_activity((int) $admin['id'], 'invoice_retry', 'invoice_draft', $id, $result);
        send_json(['ok' => true, 'result' => $result]);
    }
    if ($action === 'mark_manual_done') {
        $reference = clean_string((string) ($payload['manual_reference'] ?? ''), 120);
        if ($reference === '') {
            send_json(['ok' => false, 'error' => 'A kézzel kiállított számla sorszáma kötelező.'], 422);
        }
        $upd = db()->prepare("UPDATE invoice_drafts SET status = 'manual_done', manual_reference = ?, processed_by_user_id = ? WHERE id = ? AND status IN ('draft','failed','manual_required','pending')");
        $upd->execute([$reference, (int) $admin['id'], $id]);
        if ($upd->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Ez a tervezet már le van zárva.'], 409);
        }
        log_admin_activity((int) $admin['id'], 'invoice_manual_done', 'invoice_draft', $id, ['reference' => $reference]);
        send_json(['ok' => true]);
    }
    if ($action === 'cancel') {
        $upd = db()->prepare("UPDATE invoice_drafts SET status = 'cancelled', processed_by_user_id = ? WHERE id = ? AND status IN ('draft','failed','manual_required')");
        $upd->execute([(int) $admin['id'], $id]);
        if ($upd->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Ez a tervezet nem vonható vissza.'], 409);
        }
        log_admin_activity((int) $admin['id'], 'invoice_cancelled', 'invoice_draft', $id);
        send_json(['ok' => true]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott számlázási művelet.'], 405);
}

/* ---------------- F) Globális kereső + parancspaletta ---------------- */

function platform_get_search(array $user): void
{
    enforce_rate_limit('global_search', 120, 60);
    $q = clean_string((string) ($_GET['q'] ?? ''), 120);
    $type = clean_string((string) ($_GET['type'] ?? ''), 30);
    $limit = $type !== '' ? max(1, min(50, (int) ($_GET['limit'] ?? 20))) : max(1, min(10, (int) ($_GET['limit'] ?? 5)));
    $offset = $type !== '' ? max(0, (int) ($_GET['offset'] ?? 0)) : 0;
    send_json(['ok' => true] + tb_global_search($user, $q, $type, $limit, $offset));
}

function platform_get_palette(array $user): void
{
    $actions = [];
    if (platform_is_admin($user)) {
        $actions[] = ['id' => 'new_project', 'label' => 'Új projekt', 'keywords' => 'projekt létrehozás', 'kind' => 'form'];
        $actions[] = ['id' => 'new_work_order', 'label' => 'Új munkalap', 'keywords' => 'munkalap létrehozás', 'kind' => 'form'];
        $actions[] = ['id' => 'new_ticket', 'label' => 'Új szerviz ticket', 'keywords' => 'ticket szerviz hiba', 'kind' => 'form'];
        $actions[] = ['id' => 'change_status', 'label' => 'Státuszváltás (projekt / munkalap / ticket)', 'keywords' => 'státusz állapot', 'kind' => 'form'];
        $actions[] = ['id' => 'open_customer', 'label' => 'Ügyfél megnyitása', 'keywords' => 'ügyfél vevő idővonal', 'kind' => 'search', 'type' => 'customer'];
        $actions[] = ['id' => 'goto_profit', 'label' => 'Profit dashboard', 'keywords' => 'költség margin', 'kind' => 'nav', 'target' => '#profit'];
        $actions[] = ['id' => 'goto_workflows', 'label' => 'Workflow központ', 'keywords' => 'automatizálás job', 'kind' => 'nav', 'target' => '#workflows'];
        $actions[] = ['id' => 'goto_sla', 'label' => 'SLA riport', 'keywords' => 'szerviz határidő', 'kind' => 'nav', 'target' => '#sla'];
        $actions[] = ['id' => 'goto_reviews', 'label' => 'Értékelések moderálása', 'keywords' => 'vélemény', 'kind' => 'nav', 'target' => '#reviews'];
    }
    if (in_array((string) $user['role'], platform_worker_roles(), true)) {
        $actions[] = ['id' => 'goto_mobile', 'label' => 'Mobil munkalapok', 'keywords' => 'terep munkatárs', 'kind' => 'link', 'target' => 'mobile.html'];
    }
    $actions[] = ['id' => 'goto_security', 'label' => 'Biztonság és 2FA', 'keywords' => 'jelszó munkamenet', 'kind' => 'nav', 'target' => '#security'];
    send_json(['ok' => true, 'actions' => $actions]);
}

function platform_post_quick(array $user, array $payload): void
{
    $admin = platform_require_roles(platform_admin_roles());
    $action = clean_string($payload['action'] ?? '', 40);
    $uid = (int) $admin['id'];
    if ($action === 'new_project') {
        $title = clean_string((string) ($payload['title'] ?? ''), 180);
        $customerId = platform_int_nullable($payload['user_id'] ?? null);
        if ($title === '') {
            send_json(['ok' => false, 'error' => 'A projekt neve kötelező.'], 422);
        }
        db()->prepare("INSERT INTO projects (user_id, title, status) VALUES (?, ?, 'draft')")->execute([$customerId, $title]);
        $id = (int) db()->lastInsertId();
        log_admin_activity($uid, 'project_created_quick', 'project', $id);
        send_json(['ok' => true, 'id' => $id, 'type' => 'project'], 201);
    }
    if ($action === 'new_work_order') {
        $title = clean_string((string) ($payload['title'] ?? ''), 180);
        $projectId = platform_int_nullable($payload['project_id'] ?? null);
        $assignee = platform_int_nullable($payload['assigned_to_user_id'] ?? null);
        if ($title === '') {
            send_json(['ok' => false, 'error' => 'A munkalap neve kötelező.'], 422);
        }
        $customerId = null;
        if ($projectId !== null) {
            $p = db()->prepare('SELECT user_id FROM projects WHERE id = ? LIMIT 1');
            $p->execute([$projectId]);
            $project = $p->fetch();
            if (!$project) {
                send_json(['ok' => false, 'error' => 'A projekt nem található.'], 404);
            }
            $customerId = $project['user_id'] !== null ? (int) $project['user_id'] : null;
        }
        db()->prepare("INSERT INTO work_orders (project_id, user_id, assigned_to_user_id, title, status, priority) VALUES (?, ?, ?, ?, 'todo', 'normal')")->execute([$projectId, $customerId, $assignee, $title]);
        $id = (int) db()->lastInsertId();
        log_admin_activity($uid, 'work_order_created_quick', 'work_order', $id);
        send_json(['ok' => true, 'id' => $id, 'type' => 'work_order'], 201);
    }
    if ($action === 'new_ticket') {
        $subject = clean_string((string) ($payload['subject'] ?? ''), 180);
        $priority = platform_enum(clean_string((string) ($payload['priority'] ?? 'normal'), 20), ['low', 'normal', 'high', 'emergency'], 'prioritás');
        $description = clean_string((string) ($payload['description'] ?? ''), 4000);
        if ($subject === '' || $description === '') {
            send_json(['ok' => false, 'error' => 'Tárgy és leírás kötelező.'], 422);
        }
        db()->prepare("INSERT INTO service_tickets (user_id, subject, description, priority, status, sla_due_at, created_by_customer) VALUES (?, ?, ?, ?, 'open', ?, 0)")
            ->execute([platform_int_nullable($payload['user_id'] ?? null), $subject, $description, $priority, tb_sla_due_at($priority)]);
        $id = (int) db()->lastInsertId();
        log_admin_activity($uid, 'ticket_created_quick', 'service_ticket', $id);
        tb_safe_event('ticket_created', ['ticket_id' => $id, 'priority' => $priority]);
        if ($priority === 'emergency') {
            tb_sla_emergency_alert($id);
        }
        send_json(['ok' => true, 'id' => $id, 'type' => 'ticket'], 201);
    }
    if ($action === 'change_status') {
        $map = [
            'project' => ['projects', ['draft', 'survey_scheduled', 'quoted', 'approved', 'scheduled', 'in_progress', 'on_hold', 'completed', 'cancelled']],
            'work_order' => ['work_orders', ['todo', 'in_progress', 'blocked', 'done', 'cancelled']],
            'ticket' => ['service_tickets', ['open', 'triaged', 'scheduled', 'in_progress', 'waiting_customer', 'resolved', 'closed', 'rejected']],
        ];
        $entity = clean_string((string) ($payload['entity'] ?? ''), 20);
        if (!isset($map[$entity])) {
            send_json(['ok' => false, 'error' => 'Érvénytelen entitás.'], 422);
        }
        [$table, $allowed] = $map[$entity];
        $status = platform_enum(clean_string((string) ($payload['status'] ?? ''), 30), $allowed, 'státusz');
        $id = (int) ($payload['id'] ?? 0);
        $cur = db()->prepare('SELECT status FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) {
            send_json(['ok' => false, 'error' => 'A rekord nem található.'], 404);
        }
        db()->prepare('UPDATE ' . $table . ' SET status = ? WHERE id = ?')->execute([$status, $id]);
        log_admin_activity($uid, $entity . '_status_quick', $entity, $id, ['from' => $row['status'], 'to' => $status]);
        if ($entity === 'project' && $status !== $row['status']) {
            tb_safe_event('project_status', ['project_id' => $id, 'to' => $status]);
        }
        if ($entity === 'work_order' && $status === 'done' && $row['status'] !== 'done') {
            db()->prepare('UPDATE work_orders SET closed_at = COALESCE(closed_at, NOW()) WHERE id = ?')->execute([$id]);
            tb_safe_event('work_order_closed', ['work_order_id' => $id]);
        }
        if ($entity === 'ticket' && in_array($status, ['resolved', 'closed'], true)) {
            db()->prepare('UPDATE service_tickets SET closed_at = COALESCE(closed_at, NOW()) WHERE id = ?')->execute([$id]);
        }
        send_json(['ok' => true]);
    }
    send_json(['ok' => false, 'error' => 'Nem támogatott gyorsművelet.'], 405);
}

/* ---------------- J) Diagnosztika ---------------- */

function platform_get_health(array $user): void
{
    platform_require_roles(platform_superadmin_roles());
    send_json(['ok' => true] + tb_health_checks());
}

/* ---------------- Partnerek / idővonal / szervezet (változatlan funkciók) ---------------- */

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

/* ---------------- Router ---------------- */

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

try {
    if ($method === 'GET') {
        switch ($module) {
            case 'mobile': platform_get_mobile($user); break;
            case 'signatures': platform_get_signatures($user); break;
            case 'costs': platform_get_costs($user); break;
            case 'profit': platform_get_profit($user); break;
            case 'workflows': platform_get_workflows($user); break;
            case 'security': platform_get_security($user); break;
            case 'reviews': platform_get_reviews($user); break;
            case 'sla': platform_get_sla($user); break;
            case 'invoices': platform_get_invoices($user); break;
            case 'health': platform_get_health($user); break;
            case 'search': platform_get_search($user); break;
            case 'palette': platform_get_palette($user); break;
            case 'partners': platform_list_partners(); break;
            case 'timeline': platform_list_timeline($user); break;
            case 'org': platform_list_org(); break;
        }
        send_json(['ok' => false, 'error' => 'Nem támogatott modul.'], 405);
    }

    if ($method === 'POST') {
        switch ($module) {
            case 'mobile': platform_post_mobile($user, $payload); break;
            case 'signatures': platform_post_signatures($user, $payload); break;
            case 'costs': platform_post_costs($user, $payload); break;
            case 'workflows': platform_post_workflows($payload); break;
            case 'security': platform_post_security($user, $payload); break;
            case 'reviews': platform_post_reviews($user, $payload); break;
            case 'sla': platform_post_sla($payload); break;
            case 'invoices': platform_post_invoices($payload); break;
            case 'quick': platform_post_quick($user, $payload); break;
            case 'automation':
                platform_require_roles(platform_admin_roles());
                send_json(['ok' => true, 'tick' => tb_automation_tick(false)]);
                break;
            case 'partners': platform_post_partners($payload); break;
            case 'timeline': platform_post_timeline($user, $payload); break;
            case 'org': platform_post_org($payload); break;
        }
        send_json(['ok' => false, 'error' => 'Nem támogatott modul.'], 405);
    }
} catch (Throwable $e) {
    platform_fail($e);
}

send_json(['ok' => false, 'error' => 'Nem támogatott kérés.'], 405);
