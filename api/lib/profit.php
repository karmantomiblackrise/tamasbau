<?php
declare(strict_types=1);

/*
 * Projekt / munkalap profit és költségelemzés.
 * Minden összeg fillérben (cents) értendő, HUF pénznemben.
 */

function tb_profit_types(): array
{
    return ['material', 'labor', 'travel', 'external'];
}

function tb_travel_cost_per_km_cents(): int
{
    return max(0, (int) round((float) env_or_fallback(['TB_TRAVEL_COST_PER_KM_HUF'], '0') * 100));
}

function tb_min_margin_pct(): float
{
    return (float) env_or_fallback(['TB_MIN_MARGIN_PCT'], '10');
}

/**
 * Tiszta (DB-független) aggregáló függvény – unit tesztelhető.
 *
 * @param array $entries work_order_cost_entries sorok
 * @param array $context quote_amount_cents, budget_cents, external_costs_cents, travel_cost_per_km_cents, min_margin_pct
 */
function tb_profit_aggregate(array $entries, array $context = []): array
{
    $perKm = (int) ($context['travel_cost_per_km_cents'] ?? 0);
    $breakdown = [];
    foreach (tb_profit_types() as $type) {
        $breakdown[$type] = ['planned_cost_cents' => 0, 'actual_cost_cents' => 0, 'revenue_cents' => 0];
    }
    $plannedBillable = 0;
    $actualBillable = 0;
    foreach ($entries as $entry) {
        $type = in_array($entry['entry_type'] ?? '', tb_profit_types(), true) ? (string) $entry['entry_type'] : 'external';
        $qty = (float) ($entry['quantity'] ?? 0);
        $unitPrice = (int) ($entry['unit_price_cents'] ?? 0);
        $internal = isset($entry['internal_unit_cost_cents']) && $entry['internal_unit_cost_cents'] !== null ? (int) $entry['internal_unit_cost_cents'] : null;
        $plannedQty = isset($entry['planned_quantity']) && $entry['planned_quantity'] !== null ? (float) $entry['planned_quantity'] : null;
        $plannedPrice = isset($entry['planned_unit_price_cents']) && $entry['planned_unit_price_cents'] !== null ? (int) $entry['planned_unit_price_cents'] : null;
        $billable = (int) ($entry['billable_to_customer'] ?? 1) === 1;

        $costUnit = $internal ?? $unitPrice;
        $actualCost = (int) round($qty * $costUnit);
        if ($type === 'travel' && $actualCost === 0 && !empty($entry['travel_km'])) {
            $actualCost = (int) round((float) $entry['travel_km'] * $perKm);
        }
        $plannedCost = $plannedQty !== null ? (int) round($plannedQty * ($internal ?? $plannedPrice ?? $unitPrice)) : 0;
        $revenue = $billable ? (int) round($qty * $unitPrice) : 0;
        $plannedRevenue = ($billable && $plannedQty !== null) ? (int) round($plannedQty * ($plannedPrice ?? $unitPrice)) : 0;

        $breakdown[$type]['actual_cost_cents'] += $actualCost;
        $breakdown[$type]['planned_cost_cents'] += $plannedCost;
        $breakdown[$type]['revenue_cents'] += $revenue;
        $actualBillable += $revenue;
        $plannedBillable += $plannedRevenue;
    }
    $external = (int) ($context['external_costs_cents'] ?? 0);
    $breakdown['external']['actual_cost_cents'] += $external;

    $quoteAmount = isset($context['quote_amount_cents']) && (int) $context['quote_amount_cents'] > 0 ? (int) $context['quote_amount_cents'] : null;
    $budget = isset($context['budget_cents']) && (int) $context['budget_cents'] > 0 ? (int) $context['budget_cents'] : null;

    $plannedCost = array_sum(array_column($breakdown, 'planned_cost_cents'));
    $actualCost = array_sum(array_column($breakdown, 'actual_cost_cents'));
    $plannedRevenue = $quoteAmount ?? ($plannedBillable > 0 ? $plannedBillable : ($budget ?? 0));
    $actualRevenue = $actualBillable > 0 ? $actualBillable : ($quoteAmount ?? 0);

    $plannedMargin = $plannedRevenue - $plannedCost;
    $actualMargin = $actualRevenue - $actualCost;
    $marginPct = $actualRevenue > 0 ? round($actualMargin / $actualRevenue * 100, 1) : null;
    $minMargin = (float) ($context['min_margin_pct'] ?? 10);

    $alerts = [];
    if ($actualCost > 0 && $actualMargin < 0) {
        $alerts[] = ['type' => 'negative_margin', 'severity' => 'high', 'message' => 'Negatív margin: a tényleges költség meghaladja a bevételt.'];
    }
    if ($plannedCost > 0 && $actualCost > $plannedCost) {
        $pct = round(($actualCost - $plannedCost) / $plannedCost * 100, 1);
        $alerts[] = ['type' => 'cost_overrun', 'severity' => $pct >= 20 ? 'high' : 'medium', 'message' => 'Költségtúllépés: +' . $pct . '% a tervhez képest.'];
    }
    if ($budget !== null && $actualCost > $budget) {
        $alerts[] = ['type' => 'budget_overrun', 'severity' => 'high', 'message' => 'A tényleges költség meghaladja a projekt keretét.'];
    }
    if ($marginPct !== null && $actualMargin >= 0 && $marginPct < $minMargin) {
        $alerts[] = ['type' => 'low_margin', 'severity' => 'medium', 'message' => 'Alacsony margin: ' . $marginPct . '% (minimum: ' . $minMargin . '%).'];
    }

    return [
        'planned' => ['revenue_cents' => $plannedRevenue, 'cost_cents' => $plannedCost, 'margin_cents' => $plannedMargin],
        'actual' => ['revenue_cents' => $actualRevenue, 'cost_cents' => $actualCost, 'margin_cents' => $actualMargin, 'margin_pct' => $marginPct],
        'breakdown' => $breakdown,
        'alerts' => $alerts,
    ];
}

/**
 * Jogosultság szerinti szűrés: belső költség, margin és költség alapú riasztás csak adminnak.
 */
function tb_profit_redact(array $profit): array
{
    $profit['planned'] = ['revenue_cents' => $profit['planned']['revenue_cents']];
    $profit['actual'] = ['revenue_cents' => $profit['actual']['revenue_cents']];
    foreach ($profit['breakdown'] as $type => $row) {
        $profit['breakdown'][$type] = ['revenue_cents' => $row['revenue_cents']];
    }
    $profit['alerts'] = [];
    $profit['redacted'] = true;
    return $profit;
}

function tb_profit_for(string $scope, int $id): array
{
    if ($scope === 'project') {
        $entries = db()->prepare('SELECT entry_type, quantity, unit_price_cents, internal_unit_cost_cents, planned_quantity, planned_unit_price_cents, travel_km, billable_to_customer FROM work_order_cost_entries WHERE project_id = ? OR work_order_id IN (SELECT id FROM work_orders WHERE project_id = ?)');
        $entries->execute([$id, $id]);
        $ext = db()->prepare("SELECT COALESCE(SUM(external_cost_cents), 0) AS c FROM partner_assignments WHERE assignment_status <> 'cancelled' AND (project_id = ? OR work_order_id IN (SELECT id FROM work_orders WHERE project_id = ?))");
        $ext->execute([$id, $id]);
        $quote = db()->prepare("SELECT COALESCE(SUM(amount_cents), 0) AS c FROM crm_quotes WHERE project_id = ? AND status = 'accepted'");
        $quote->execute([$id]);
        $budget = db()->prepare('SELECT budget_cents FROM projects WHERE id = ? LIMIT 1');
        $budget->execute([$id]);
        $context = [
            'external_costs_cents' => (int) ($ext->fetch()['c'] ?? 0),
            'quote_amount_cents' => (int) ($quote->fetch()['c'] ?? 0),
            'budget_cents' => (int) (($budget->fetch() ?: [])['budget_cents'] ?? 0),
        ];
    } else {
        $entries = db()->prepare('SELECT entry_type, quantity, unit_price_cents, internal_unit_cost_cents, planned_quantity, planned_unit_price_cents, travel_km, billable_to_customer FROM work_order_cost_entries WHERE work_order_id = ?');
        $entries->execute([$id]);
        $ext = db()->prepare("SELECT COALESCE(SUM(external_cost_cents), 0) AS c FROM partner_assignments WHERE assignment_status <> 'cancelled' AND work_order_id = ?");
        $ext->execute([$id]);
        $context = ['external_costs_cents' => (int) ($ext->fetch()['c'] ?? 0)];
    }
    $context['travel_cost_per_km_cents'] = tb_travel_cost_per_km_cents();
    $context['min_margin_pct'] = tb_min_margin_pct();
    return tb_profit_aggregate($entries->fetchAll(), $context);
}

/**
 * Dashboard: projektenkénti és munkalaponkénti profit + KPI összesítés.
 */
function tb_profit_overview(bool $internal, string $scope = 'project', int $limit = 100): array
{
    $limit = max(1, min(300, $limit));
    if ($scope === 'work_order') {
        $stmt = db()->prepare("SELECT wo.id, wo.title, wo.status, p.title AS parent_title FROM work_orders wo LEFT JOIN projects p ON p.id = wo.project_id WHERE wo.status <> 'cancelled' ORDER BY wo.updated_at DESC LIMIT ?");
    } else {
        $stmt = db()->prepare("SELECT p.id, p.title, p.status, u.name AS parent_title FROM projects p LEFT JOIN users u ON u.id = p.user_id WHERE p.status <> 'cancelled' ORDER BY p.updated_at DESC LIMIT ?");
    }
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    $kpi = ['revenue_cents' => 0, 'planned_revenue_cents' => 0, 'cost_cents' => 0, 'planned_cost_cents' => 0, 'margin_cents' => 0, 'alert_count' => 0, 'negative_margin_count' => 0, 'count' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $profit = tb_profit_for($scope, (int) $row['id']);
        $kpi['count']++;
        $kpi['revenue_cents'] += $profit['actual']['revenue_cents'];
        $kpi['planned_revenue_cents'] += $profit['planned']['revenue_cents'];
        $kpi['cost_cents'] += $profit['actual']['cost_cents'];
        $kpi['planned_cost_cents'] += $profit['planned']['cost_cents'];
        $kpi['margin_cents'] += $profit['actual']['margin_cents'];
        $kpi['alert_count'] += count($profit['alerts']) > 0 ? 1 : 0;
        foreach ($profit['alerts'] as $alert) {
            if ($alert['type'] === 'negative_margin') {
                $kpi['negative_margin_count']++;
            }
        }
        $rows[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'parent_title' => $row['parent_title'],
            'profit' => $internal ? $profit : tb_profit_redact($profit),
        ];
    }
    $kpi['margin_pct'] = $kpi['revenue_cents'] > 0 ? round($kpi['margin_cents'] / $kpi['revenue_cents'] * 100, 1) : null;
    if (!$internal) {
        $kpi = ['revenue_cents' => $kpi['revenue_cents'], 'planned_revenue_cents' => $kpi['planned_revenue_cents'], 'count' => $kpi['count']];
    }
    return ['scope' => $scope, 'kpi' => $kpi, 'rows' => $rows, 'internal_visible' => $internal];
}

function tb_profit_csv(array $overview): string
{
    $internal = (bool) $overview['internal_visible'];
    $header = ['Azonosító', 'Megnevezés', 'Státusz', 'Kapcsolódó', 'Tervezett bevétel (Ft)', 'Tényleges bevétel (Ft)'];
    if ($internal) {
        array_push($header, 'Tervezett költség (Ft)', 'Tényleges költség (Ft)', 'Anyag (Ft)', 'Munkaóra (Ft)', 'Utazás (Ft)', 'Külső (Ft)', 'Margin (Ft)', 'Margin (%)', 'Riasztások');
    }
    $rows = [];
    foreach ($overview['rows'] as $row) {
        $p = $row['profit'];
        $line = [$row['id'], $row['title'], $row['status'], $row['parent_title'], intdiv((int) $p['planned']['revenue_cents'], 100), intdiv((int) $p['actual']['revenue_cents'], 100)];
        if ($internal) {
            array_push(
                $line,
                intdiv((int) $p['planned']['cost_cents'], 100),
                intdiv((int) $p['actual']['cost_cents'], 100),
                intdiv((int) $p['breakdown']['material']['actual_cost_cents'], 100),
                intdiv((int) $p['breakdown']['labor']['actual_cost_cents'], 100),
                intdiv((int) $p['breakdown']['travel']['actual_cost_cents'], 100),
                intdiv((int) $p['breakdown']['external']['actual_cost_cents'], 100),
                intdiv((int) $p['actual']['margin_cents'], 100),
                $p['actual']['margin_pct'] ?? '',
                implode(' | ', array_column($p['alerts'], 'message'))
            );
        }
        $rows[] = $line;
    }
    return tb_csv_build($header, $rows);
}
