<?php
declare(strict_types=1);

/*
 * Számlázó adapter réteg (Billingo / Számlázz.hu előkészítés).
 * Biztonsági alapelv: valódi API-kulcs és kifejezett "live" mód nélkül SOHA nem megy ki éles számla.
 */

interface TbInvoiceProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function mode(): string;

    /**
     * @return array{status:string, external_id?:?string, message?:string}
     */
    public function createInvoice(array $draft): array;
}

final class TbNoopInvoiceProvider implements TbInvoiceProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function mode(): string
    {
        return 'noop';
    }

    public function createInvoice(array $draft): array
    {
        return ['status' => 'draft', 'external_id' => null, 'message' => 'Nincs számlázó beállítva: a tervezet kézi kiállításra vár.'];
    }
}

abstract class TbRemoteInvoiceProvider implements TbInvoiceProvider
{
    public function __construct(private string $apiKey, private string $mode)
    {
    }

    public function isConfigured(): bool
    {
        return strlen(trim($this->apiKey)) >= 8;
    }

    public function mode(): string
    {
        return $this->mode === 'live' ? 'live' : 'sandbox';
    }

    public function createInvoice(array $draft): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'draft', 'external_id' => null, 'message' => 'Hiányzó API kulcs – nem történt küldés (no-op).'];
        }
        if ($this->mode() !== 'live') {
            $fake = 'SANDBOX-' . strtoupper($this->name()) . '-' . substr(hash('sha256', ($draft['source_type'] ?? '') . ':' . ($draft['source_id'] ?? '')), 0, 10);
            return ['status' => 'sandbox', 'external_id' => $fake, 'message' => 'Sandbox mód: valódi számla nem készült.'];
        }
        return $this->sendLive($draft);
    }

    /**
     * Éles API hívás helye. Szándékosan nincs implementálva: az éles bekötés külön, tesztelt
     * fejlesztés. Addig kivételt dob, ami kézi fallback folyamatot indít.
     */
    protected function sendLive(array $draft): array
    {
        throw new RuntimeException('Az éles ' . $this->name() . ' integráció még nincs bekötve – kézi számlakiállítás szükséges.');
    }
}

final class TbBillingoInvoiceProvider extends TbRemoteInvoiceProvider
{
    public function name(): string
    {
        return 'billingo';
    }
}

final class TbSzamlazzInvoiceProvider extends TbRemoteInvoiceProvider
{
    public function name(): string
    {
        return 'szamlazz';
    }
}

function tb_invoice_provider(): TbInvoiceProvider
{
    $provider = strtolower(trim((string) env_or_fallback(['TB_INVOICE_PROVIDER'], 'none')));
    $mode = strtolower(trim((string) env_or_fallback(['TB_INVOICE_MODE'], 'sandbox')));
    if ($provider === 'billingo') {
        return new TbBillingoInvoiceProvider((string) env_or_fallback(['TB_BILLINGO_API_KEY'], ''), $mode);
    }
    if ($provider === 'szamlazz') {
        return new TbSzamlazzInvoiceProvider((string) env_or_fallback(['TB_SZAMLAZZ_AGENT_KEY'], ''), $mode);
    }
    return new TbNoopInvoiceProvider();
}

function tb_invoice_provider_summary(): array
{
    $p = tb_invoice_provider();
    return ['provider' => $p->name(), 'mode' => $p->mode(), 'configured' => $p->isConfigured()];
}

/**
 * Számla tervezet adatainak összeállítása rendelésből vagy projektből.
 */
function tb_invoice_build_draft(string $sourceType, int $sourceId): array
{
    if ($sourceType === 'order') {
        $stmt = db()->prepare('SELECT o.id, o.user_id, o.total, o.billing_name, o.billing_tax_number, o.billing_postal_code, o.billing_city, o.billing_address, u.name, u.email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1');
        $stmt->execute([$sourceId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new TbWorkflowPermanentError('Rendelés nem található: #' . $sourceId);
        }
        $itemsStmt = db()->prepare('SELECT oi.qty, oi.unit_price, p.name FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
        $itemsStmt->execute([$sourceId]);
        $items = [];
        foreach ($itemsStmt->fetchAll() as $item) {
            $items[] = ['name' => (string) ($item['name'] ?? 'Termék'), 'qty' => (float) $item['qty'], 'unit_price_cents' => (int) $item['unit_price'] * 100];
        }
        return [
            'customer_name' => (string) ($order['billing_name'] ?: $order['name'] ?: 'Ismeretlen vevő'),
            'customer_email' => $order['email'] ?? null,
            'buyer_tax_number' => $order['billing_tax_number'] ?? null,
            'billing_address' => trim(implode(' ', array_filter([(string) $order['billing_postal_code'], (string) $order['billing_city'], (string) $order['billing_address']]))),
            'total_cents' => (int) $order['total'] * 100,
            'items' => $items,
        ];
    }
    if ($sourceType === 'project') {
        $stmt = db()->prepare('SELECT p.id, p.title, p.budget_cents, u.name, u.email FROM projects p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1');
        $stmt->execute([$sourceId]);
        $project = $stmt->fetch();
        if (!$project) {
            throw new TbWorkflowPermanentError('Projekt nem található: #' . $sourceId);
        }
        $profit = tb_profit_for('project', $sourceId);
        $total = (int) $profit['actual']['revenue_cents'];
        $items = [];
        $entries = db()->prepare('SELECT title, quantity, unit_price_cents FROM work_order_cost_entries WHERE billable_to_customer = 1 AND (project_id = ? OR work_order_id IN (SELECT id FROM work_orders WHERE project_id = ?))');
        $entries->execute([$sourceId, $sourceId]);
        foreach ($entries->fetchAll() as $entry) {
            $items[] = ['name' => (string) $entry['title'], 'qty' => (float) $entry['quantity'], 'unit_price_cents' => (int) $entry['unit_price_cents']];
        }
        if (!$items && $total > 0) {
            $items[] = ['name' => (string) $project['title'], 'qty' => 1, 'unit_price_cents' => $total];
        }
        return [
            'customer_name' => (string) ($project['name'] ?: 'Ismeretlen vevő'),
            'customer_email' => $project['email'] ?? null,
            'buyer_tax_number' => null,
            'billing_address' => '',
            'total_cents' => $total,
            'items' => $items,
        ];
    }
    throw new TbWorkflowPermanentError('Ismeretlen számla forrás: ' . $sourceType);
}

/**
 * Számla-előkészítő esemény feldolgozása. Idempotens: forrásonként egy tervezet (UNIQUE kulcs).
 * Provider hiba esetén nem dob kivételt, hanem kézi fallback állapotba teszi a tervezetet.
 */
function tb_invoice_prepare(string $sourceType, int $sourceId): array
{
    if (!in_array($sourceType, ['order', 'project'], true) || $sourceId <= 0) {
        throw new TbWorkflowPermanentError('Érvénytelen számla forrás.');
    }
    $existing = db()->prepare('SELECT id, status FROM invoice_drafts WHERE source_type = ? AND source_id = ? LIMIT 1');
    $existing->execute([$sourceType, $sourceId]);
    $row = $existing->fetch();
    if ($row && in_array((string) $row['status'], ['sent', 'sandbox', 'manual_done', 'cancelled'], true)) {
        return ['invoice_draft_id' => (int) $row['id'], 'status' => $row['status'], 'skipped' => 'already_processed'];
    }
    $draft = tb_invoice_build_draft($sourceType, $sourceId);
    $provider = tb_invoice_provider();
    if (!$row) {
        $insert = db()->prepare('INSERT IGNORE INTO invoice_drafts (source_type, source_id, provider, mode, status, customer_name, customer_email, buyer_tax_number, billing_address, total_cents, currency, items_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$sourceType, $sourceId, $provider->name(), $provider->mode(), 'draft', clean_string($draft['customer_name'], 180), $draft['customer_email'], $draft['buyer_tax_number'], clean_string((string) $draft['billing_address'], 255), (int) $draft['total_cents'], 'HUF', tb_json($draft['items'])]);
        $existing->execute([$sourceType, $sourceId]);
        $row = $existing->fetch();
    }
    $draftId = (int) $row['id'];
    return tb_invoice_send_draft($draftId, $draft + ['source_type' => $sourceType, 'source_id' => $sourceId]);
}

function tb_invoice_send_draft(int $draftId, array $draft): array
{
    $provider = tb_invoice_provider();
    $claim = db()->prepare("UPDATE invoice_drafts SET status = 'pending', attempts = attempts + 1, provider = ?, mode = ? WHERE id = ? AND status IN ('draft','failed','manual_required')");
    $claim->execute([$provider->name(), $provider->mode(), $draftId]);
    if ($claim->rowCount() === 0) {
        return ['invoice_draft_id' => $draftId, 'skipped' => 'not_sendable'];
    }
    try {
        $result = $provider->createInvoice($draft);
        $status = in_array($result['status'] ?? '', ['draft', 'sent', 'sandbox'], true) ? (string) $result['status'] : 'manual_required';
        $update = db()->prepare('UPDATE invoice_drafts SET status = ?, external_id = ?, error_message = ? WHERE id = ?');
        $update->execute([$status, $result['external_id'] ?? null, isset($result['message']) ? clean_string((string) $result['message'], 500) : null, $draftId]);
        if ($status === 'draft') {
            tb_notify(null, 'admin', 'Számlatervezet elkészült', 'Tervezet #' . $draftId . ' (' . ($draft['source_type'] ?? '') . ' #' . ($draft['source_id'] ?? '') . ') kézi kiállításra vár.', '/admin-center.html#invoices', true);
        }
        return ['invoice_draft_id' => $draftId, 'status' => $status, 'external_id' => $result['external_id'] ?? null];
    } catch (Throwable $e) {
        $message = clean_string(redact_secrets($e->getMessage()), 500);
        $update = db()->prepare("UPDATE invoice_drafts SET status = 'manual_required', error_message = ? WHERE id = ?");
        $update->execute([$message, $draftId]);
        tb_notify(null, 'admin', 'Kézi számlázás szükséges', 'Tervezet #' . $draftId . ': ' . $message, '/admin-center.html#invoices', true);
        app_log_error('invoice_provider_failed', $e, ['draft_id' => $draftId, 'provider' => $provider->name()]);
        return ['invoice_draft_id' => $draftId, 'status' => 'manual_required', 'error' => $message];
    }
}
