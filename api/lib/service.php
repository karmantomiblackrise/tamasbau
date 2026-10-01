<?php
declare(strict_types=1);

/*
 * Szerviz SLA automatika: emergency azonnali riasztás, lejárat előtti figyelmeztetés,
 * SLA túllépés jelzés, várakozó ticketek eszkalációja, SLA riport.
 */

function tb_sla_open_statuses(): array
{
    return ['open', 'triaged', 'scheduled', 'in_progress', 'waiting_customer'];
}

function tb_sla_default_hours(string $priority): int
{
    $defaults = ['emergency' => 4, 'high' => 24, 'normal' => 72, 'low' => 168];
    $envKey = 'TB_SLA_HOURS_' . strtoupper($priority);
    $env = (int) env_or_fallback([$envKey], '0');
    if ($env > 0) {
        return min(24 * 60, $env);
    }
    return $defaults[$priority] ?? 72;
}

function tb_sla_due_at(string $priority, ?int $from = null): string
{
    return date('Y-m-d H:i:s', ($from ?? time()) + tb_sla_default_hours($priority) * 3600);
}

function tb_sla_history(int $ticketId, string $eventType, ?string $from, ?string $to, string $note): void
{
    try {
        $stmt = db()->prepare('INSERT INTO service_ticket_history (ticket_id, actor_user_id, event_type, from_value, to_value, note) VALUES (?, NULL, ?, ?, ?, ?)');
        $stmt->execute([$ticketId, $eventType, $from, $to, clean_string($note, 500)]);
    } catch (Throwable $e) {
        app_log_error('sla_history_failed', $e);
    }
}

/**
 * Emergency ticket riasztás: pontosan egyszer (emergency_alerted_at atomikus beállítása).
 */
function tb_sla_emergency_alert(int $ticketId): array
{
    $stmt = db()->prepare('SELECT id, subject, location, priority, status FROM service_tickets WHERE id = ? LIMIT 1');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        throw new TbWorkflowPermanentError('Ticket nem található: #' . $ticketId);
    }
    if ((string) $ticket['priority'] !== 'emergency') {
        return ['skipped' => 'not_emergency'];
    }
    $mark = db()->prepare('UPDATE service_tickets SET emergency_alerted_at = NOW() WHERE id = ? AND emergency_alerted_at IS NULL');
    $mark->execute([$ticketId]);
    if ($mark->rowCount() === 0) {
        return ['skipped' => 'already_alerted'];
    }
    $message = 'Sürgős (emergency) ticket #' . $ticketId . ': ' . $ticket['subject'] . ($ticket['location'] ? ' – ' . $ticket['location'] : '');
    tb_notify(null, 'admin', 'SÜRGŐS szerviz bejelentés', $message, '/admin#service', true);
    $mailError = tb_alert_email('[SÜRGŐS] Szerviz ticket #' . $ticketId, $message . "\n\nKérjük, azonnal vegye fel a kapcsolatot az ügyféllel.");
    tb_sla_history($ticketId, 'emergency_alert', null, 'alerted', $mailError === null ? 'Azonnali riasztás elküldve.' : 'In-app riasztás kész, e-mail hiba: ' . $mailError);
    return ['alerted' => true, 'email_error' => $mailError];
}

/**
 * SLA monitor futtatása. Minden lépés atomikus feltételes UPDATE-tel jelöl, így ismételt futás nem duplikál.
 */
function tb_sla_run(array $config = []): array
{
    $warnHours = max(1, min(72, (int) ($config['warn_before_hours'] ?? 4)));
    $escalateHours = max(1, min(720, (int) ($config['escalate_after_hours'] ?? 48)));
    $open = tb_sla_open_statuses();
    $placeholders = implode(',', array_fill(0, count($open), '?'));
    $result = ['warned' => 0, 'breached' => 0, 'escalated' => 0, 'sla_assigned' => 0];

    // Hiányzó SLA határidő pótlása prioritás alapján.
    $missing = db()->prepare("SELECT id, priority, created_at FROM service_tickets WHERE sla_due_at IS NULL AND status IN ($placeholders) LIMIT 200");
    $missing->execute($open);
    $setDue = db()->prepare('UPDATE service_tickets SET sla_due_at = ? WHERE id = ? AND sla_due_at IS NULL');
    foreach ($missing->fetchAll() as $row) {
        $setDue->execute([tb_sla_due_at((string) $row['priority'], strtotime((string) $row['created_at']) ?: time()), (int) $row['id']]);
        $result['sla_assigned'] += $setDue->rowCount();
    }

    $warn = db()->prepare("SELECT id, subject, sla_due_at, assigned_to_user_id FROM service_tickets WHERE status IN ($placeholders) AND sla_warned_at IS NULL AND sla_due_at IS NOT NULL AND sla_due_at > NOW() AND sla_due_at <= (NOW() + INTERVAL ? HOUR) LIMIT 200");
    $warn->execute(array_merge($open, [$warnHours]));
    $markWarn = db()->prepare('UPDATE service_tickets SET sla_warned_at = NOW() WHERE id = ? AND sla_warned_at IS NULL');
    foreach ($warn->fetchAll() as $row) {
        $markWarn->execute([(int) $row['id']]);
        if ($markWarn->rowCount() === 0) {
            continue;
        }
        $msg = 'Ticket #' . (int) $row['id'] . ' (' . $row['subject'] . ') SLA határideje: ' . $row['sla_due_at'];
        tb_notify(null, 'admin', 'SLA határidő közeleg', $msg, '/admin#service', true);
        if ((int) ($row['assigned_to_user_id'] ?? 0) > 0) {
            tb_notify((int) $row['assigned_to_user_id'], 'user', 'SLA határidő közeleg', $msg, '/mobile.html', true);
        }
        tb_sla_history((int) $row['id'], 'sla_warning', null, (string) $row['sla_due_at'], 'SLA határidő előtti figyelmeztetés.');
        $result['warned']++;
    }

    $breach = db()->prepare("SELECT id, subject, sla_due_at FROM service_tickets WHERE status IN ($placeholders) AND sla_breached_at IS NULL AND sla_due_at IS NOT NULL AND sla_due_at <= NOW() LIMIT 200");
    $breach->execute($open);
    $markBreach = db()->prepare('UPDATE service_tickets SET sla_breached_at = NOW() WHERE id = ? AND sla_breached_at IS NULL');
    foreach ($breach->fetchAll() as $row) {
        $markBreach->execute([(int) $row['id']]);
        if ($markBreach->rowCount() === 0) {
            continue;
        }
        $msg = 'Ticket #' . (int) $row['id'] . ' (' . $row['subject'] . ') SLA határideje lejárt: ' . $row['sla_due_at'];
        tb_notify(null, 'admin', 'SLA túllépés', $msg, '/admin#service', true);
        tb_alert_email('SLA túllépés – ticket #' . (int) $row['id'], $msg);
        tb_sla_history((int) $row['id'], 'sla_breach', null, (string) $row['sla_due_at'], 'SLA határidő lejárt.');
        $result['breached']++;
    }

    $waitingStatuses = ['open', 'triaged', 'waiting_customer'];
    $waitPlaceholders = implode(',', array_fill(0, count($waitingStatuses), '?'));
    $esc = db()->prepare("SELECT id, subject, priority, escalation_level FROM service_tickets WHERE status IN ($waitPlaceholders) AND escalation_level < 3 AND COALESCE(escalated_at, created_at) <= (NOW() - INTERVAL ? HOUR) AND updated_at <= (NOW() - INTERVAL ? HOUR) LIMIT 100");
    $esc->execute(array_merge($waitingStatuses, [$escalateHours, $escalateHours]));
    $bump = ['low' => 'normal', 'normal' => 'high', 'high' => 'high', 'emergency' => 'emergency'];
    $markEsc = db()->prepare('UPDATE service_tickets SET priority = ?, escalation_level = escalation_level + 1, escalated_at = NOW() WHERE id = ? AND escalation_level = ?');
    foreach ($esc->fetchAll() as $row) {
        $from = (string) $row['priority'];
        $to = $bump[$from] ?? $from;
        $markEsc->execute([$to, (int) $row['id'], (int) $row['escalation_level']]);
        if ($markEsc->rowCount() === 0) {
            continue;
        }
        $level = (int) $row['escalation_level'] + 1;
        tb_notify(null, 'admin', 'Ticket eszkaláció (' . $level . '. szint)', 'Ticket #' . (int) $row['id'] . ' (' . $row['subject'] . ') ' . $escalateHours . ' órája várakozik.', '/admin#service', true);
        tb_sla_history((int) $row['id'], 'escalation', $from, $to, 'Automatikus eszkaláció, szint: ' . $level);
        $result['escalated']++;
    }
    return $result;
}

function tb_sla_report(int $days = 90): array
{
    $days = max(1, min(730, $days));
    $openList = "'" . implode("','", tb_sla_open_statuses()) . "'";
    $stmt = db()->prepare("SELECT priority,
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ($openList) THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status IN ($openList) AND sla_due_at IS NOT NULL AND sla_due_at < NOW() THEN 1 ELSE 0 END) AS overdue_open,
            SUM(CASE WHEN status IN ('resolved','closed') AND sla_due_at IS NOT NULL AND COALESCE(closed_at, updated_at) > sla_due_at THEN 1 ELSE 0 END) AS resolved_late,
            AVG(CASE WHEN status IN ('resolved','closed') THEN TIMESTAMPDIFF(MINUTE, created_at, COALESCE(closed_at, updated_at)) END) AS avg_resolution_minutes,
            SUM(CASE WHEN escalation_level > 0 THEN 1 ELSE 0 END) AS escalated,
            SUM(CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved_count
        FROM service_tickets
        WHERE created_at >= (NOW() - INTERVAL ? DAY)
        GROUP BY priority
        ORDER BY FIELD(priority, 'emergency','high','normal','low')");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll();
    $totals = ['total' => 0, 'open_count' => 0, 'overdue_open' => 0, 'resolved_late' => 0, 'escalated' => 0];
    $weighted = 0.0;
    $resolvedCount = 0;
    $byPriority = [];
    foreach ($rows as $row) {
        $avg = $row['avg_resolution_minutes'] !== null ? round((float) $row['avg_resolution_minutes'] / 60, 1) : null;
        $item = [
            'priority' => $row['priority'],
            'total' => (int) $row['total'],
            'open_count' => (int) $row['open_count'],
            'overdue_open' => (int) $row['overdue_open'],
            'resolved_late' => (int) $row['resolved_late'],
            'escalated' => (int) $row['escalated'],
            'avg_resolution_hours' => $avg,
            'sla_hours' => tb_sla_default_hours((string) $row['priority']),
        ];
        $byPriority[] = $item;
        foreach ($totals as $k => $_) {
            $totals[$k] += $item[$k];
        }
        $resolved = (int) $row['resolved_count'];
        if ($avg !== null && $resolved > 0) {
            $weighted += $avg * $resolved;
            $resolvedCount += $resolved;
        }
    }
    $totals['avg_resolution_hours'] = $resolvedCount > 0 ? round($weighted / $resolvedCount, 1) : null;
    $totals['breached_total'] = $totals['overdue_open'] + $totals['resolved_late'];
    return ['days' => $days, 'totals' => $totals, 'by_priority' => $byPriority];
}
