<?php
declare(strict_types=1);

/*
 * Digitális aláírás workflow: dokumentum pillanatkép + hash + verzió, aláírás kép validálás,
 * jogosultság, üzleti hatások (ajánlat elfogadás, munkalap/projekt lezárás), nyomtatható HTML,
 * ügyfél e-mail másolat, visszavonás.
 */

const TB_SIGNATURE_MAX_BYTES = 400000;

function tb_signature_document_types(): array
{
    return ['quote', 'work_order', 'project'];
}

/**
 * PNG data URL validálása. Visszaadja a normalizált data URL-t, hibánál InvalidArgumentException.
 * GD kiterjesztés nem szükséges (getimagesizefromstring a core része).
 */
function tb_signature_validate_png(string $dataUrl): string
{
    $prefix = 'data:image/png;base64,';
    if (!str_starts_with($dataUrl, $prefix)) {
        throw new InvalidArgumentException('Az aláírásnak PNG képnek kell lennie.');
    }
    $b64 = substr($dataUrl, strlen($prefix));
    if ($b64 === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $b64)) {
        throw new InvalidArgumentException('Hibás aláírás kódolás.');
    }
    $binary = base64_decode($b64, true);
    if ($binary === false || strlen($binary) < 100) {
        throw new InvalidArgumentException('Az aláírás üres vagy hibás.');
    }
    if (strlen($binary) > TB_SIGNATURE_MAX_BYTES) {
        throw new InvalidArgumentException('Az aláírás képe túl nagy.');
    }
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new InvalidArgumentException('Az aláírás nem érvényes PNG.');
    }
    $info = @getimagesizefromstring($binary);
    if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG) {
        throw new InvalidArgumentException('Az aláírás nem érvényes PNG.');
    }
    [$w, $h] = $info;
    if ($w < 50 || $h < 20 || $w > 3000 || $h > 1500) {
        throw new InvalidArgumentException('Az aláírás mérete nem megfelelő.');
    }
    return $prefix . base64_encode($binary);
}

function tb_canonical_json(array $data): string
{
    $sort = static function (&$value) use (&$sort): void {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value);
            }
            foreach ($value as &$v) {
                $sort($v);
            }
        }
    };
    $sort($data);
    return tb_json($data);
}

/**
 * Szerver oldali dokumentum pillanatkép (az aláírt tartalom) + hash + verzió.
 */
function tb_signature_document(string $type, int $id): ?array
{
    if ($type === 'quote') {
        $stmt = db()->prepare('SELECT cq.id, cq.title, cq.body, cq.amount_cents, cq.currency, cq.status, cq.valid_until, cq.user_id, cq.project_id, cq.updated_at, l.email AS lead_email, l.name AS lead_name FROM crm_quotes cq LEFT JOIN leads l ON l.id = cq.lead_id WHERE cq.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $snapshot = ['type' => 'quote', 'id' => (int) $row['id'], 'title' => $row['title'], 'body' => $row['body'], 'amount_cents' => $row['amount_cents'] !== null ? (int) $row['amount_cents'] : null, 'currency' => $row['currency'], 'valid_until' => $row['valid_until']];
        $owner = ['user_id' => (int) ($row['user_id'] ?? 0), 'email' => (string) ($row['lead_email'] ?? ''), 'name' => (string) ($row['lead_name'] ?? '')];
    } elseif ($type === 'work_order') {
        $stmt = db()->prepare('SELECT wo.id, wo.project_id, wo.user_id, wo.assigned_to_user_id, wo.title, wo.location, wo.description, wo.status, wo.actual_minutes, wo.updated_at, u.email, u.name FROM work_orders wo LEFT JOIN users u ON u.id = wo.user_id WHERE wo.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $check = db()->prepare('SELECT item_text, is_done FROM work_order_checklists WHERE work_order_id = ? ORDER BY id ASC');
        $check->execute([$id]);
        $costs = db()->prepare('SELECT entry_type, title, quantity, unit FROM work_order_cost_entries WHERE work_order_id = ? AND billable_to_customer = 1 ORDER BY id ASC');
        $costs->execute([$id]);
        $snapshot = [
            'type' => 'work_order', 'id' => (int) $row['id'], 'title' => $row['title'], 'location' => $row['location'], 'description' => $row['description'],
            'actual_minutes' => $row['actual_minutes'] !== null ? (int) $row['actual_minutes'] : null,
            'checklist' => array_map(static fn ($c) => ['item' => $c['item_text'], 'done' => (int) $c['is_done']], $check->fetchAll()),
            'materials_and_work' => array_map(static fn ($c) => ['type' => $c['entry_type'], 'title' => $c['title'], 'quantity' => (string) $c['quantity'], 'unit' => $c['unit']], $costs->fetchAll()),
        ];
        $owner = ['user_id' => (int) ($row['user_id'] ?? 0), 'email' => (string) ($row['email'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'assigned_to_user_id' => (int) ($row['assigned_to_user_id'] ?? 0)];
    } elseif ($type === 'project') {
        $stmt = db()->prepare('SELECT p.id, p.user_id, p.title, p.address, p.description, p.status, p.updated_at, u.email, u.name FROM projects p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $wos = db()->prepare('SELECT id, title, status FROM work_orders WHERE project_id = ? ORDER BY id ASC');
        $wos->execute([$id]);
        $snapshot = [
            'type' => 'project', 'id' => (int) $row['id'], 'title' => $row['title'], 'address' => $row['address'], 'description' => $row['description'],
            'work_orders' => array_map(static fn ($w) => ['id' => (int) $w['id'], 'title' => $w['title'], 'status' => $w['status']], $wos->fetchAll()),
        ];
        $owner = ['user_id' => (int) ($row['user_id'] ?? 0), 'email' => (string) ($row['email'] ?? ''), 'name' => (string) ($row['name'] ?? '')];
    } else {
        return null;
    }
    $canonical = tb_canonical_json($snapshot);
    $hash = hash('sha256', $canonical);
    return [
        'type' => $type,
        'id' => $id,
        'status' => (string) $row['status'],
        'snapshot' => $snapshot,
        'snapshot_json' => $canonical,
        'hash' => $hash,
        'version' => 'v' . date('YmdHis', strtotime((string) $row['updated_at']) ?: time()) . '-' . substr($hash, 0, 8),
        'owner' => $owner,
    ];
}

function tb_signature_can_access(array $user, array $document): bool
{
    if (tb_is_backoffice($user)) {
        return true;
    }
    $uid = (int) $user['id'];
    $owner = $document['owner'];
    if ($uid > 0 && $uid === (int) ($owner['user_id'] ?? 0)) {
        return true;
    }
    if ($document['type'] === 'quote' && ($owner['email'] ?? '') !== '' && strcasecmp((string) $owner['email'], (string) ($user['email'] ?? '')) === 0) {
        return true;
    }
    if (($user['role'] ?? '') === 'field_worker' && $document['type'] === 'work_order' && $uid === (int) ($owner['assigned_to_user_id'] ?? 0)) {
        return true;
    }
    return false;
}

function tb_signature_assert_signable(array $document): void
{
    $status = $document['status'];
    if ($document['type'] === 'quote' && !in_array($status, ['draft', 'sent', 'viewed'], true)) {
        throw new InvalidArgumentException('Ez az ajánlat már nem fogadható el (státusz: ' . $status . ').');
    }
    if ($document['type'] === 'work_order' && $status === 'cancelled') {
        throw new InvalidArgumentException('Visszavont munkalap nem írható alá.');
    }
    if ($document['type'] === 'project' && $status === 'cancelled') {
        throw new InvalidArgumentException('Törölt projekt nem írható alá.');
    }
}

/**
 * Aláírás üzleti hatásai (ugyanabban a tranzakcióban hívandó).
 */
function tb_signature_apply_effects(PDO $pdo, array $document, int $actorUserId, string $signerName): array
{
    $id = (int) $document['id'];
    $events = [];
    if ($document['type'] === 'quote') {
        $upd = $pdo->prepare("UPDATE crm_quotes SET status = 'accepted' WHERE id = ? AND status IN ('draft','sent','viewed')");
        $upd->execute([$id]);
        if ($upd->rowCount() > 0) {
            $pdo->prepare('INSERT INTO crm_quote_status_logs (crm_quote_id, from_status, to_status, changed_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $document['status'], 'accepted', $actorUserId, 'Digitális aláírással elfogadva: ' . $signerName]);
            $events[] = ['crm_quote_status', ['crm_quote_id' => $id, 'to' => 'accepted']];
        }
    } elseif ($document['type'] === 'work_order') {
        $upd = $pdo->prepare("UPDATE work_orders SET status = 'done', handover_ready = 1, client_signature_name = ?, closed_at = COALESCE(closed_at, NOW()) WHERE id = ? AND status <> 'cancelled'");
        $upd->execute([clean_string($signerName, 120), $id]);
        $pdo->prepare("INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note, related_type, related_id) SELECT project_id, ?, 'work_order', ?, 'work_order', id FROM work_orders WHERE id = ? AND project_id IS NOT NULL")
            ->execute([$actorUserId, 'Munkalap lezárva ügyfél aláírással (' . clean_string($signerName, 120) . ').', $id]);
        $events[] = ['work_order_closed', ['work_order_id' => $id]];
    } elseif ($document['type'] === 'project') {
        $upd = $pdo->prepare("UPDATE projects SET status = 'completed' WHERE id = ? AND status NOT IN ('completed','cancelled')");
        $upd->execute([$id]);
        $pdo->prepare("INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note) VALUES (?, ?, 'status_change', ?)")
            ->execute([$id, $actorUserId, 'Projekt átadás-átvétel aláírva (' . clean_string($signerName, 120) . ').']);
        if ($upd->rowCount() > 0) {
            $events[] = ['project_status', ['project_id' => $id, 'to' => 'completed']];
        }
    }
    return $events;
}

/**
 * Aláírás létrehozása. Hibánál InvalidArgumentException (422), DomainException (409) vagy RuntimeException.
 */
function tb_signature_create(array $user, array $input): array
{
    $type = (string) ($input['document_type'] ?? '');
    $docId = (int) ($input['document_id'] ?? 0);
    if (!in_array($type, tb_signature_document_types(), true) || $docId <= 0) {
        throw new InvalidArgumentException('Érvénytelen dokumentum.');
    }
    $signerName = clean_string((string) ($input['signer_name'] ?? ''), 120);
    $signerEmail = clean_string((string) ($input['signer_email'] ?? ''), 190);
    $signerRole = clean_string((string) ($input['signer_role'] ?? 'customer'), 40);
    $declaration = clean_string((string) ($input['declaration_text'] ?? ''), 4000);
    $accepted = !empty($input['declaration_accepted']);
    $ipMode = (string) ($input['ip_capture_mode'] ?? 'hash');
    if (!in_array($ipMode, ['hash', 'omitted'], true)) {
        $ipMode = 'hash';
    }
    if (mb_strlen($signerName) < 2 || !filter_var($signerEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($declaration) < 10 || !$accepted) {
        throw new InvalidArgumentException('Aláíró neve, érvényes e-mail címe és az elfogadott nyilatkozat kötelező.');
    }
    $signature = tb_signature_validate_png((string) ($input['signature_png'] ?? ''));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $table = ['quote' => 'crm_quotes', 'work_order' => 'work_orders', 'project' => 'projects'][$type];
        $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = ? FOR UPDATE')->execute([$docId]);
        $document = tb_signature_document($type, $docId);
        if (!$document) {
            throw new OutOfBoundsException('A dokumentum nem található.');
        }
        if (!tb_signature_can_access($user, $document)) {
            throw new UnexpectedValueException('Nincs jogosultsága a dokumentum aláírásához.');
        }
        tb_signature_assert_signable($document);
        $expectedHash = (string) ($input['document_hash'] ?? '');
        if ($expectedHash !== '' && !hash_equals($document['hash'], $expectedHash)) {
            throw new DomainException('A dokumentum időközben módosult. Kérjük, töltse újra és ellenőrizze a tartalmat aláírás előtt.');
        }
        $dup = $pdo->prepare("SELECT id FROM digital_signatures WHERE document_type = ? AND document_id = ? AND document_hash = ? AND status = 'active' LIMIT 1");
        $dup->execute([$type, $docId, $document['hash']]);
        if ($dup->fetch()) {
            throw new DomainException('Ez a dokumentumverzió már alá van írva.');
        }
        $insert = $pdo->prepare("INSERT INTO digital_signatures (document_type, document_id, document_version, document_hash, document_snapshot, signer_name, signer_email, signer_role, declaration_text, signature_svg, signer_ip_hash, ip_capture_mode, status, signed_at, created_by_user_id, email_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), ?, 'pending')");
        $insert->execute([$type, $docId, $document['version'], $document['hash'], $document['snapshot_json'], $signerName, $signerEmail, $signerRole, $declaration, $signature, $ipMode === 'hash' ? auth_ip_hash() : null, $ipMode, (int) $user['id']]);
        $signatureId = (int) $pdo->lastInsertId();
        $events = tb_signature_apply_effects($pdo, $document, (int) $user['id'], $signerName);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    foreach ($events as [$event, $data]) {
        tb_workflow_event($event, $data);
    }
    log_admin_activity((int) $user['id'], 'digital_signature_created', $type, $docId, ['signature_id' => $signatureId, 'hash' => $document['hash']]);
    $mail = tb_signature_send_copy($signatureId);
    return ['id' => $signatureId, 'document_hash' => $document['hash'], 'document_version' => $document['version'], 'email_status' => $mail['status'], 'email_error' => $mail['error']];
}

function tb_signature_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, document_type, document_id, document_version, document_hash, document_snapshot, signer_name, signer_email, signer_role, declaration_text, signature_svg, ip_capture_mode, status, revoked_reason, revoked_at, revoked_by_user_id, signed_at, created_by_user_id, email_status, email_error, created_at FROM digital_signatures WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tb_signature_labels(): array
{
    return ['quote' => 'Árajánlat elfogadása', 'work_order' => 'Munkalap teljesítésigazolás', 'project' => 'Projekt átadás-átvétel'];
}

/**
 * Nyomtatható HTML (PDF helyett / mellett: böngészőből "Mentés PDF-ként").
 * Minden dinamikus érték escapelt; csak validált PNG data URL kerül képként a dokumentumba.
 */
function tb_signature_printable_html(array $row): string
{
    $labels = tb_signature_labels();
    $title = $labels[$row['document_type']] ?? 'Aláírt dokumentum';
    $snapshot = tb_json_decode_array($row['document_snapshot'] ?? null);
    $renderValue = static function ($value) use (&$renderValue): string {
        if (is_array($value)) {
            $out = '<ul>';
            foreach ($value as $k => $v) {
                $out .= '<li>' . (is_int($k) ? '' : '<strong>' . tb_h($k) . ':</strong> ') . $renderValue($v) . '</li>';
            }
            return $out . '</ul>';
        }
        return nl2br(tb_h($value));
    };
    $rows = '';
    foreach ($snapshot as $key => $value) {
        $rows .= '<tr><th>' . tb_h($key) . '</th><td>' . $renderValue($value) . '</td></tr>';
    }
    $sig = (string) ($row['signature_svg'] ?? '');
    $sigHtml = preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/]+={0,2}$/', $sig)
        ? '<img alt="Aláírás" src="' . tb_h($sig) . '" style="max-width:420px;border-bottom:1px solid #333">'
        : '<em>(Régi formátumú aláírás – biztonsági okból nem jeleníthető meg.)</em>';
    $revoked = (string) $row['status'] !== 'active'
        ? '<div class="revoked">ÉRVÉNYTELENÍTVE (' . tb_h($row['status']) . ') – ' . tb_h($row['revoked_at']) . ' – Indok: ' . tb_h($row['revoked_reason']) . '</div>'
        : '';
    return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>' . tb_h($title) . ' #' . (int) $row['id'] . '</title>'
        . '<style>body{font-family:Arial,sans-serif;max-width:800px;margin:24px auto;color:#111}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:6px;text-align:left;vertical-align:top}th{width:28%;background:#f4f4f4}.meta{font-size:12px;color:#444}.revoked{border:2px solid #b00;color:#b00;padding:8px;font-weight:bold;margin:12px 0}@media print{.noprint{display:none}}</style>'
        . '</head><body>'
        . '<p class="noprint">Nyomtatáshoz / PDF mentéshez használja a böngésző Nyomtatás (Ctrl+P) funkcióját.</p>'
        . '<h1>' . tb_h($title) . '</h1>' . $revoked
        . '<p class="meta">Aláírás azonosító: #' . (int) $row['id'] . ' · Dokumentum: ' . tb_h($row['document_type']) . ' #' . (int) $row['document_id']
        . ' · Verzió: ' . tb_h($row['document_version']) . '<br>Dokumentum SHA-256: <code>' . tb_h($row['document_hash'] ?? '') . '</code>'
        . '<br>Időbélyeg: ' . tb_h($row['signed_at']) . ' (' . tb_h(date_default_timezone_get()) . ') · IP rögzítés: ' . ($row['ip_capture_mode'] === 'hash' ? 'sózott hash (nyers IP nem tárolt)' : 'nem rögzített') . '</p>'
        . '<h2>Dokumentum tartalma</h2><table>' . $rows . '</table>'
        . '<h2>Nyilatkozat</h2><p>' . nl2br(tb_h($row['declaration_text'])) . '</p>'
        . '<h2>Aláíró</h2><p>' . tb_h($row['signer_name']) . ' &lt;' . tb_h($row['signer_email']) . '&gt; (' . tb_h($row['signer_role'] ?? 'customer') . ')</p>'
        . $sigHtml
        . '<p class="meta">' . tb_h(app_config()['app_name']) . ' – elektronikusan rögzített aláírás.</p>'
        . '</body></html>';
}

/**
 * Ügyfélmásolat e-mail. Hiba esetén az állapot "failed" lesz, az admin később újraküldheti.
 */
function tb_signature_send_copy(int $signatureId): array
{
    $row = tb_signature_get($signatureId);
    if (!$row) {
        return ['status' => 'failed', 'error' => 'Aláírás nem található.'];
    }
    $labels = tb_signature_labels();
    $text = 'Tisztelt ' . $row['signer_name'] . "!\n\n"
        . 'Ezúton küldjük az Ön által ' . $row['signed_at'] . ' időpontban aláírt dokumentum összefoglalóját: ' . ($labels[$row['document_type']] ?? '') . ' #' . $row['document_id'] . ".\n"
        . 'Dokumentum verzió: ' . $row['document_version'] . "\n"
        . 'Dokumentum ellenőrző kód (SHA-256): ' . $row['document_hash'] . "\n\n"
        . "Nyilatkozat:\n" . $row['declaration_text'] . "\n\n"
        . "A teljes, nyomtatható példányt kérésre megküldjük, illetve ügyfélfiókjában is elérhető.\n\n"
        . 'Üdvözlettel:' . "\n" . app_config()['app_name'];
    try {
        send_app_mail((string) $row['signer_email'], (string) $row['signer_name'], 'Aláírt dokumentum másolata – ' . ($labels[$row['document_type']] ?? 'dokumentum'), format_html_email($text), $text);
        db()->prepare("UPDATE digital_signatures SET email_status = 'sent', email_error = NULL WHERE id = ?")->execute([$signatureId]);
        return ['status' => 'sent', 'error' => null];
    } catch (Throwable $e) {
        $error = clean_string(redact_secrets($e->getMessage()), 480);
        db()->prepare("UPDATE digital_signatures SET email_status = 'failed', email_error = ? WHERE id = ?")->execute([$error, $signatureId]);
        tb_notify(null, 'admin', 'Aláírás másolat küldése sikertelen', 'Aláírás #' . $signatureId . ': ' . $error, '/admin-center.html#signatures');
        return ['status' => 'failed', 'error' => 'Az e-mail másolat küldése nem sikerült; az adminisztrátor újraküldheti.'];
    }
}

function tb_signature_revoke(array $admin, int $id, string $reason, string $newStatus = 'revoked'): void
{
    if (!in_array($newStatus, ['revoked', 'invalidated'], true)) {
        throw new InvalidArgumentException('Érvénytelen státusz.');
    }
    if ($id <= 0 || mb_strlen($reason) < 3) {
        throw new InvalidArgumentException('Aláírás azonosító és indoklás kötelező.');
    }
    $stmt = db()->prepare("UPDATE digital_signatures SET status = ?, revoked_reason = ?, revoked_at = NOW(), revoked_by_user_id = ? WHERE id = ? AND status = 'active'");
    $stmt->execute([$newStatus, clean_string($reason, 255), (int) $admin['id'], $id]);
    if ($stmt->rowCount() === 0) {
        throw new DomainException('Az aláírás nem található vagy már nem aktív.');
    }
    log_admin_activity((int) $admin['id'], 'digital_signature_' . $newStatus, 'digital_signature', $id, ['reason' => clean_string($reason, 255)]);
}
