<?php
declare(strict_types=1);

/*
 * Munkatársi mobil (PWA) szinkron: idempotens, konfliktusérzékeny műveletek.
 * Ütközés esetén SOHA nincs csendes felülírás: a művelet "conflict" állapotba kerül,
 * és manuálisan kell feloldani (keep_server / apply_mine).
 */

function tb_mobile_op_types(): array
{
    return ['status', 'checklist', 'note', 'time_log', 'material'];
}

function tb_mobile_work_order_statuses(): array
{
    return ['todo', 'in_progress', 'blocked', 'done', 'cancelled'];
}

function tb_mobile_can_work_on(array $user, array $workOrder): bool
{
    if (tb_is_backoffice($user)) {
        return true;
    }
    return ($user['role'] ?? '') === 'field_worker' && (int) $user['id'] === (int) ($workOrder['assigned_to_user_id'] ?? 0);
}

function tb_mobile_list(array $user, string $status = ''): array
{
    $where = [];
    $args = [];
    if (!tb_is_backoffice($user)) {
        $where[] = 'wo.assigned_to_user_id = ?';
        $args[] = (int) $user['id'];
    }
    if ($status !== '' && $status !== 'all') {
        if (!in_array($status, tb_mobile_work_order_statuses(), true)) {
            throw new InvalidArgumentException('Érvénytelen munkalap státusz.');
        }
        $where[] = 'wo.status = ?';
        $args[] = $status;
    } else {
        $where[] = "wo.status NOT IN ('cancelled')";
    }
    $sql = 'SELECT wo.id, wo.project_id, wo.title, wo.location, wo.status, wo.priority, wo.due_at, wo.actual_minutes, wo.updated_at, p.title AS project_title,
            (SELECT COUNT(*) FROM work_order_checklists c WHERE c.work_order_id = wo.id) AS checklist_total,
            (SELECT COUNT(*) FROM work_order_checklists c WHERE c.work_order_id = wo.id AND c.is_done = 1) AS checklist_done
            FROM work_orders wo LEFT JOIN projects p ON p.id = wo.project_id';
    $sql .= ' WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(wo.status, 'in_progress','todo','blocked','done','cancelled'), wo.due_at IS NULL, wo.due_at ASC, wo.updated_at DESC LIMIT 200";
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function tb_mobile_detail(array $user, int $id): ?array
{
    $stmt = db()->prepare('SELECT wo.id, wo.project_id, wo.user_id, wo.assigned_to_user_id, wo.title, wo.location, wo.work_type, wo.description, wo.status, wo.priority, wo.planned_minutes, wo.actual_minutes, wo.due_at, wo.handover_ready, wo.closed_at, wo.updated_at, p.title AS project_title, u.name AS customer_name, u.phone AS customer_phone FROM work_orders wo LEFT JOIN projects p ON p.id = wo.project_id LEFT JOIN users u ON u.id = wo.user_id WHERE wo.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $wo = $stmt->fetch();
    if (!$wo) {
        return null;
    }
    if (!tb_mobile_can_work_on($user, $wo)) {
        throw new UnexpectedValueException('Nincs jogosultsága ehhez a munkalaphoz.');
    }
    $checklist = db()->prepare('SELECT id, item_text, is_done, updated_at FROM work_order_checklists WHERE work_order_id = ? ORDER BY id ASC');
    $checklist->execute([$id]);
    $notes = db()->prepare('SELECT n.id, n.note, n.created_at, u.name AS author_name FROM work_order_notes n LEFT JOIN users u ON u.id = n.author_user_id WHERE n.work_order_id = ? ORDER BY n.created_at DESC LIMIT 50');
    $notes->execute([$id]);
    $times = db()->prepare('SELECT t.id, t.minutes, t.started_at, t.note, t.created_at, u.name AS user_name FROM work_order_time_logs t LEFT JOIN users u ON u.id = t.user_id WHERE t.work_order_id = ? ORDER BY t.created_at DESC LIMIT 50');
    $times->execute([$id]);
    $materials = db()->prepare("SELECT id, title, quantity, unit, created_at FROM work_order_cost_entries WHERE work_order_id = ? AND entry_type = 'material' ORDER BY created_at DESC LIMIT 100");
    $materials->execute([$id]);
    $sig = db()->prepare("SELECT id, signer_name, signed_at, status FROM digital_signatures WHERE document_type = 'work_order' AND document_id = ? ORDER BY id DESC LIMIT 5");
    $sig->execute([$id]);
    unset($wo['user_id']);
    return ['work_order' => $wo, 'checklist' => $checklist->fetchAll(), 'notes' => $notes->fetchAll(), 'time_logs' => $times->fetchAll(), 'materials' => $materials->fetchAll(), 'signatures' => $sig->fetchAll()];
}

function tb_labor_rates_cents(): array
{
    return [
        'rate' => max(0, (int) round((float) env_or_fallback(['TB_LABOR_HOURLY_RATE_HUF'], '0') * 100)),
        'cost' => max(0, (int) round((float) env_or_fallback(['TB_LABOR_HOURLY_COST_HUF'], '0') * 100)),
    ];
}

/**
 * Ütközés vizsgálat. Visszatérés: null (nincs ütközés), vagy ['reason' => ..., 'server' => ...].
 */
function tb_mobile_detect_conflict(PDO $pdo, array $wo, string $type, array $data, array $base): ?array
{
    $closed = in_array((string) $wo['status'], ['done', 'cancelled'], true);
    if ($type === 'status') {
        $target = (string) ($data['status'] ?? '');
        $baseStatus = (string) ($base['status'] ?? '');
        if ($baseStatus !== '' && $baseStatus !== (string) $wo['status'] && $target !== (string) $wo['status']) {
            return ['reason' => 'status_changed', 'message' => 'A munkalap státuszát időközben más módosította.', 'server' => ['status' => $wo['status'], 'updated_at' => $wo['updated_at']]];
        }
        return null;
    }
    if ($type === 'checklist') {
        $item = $pdo->prepare('SELECT id, item_text, is_done FROM work_order_checklists WHERE id = ? AND work_order_id = ? LIMIT 1');
        $item->execute([(int) ($data['item_id'] ?? 0), (int) $wo['id']]);
        $row = $item->fetch();
        if (!$row) {
            return ['reason' => 'item_missing', 'message' => 'A checklist tétel időközben törölve lett.', 'server' => null];
        }
        $target = !empty($data['is_done']) ? 1 : 0;
        if (array_key_exists('is_done', $base) && (int) $base['is_done'] !== (int) $row['is_done'] && (int) $row['is_done'] !== $target) {
            return ['reason' => 'item_changed', 'message' => 'A checklist tételt időközben más módosította.', 'server' => ['item_id' => (int) $row['id'], 'is_done' => (int) $row['is_done'], 'item_text' => $row['item_text']]];
        }
        if ($closed) {
            return ['reason' => 'work_order_closed', 'message' => 'A munkalap időközben lezárásra került.', 'server' => ['status' => $wo['status']]];
        }
        return null;
    }
    if (in_array($type, ['time_log', 'material'], true) && $closed) {
        return ['reason' => 'work_order_closed', 'message' => 'A munkalap időközben lezárásra került, az adat rögzítése jóváhagyást igényel.', 'server' => ['status' => $wo['status']]];
    }
    return null;
}

function tb_mobile_validate_op(string $type, array $data): array
{
    if ($type === 'status') {
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, tb_mobile_work_order_statuses(), true)) {
            throw new InvalidArgumentException('Érvénytelen munkalap státusz.');
        }
        return ['status' => $status];
    }
    if ($type === 'checklist') {
        $itemId = (int) ($data['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new InvalidArgumentException('Hiányzó checklist tétel.');
        }
        return ['item_id' => $itemId, 'is_done' => !empty($data['is_done']) ? 1 : 0];
    }
    if ($type === 'note') {
        $note = clean_string((string) ($data['note'] ?? ''), 2000);
        if ($note === '') {
            throw new InvalidArgumentException('A megjegyzés nem lehet üres.');
        }
        return ['note' => $note];
    }
    if ($type === 'time_log') {
        $minutes = (int) ($data['minutes'] ?? 0);
        if ($minutes < 1 || $minutes > 1440) {
            throw new InvalidArgumentException('A munkaidő 1 és 1440 perc között lehet.');
        }
        $startedAt = null;
        if (!empty($data['started_at'])) {
            $ts = strtotime((string) $data['started_at']);
            if ($ts === false) {
                throw new InvalidArgumentException('Érvénytelen kezdési időpont.');
            }
            $startedAt = date('Y-m-d H:i:s', $ts);
        }
        return ['minutes' => $minutes, 'started_at' => $startedAt, 'note' => clean_string((string) ($data['note'] ?? ''), 500)];
    }
    if ($type === 'material') {
        $title = clean_string((string) ($data['title'] ?? ''), 180);
        $qty = round((float) ($data['quantity'] ?? 0), 2);
        if ($title === '' || $qty <= 0 || $qty > 100000) {
            throw new InvalidArgumentException('Anyag megnevezése és pozitív mennyiség kötelező.');
        }
        return ['title' => $title, 'quantity' => $qty, 'unit' => clean_string((string) ($data['unit'] ?? 'db'), 20), 'product_id' => max(0, (int) ($data['product_id'] ?? 0))];
    }
    throw new InvalidArgumentException('Nem támogatott művelet típus.');
}

function tb_mobile_apply(PDO $pdo, array $user, array $wo, string $type, array $data): array
{
    $uid = (int) $user['id'];
    $woId = (int) $wo['id'];
    if ($type === 'status') {
        $closing = in_array($data['status'], ['done'], true);
        $pdo->prepare('UPDATE work_orders SET status = ?, closed_at = ' . ($closing ? 'COALESCE(closed_at, NOW())' : 'closed_at') . ' WHERE id = ?')->execute([$data['status'], $woId]);
        if ((int) ($wo['project_id'] ?? 0) > 0 && $data['status'] !== $wo['status']) {
            $pdo->prepare("INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note, related_type, related_id) VALUES (?, ?, 'work_order', ?, 'work_order', ?)")
                ->execute([(int) $wo['project_id'], $uid, '[Mobil] Munkalap státusz: ' . $wo['status'] . ' → ' . $data['status'], $woId]);
        }
        return ['status' => $data['status']];
    }
    if ($type === 'checklist') {
        $pdo->prepare('UPDATE work_order_checklists SET is_done = ? WHERE id = ? AND work_order_id = ?')->execute([$data['is_done'], $data['item_id'], $woId]);
        return ['item_id' => $data['item_id'], 'is_done' => $data['is_done']];
    }
    if ($type === 'note') {
        $pdo->prepare("INSERT INTO work_order_notes (work_order_id, author_user_id, note, source) VALUES (?, ?, ?, 'mobile')")->execute([$woId, $uid, $data['note']]);
        return ['note_id' => (int) $pdo->lastInsertId()];
    }
    if ($type === 'time_log') {
        $pdo->prepare('INSERT INTO work_order_time_logs (work_order_id, user_id, minutes, started_at, note) VALUES (?, ?, ?, ?, ?)')->execute([$woId, $uid, $data['minutes'], $data['started_at'], $data['note'] !== '' ? $data['note'] : null]);
        $logId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE work_orders SET actual_minutes = COALESCE(actual_minutes, 0) + ? WHERE id = ?')->execute([$data['minutes'], $woId]);
        $rates = tb_labor_rates_cents();
        if ($rates['rate'] > 0 || $rates['cost'] > 0) {
            $hours = round($data['minutes'] / 60, 2);
            $pdo->prepare("INSERT INTO work_order_cost_entries (project_id, work_order_id, entry_type, title, quantity, unit, unit_price_cents, internal_unit_cost_cents, billable_to_customer, created_by_user_id) VALUES (?, ?, 'labor', ?, ?, 'óra', ?, ?, 1, ?)")
                ->execute([$wo['project_id'], $woId, 'Munkaidő (mobil) – ' . clean_string((string) ($user['name'] ?? ''), 80), $hours, $rates['rate'], $rates['cost'] > 0 ? $rates['cost'] : null, $uid]);
        }
        return ['time_log_id' => $logId, 'minutes' => $data['minutes']];
    }
    if ($type === 'material') {
        $unitPrice = 0;
        if ($data['product_id'] > 0) {
            $p = $pdo->prepare('SELECT price FROM products WHERE id = ? LIMIT 1');
            $p->execute([$data['product_id']]);
            $unitPrice = (int) (($p->fetch() ?: [])['price'] ?? 0) * 100;
        }
        $pdo->prepare("INSERT INTO work_order_cost_entries (project_id, work_order_id, entry_type, title, quantity, unit, unit_price_cents, billable_to_customer, note, created_by_user_id) VALUES (?, ?, 'material', ?, ?, ?, ?, 1, ?, ?)")
            ->execute([$wo['project_id'], $woId, $data['title'], $data['quantity'], $data['unit'] !== '' ? $data['unit'] : null, $unitPrice, 'Mobil rögzítés', $uid]);
        return ['cost_entry_id' => (int) $pdo->lastInsertId()];
    }
    throw new InvalidArgumentException('Nem támogatott művelet típus.');
}

/**
 * Egy offline sorból érkező művelet feldolgozása.
 *
 * @return array{http:int, body:array}
 */
function tb_mobile_sync(array $user, array $input): array
{
    $key = (string) ($input['idempotency_key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{8,120}$/', $key)) {
        throw new InvalidArgumentException('Érvénytelen idempotency kulcs.');
    }
    $woId = (int) ($input['work_order_id'] ?? 0);
    $type = (string) ($input['op_type'] ?? '');
    if ($woId <= 0 || !in_array($type, tb_mobile_op_types(), true)) {
        throw new InvalidArgumentException('Munkalap azonosító és művelet típus kötelező.');
    }
    $data = tb_mobile_validate_op($type, is_array($input['data'] ?? null) ? $input['data'] : []);
    $base = is_array($input['base'] ?? null) ? $input['base'] : [];
    $uid = (int) $user['id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, project_id, assigned_to_user_id, status, actual_minutes, updated_at FROM work_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$woId]);
        $wo = $stmt->fetch();
        if (!$wo) {
            throw new OutOfBoundsException('A munkalap nem található.');
        }
        if (!tb_mobile_can_work_on($user, $wo)) {
            throw new UnexpectedValueException('Nincs jogosultsága ehhez a munkalaphoz.');
        }
        $seen = $pdo->prepare('SELECT id, actor_user_id, work_order_id, operation_status, conflict_snapshot FROM work_order_sync_operations WHERE idempotency_key = ? LIMIT 1');
        $seen->execute([$key]);
        $existing = $seen->fetch();
        if ($existing) {
            $pdo->commit();
            if ((int) $existing['actor_user_id'] !== $uid || (int) $existing['work_order_id'] !== $woId) {
                throw new InvalidArgumentException('Az idempotency kulcs már más művelethez tartozik.');
            }
            if ($existing['operation_status'] === 'conflict') {
                return ['http' => 409, 'body' => ['ok' => false, 'code' => 'conflict', 'duplicate' => true, 'operation_id' => (int) $existing['id'], 'error' => 'Ütközés: manuális feloldás szükséges.', 'conflict' => tb_json_decode_array($existing['conflict_snapshot'])]];
            }
            return ['http' => 200, 'body' => ['ok' => true, 'duplicate' => true, 'operation_id' => (int) $existing['id'], 'status' => $existing['operation_status']]];
        }

        $conflict = tb_mobile_detect_conflict($pdo, $wo, $type, $data, $base);
        $record = ['op_type' => $type, 'data' => $data, 'base' => $base, 'client_created_at' => clean_string((string) ($input['client_created_at'] ?? ''), 40)];
        $insert = $pdo->prepare('INSERT INTO work_order_sync_operations (idempotency_key, work_order_id, actor_user_id, operation_type, payload_json, operation_status, conflict_snapshot, applied_at) VALUES (?, ?, ?, ?, ?, ?, ?, ' . ($conflict ? 'NULL' : 'NOW()') . ')');
        if ($conflict) {
            $insert->execute([$key, $woId, $uid, $type, tb_json($record), 'conflict', tb_json($conflict)]);
            $opId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return ['http' => 409, 'body' => ['ok' => false, 'code' => 'conflict', 'operation_id' => $opId, 'error' => $conflict['message'], 'conflict' => $conflict]];
        }
        $result = tb_mobile_apply($pdo, $user, $wo, $type, $data);
        $insert->execute([$key, $woId, $uid, $type, tb_json($record), 'applied', null]);
        $opId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    if ($type === 'status' && $data['status'] === 'done' && $wo['status'] !== 'done') {
        tb_notify(null, 'admin', 'Munkalap elkészült (mobil)', 'Munkalap #' . $woId . ' státusza: kész.', '/admin#work-orders');
    }
    return ['http' => 200, 'body' => ['ok' => true, 'operation_id' => $opId, 'status' => 'applied', 'result' => $result]];
}

function tb_mobile_conflicts(array $user): array
{
    $sql = "SELECT s.id, s.work_order_id, s.operation_type, s.payload_json, s.conflict_snapshot, s.created_at, wo.title AS work_order_title, u.name AS actor_name
            FROM work_order_sync_operations s LEFT JOIN work_orders wo ON wo.id = s.work_order_id LEFT JOIN users u ON u.id = s.actor_user_id
            WHERE s.operation_status = 'conflict'";
    $args = [];
    if (!tb_is_backoffice($user)) {
        $sql .= ' AND s.actor_user_id = ?';
        $args[] = (int) $user['id'];
    }
    $stmt = db()->prepare($sql . ' ORDER BY s.created_at DESC LIMIT 100');
    $stmt->execute($args);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['payload'] = tb_json_decode_array($row['payload_json']);
        $row['conflict'] = tb_json_decode_array($row['conflict_snapshot']);
        unset($row['payload_json'], $row['conflict_snapshot']);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Konfliktus manuális feloldása: keep_server (eldobjuk a helyi módosítást) vagy apply_mine (tudatos felülírás).
 */
function tb_mobile_resolve_conflict(array $user, int $operationId, string $resolution): array
{
    if (!in_array($resolution, ['keep_server', 'apply_mine'], true)) {
        throw new InvalidArgumentException('Érvénytelen feloldási mód.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, work_order_id, actor_user_id, payload_json FROM work_order_sync_operations WHERE id = ? AND operation_status = 'conflict' FOR UPDATE");
        $stmt->execute([$operationId]);
        $op = $stmt->fetch();
        if (!$op) {
            throw new OutOfBoundsException('A konfliktus nem található vagy már fel lett oldva.');
        }
        if (!tb_is_backoffice($user) && (int) $op['actor_user_id'] !== (int) $user['id']) {
            throw new UnexpectedValueException('Nincs jogosultsága a konfliktus feloldásához.');
        }
        $result = null;
        if ($resolution === 'apply_mine') {
            $woStmt = $pdo->prepare('SELECT id, project_id, assigned_to_user_id, status, actual_minutes, updated_at FROM work_orders WHERE id = ? FOR UPDATE');
            $woStmt->execute([(int) $op['work_order_id']]);
            $wo = $woStmt->fetch();
            if (!$wo || !tb_mobile_can_work_on($user, $wo)) {
                throw new UnexpectedValueException('Nincs jogosultsága ehhez a munkalaphoz.');
            }
            $payload = tb_json_decode_array($op['payload_json']);
            $type = (string) ($payload['op_type'] ?? '');
            $data = tb_mobile_validate_op($type, is_array($payload['data'] ?? null) ? $payload['data'] : []);
            if ($type === 'checklist') {
                $chk = $pdo->prepare('SELECT id FROM work_order_checklists WHERE id = ? AND work_order_id = ?');
                $chk->execute([$data['item_id'], (int) $wo['id']]);
                if (!$chk->fetch()) {
                    throw new DomainException('A checklist tétel már nem létezik, a módosítás nem alkalmazható.');
                }
            }
            $result = tb_mobile_apply($pdo, $user, $wo, $type, $data);
        }
        $pdo->prepare('UPDATE work_order_sync_operations SET operation_status = ?, resolution = ?, resolved_at = NOW(), resolved_by_user_id = ?, applied_at = ' . ($resolution === 'apply_mine' ? 'NOW()' : 'applied_at') . ' WHERE id = ?')
            ->execute([$resolution === 'apply_mine' ? 'resolved' : 'discarded', $resolution, (int) $user['id'], $operationId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    log_admin_activity((int) $user['id'], 'mobile_conflict_resolved', 'work_order_sync_operation', $operationId, ['resolution' => $resolution]);
    return ['resolution' => $resolution, 'result' => $result];
}
