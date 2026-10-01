<?php
declare(strict_types=1);

/*
 * Workflow motor: szabály alapú, idempotens job sor (pending → processing → done/failed)
 * exponenciális backoff retry stratégiával. Cron nélkül is futtatható (admin gomb vagy
 * admin oldalbetöltéskori "tick"), cronból pedig az api/cron.php szkripttel.
 */

const TB_WORKFLOW_STALE_MINUTES = 15;
const TB_WORKFLOW_BATCH = 25;

function tb_workflow_handlers(): array
{
    return [
        'quote_request_to_lead' => 'tb_wf_quote_request_to_lead',
        'quote_sent_followup' => 'tb_wf_quote_sent_followup',
        'accepted_quote_to_project' => 'tb_wf_accepted_quote_to_project',
        'project_closed_review_request' => 'tb_wf_project_closed_review_request',
        'warranty_expiry_reminder' => 'tb_wf_warranty_expiry_reminder',
        'urgent_ticket_alert' => 'tb_wf_urgent_ticket_alert',
        'service_sla_monitor' => 'tb_wf_service_sla_monitor',
        'invoice_prepare' => 'tb_wf_invoice_prepare',
        'negative_review_followup' => 'tb_wf_negative_review_followup',
    ];
}

function tb_workflow_backoff_seconds(int $attempt): int
{
    $attempt = max(1, $attempt);
    return (int) min(3600, 60 * (2 ** ($attempt - 1)));
}

function tb_workflow_rule(string $ruleKey): ?array
{
    $stmt = db()->prepare('SELECT id, rule_key, title, is_enabled, config_json FROM workflow_rules WHERE rule_key = ? LIMIT 1');
    $stmt->execute([$ruleKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tb_workflow_rule_config(string $ruleKey): array
{
    $rule = tb_workflow_rule($ruleKey);
    return $rule ? tb_json_decode_array($rule['config_json'] ?? null) : [];
}

/**
 * Job sorba állítása. Csak engedélyezett szabálynál; azonos idempotency kulcs esetén nem duplikál.
 * Soha nem dob kivételt, hogy a fő üzleti folyamatot ne törje el.
 */
function tb_workflow_enqueue(string $ruleKey, string $idempotencyKey, array $payload = [], string $triggerType = 'event', ?string $runAfter = null): bool
{
    try {
        $rule = tb_workflow_rule($ruleKey);
        if (!$rule || (int) $rule['is_enabled'] !== 1) {
            return false;
        }
        $stmt = db()->prepare('INSERT IGNORE INTO workflow_jobs (rule_id, idempotency_key, trigger_type, payload_json, status, retries, max_retries, next_attempt_at) VALUES (?, ?, ?, ?, ?, 0, 3, ?)');
        $stmt->execute([(int) $rule['id'], clean_string($idempotencyKey, 120), clean_string($triggerType, 80), tb_json($payload), 'pending', $runAfter]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        app_log_error('workflow_enqueue_failed', $e, ['rule' => $ruleKey]);
        return false;
    }
}

/**
 * Üzleti esemény → workflow jobok. A hook pontokból (operations.php, orders.php, quotes.php) hívjuk.
 */
function tb_workflow_event(string $event, array $data): void
{
    try {
        switch ($event) {
            case 'quote_request_created':
                tb_workflow_enqueue('quote_request_to_lead', 'quote_request_to_lead:quote:' . (int) $data['quote_id'], ['quote_id' => (int) $data['quote_id']]);
                break;
            case 'crm_quote_status':
                $quoteId = (int) $data['crm_quote_id'];
                if (($data['to'] ?? '') === 'sent') {
                    tb_workflow_enqueue('quote_sent_followup', 'quote_sent_followup:crm_quote:' . $quoteId, ['crm_quote_id' => $quoteId]);
                }
                if (($data['to'] ?? '') === 'accepted') {
                    tb_workflow_enqueue('accepted_quote_to_project', 'accepted_quote_to_project:crm_quote:' . $quoteId, ['crm_quote_id' => $quoteId]);
                }
                break;
            case 'project_status':
                if (($data['to'] ?? '') === 'completed') {
                    $projectId = (int) $data['project_id'];
                    tb_workflow_enqueue('project_closed_review_request', 'project_closed_review_request:project:' . $projectId, ['source_type' => 'project', 'source_id' => $projectId]);
                    tb_workflow_enqueue('invoice_prepare', 'invoice_prepare:project:' . $projectId, ['source_type' => 'project', 'source_id' => $projectId]);
                }
                break;
            case 'work_order_closed':
                $workOrderId = (int) $data['work_order_id'];
                tb_workflow_enqueue('project_closed_review_request', 'project_closed_review_request:work_order:' . $workOrderId, ['source_type' => 'work_order', 'source_id' => $workOrderId]);
                break;
            case 'order_status':
                if (in_array(($data['to'] ?? ''), ['completed', 'delivered'], true)) {
                    $orderId = (int) $data['order_id'];
                    tb_workflow_enqueue('invoice_prepare', 'invoice_prepare:order:' . $orderId, ['source_type' => 'order', 'source_id' => $orderId]);
                }
                break;
            case 'ticket_created':
            case 'ticket_priority':
                if (($data['priority'] ?? '') === 'emergency') {
                    $ticketId = (int) $data['ticket_id'];
                    tb_workflow_enqueue('urgent_ticket_alert', 'urgent_ticket_alert:ticket:' . $ticketId, ['ticket_id' => $ticketId]);
                }
                break;
            case 'review_submitted':
                if ((int) ($data['rating'] ?? 5) < 3) {
                    $reviewId = (int) $data['review_id'];
                    tb_workflow_enqueue('negative_review_followup', 'negative_review_followup:review:' . $reviewId, ['review_id' => $reviewId]);
                }
                break;
        }
    } catch (Throwable $e) {
        app_log_error('workflow_event_failed', $e, ['event' => $event]);
    }
}

/**
 * Időzített (cron nélküli) jobok: óránkénti SLA ellenőrzés, napi garancia emlékeztető.
 */
function tb_workflow_enqueue_scheduled(): void
{
    tb_workflow_enqueue('service_sla_monitor', 'service_sla_monitor:' . date('YmdH'), [], 'schedule');
    tb_workflow_enqueue('warranty_expiry_reminder', 'warranty_expiry_reminder:' . date('Ymd'), [], 'schedule');
}

/**
 * Egy job végrehajtása (már "processing" állapotban). Siker: done; hiba: retry backoff-fal vagy failed.
 */
function tb_workflow_execute_job(array $job): array
{
    $jobId = (int) $job['id'];
    $ruleKey = (string) ($job['rule_key'] ?? '');
    $handlers = tb_workflow_handlers();
    try {
        if ((int) ($job['is_enabled'] ?? 0) !== 1) {
            throw new TbWorkflowPermanentError('A workflow szabály le van tiltva.');
        }
        if (!isset($handlers[$ruleKey]) || !function_exists($handlers[$ruleKey])) {
            throw new TbWorkflowPermanentError('Ismeretlen workflow szabály: ' . $ruleKey);
        }
        $payload = tb_json_decode_array($job['payload_json'] ?? null);
        $config = tb_json_decode_array($job['config_json'] ?? null);
        $result = call_user_func($handlers[$ruleKey], $payload, $config);
        $done = db()->prepare("UPDATE workflow_jobs SET status = 'done', processed_at = NOW(), last_error = NULL, result_json = ?, next_attempt_at = NULL WHERE id = ? AND status = 'processing'");
        $done->execute([tb_json(is_array($result) ? $result : ['result' => $result]), $jobId]);
        return ['id' => $jobId, 'rule_key' => $ruleKey, 'status' => 'done'];
    } catch (Throwable $e) {
        $retries = (int) ($job['retries'] ?? 0) + 1;
        $max = max(1, (int) ($job['max_retries'] ?? 3));
        $permanent = $e instanceof TbWorkflowPermanentError;
        $message = clean_string(redact_secrets($e->getMessage()), 480);
        if ($permanent || $retries >= $max) {
            $fail = db()->prepare("UPDATE workflow_jobs SET status = 'failed', retries = ?, last_error = ?, processed_at = NOW(), next_attempt_at = NULL WHERE id = ?");
            $fail->execute([$retries, $message, $jobId]);
            tb_notify(null, 'admin', 'Workflow job sikertelen', 'Job #' . $jobId . ' (' . $ruleKey . '): ' . $message, '/admin-center.html#workflows');
            if (!$permanent) {
                app_log_error('workflow_job_failed', $e, ['job_id' => $jobId, 'rule' => $ruleKey]);
            }
            return ['id' => $jobId, 'rule_key' => $ruleKey, 'status' => 'failed', 'error' => $message];
        }
        $delay = tb_workflow_backoff_seconds($retries);
        $retry = db()->prepare("UPDATE workflow_jobs SET status = 'pending', retries = ?, last_error = ?, next_attempt_at = (NOW() + INTERVAL ? SECOND) WHERE id = ?");
        $retry->execute([$retries, $message, $delay, $jobId]);
        return ['id' => $jobId, 'rule_key' => $ruleKey, 'status' => 'retry', 'retry_in_seconds' => $delay, 'error' => $message];
    }
}

function tb_workflow_claim(int $jobId): ?array
{
    $claim = db()->prepare("UPDATE workflow_jobs SET status = 'processing', started_at = NOW() WHERE id = ? AND status = 'pending'");
    $claim->execute([$jobId]);
    if ($claim->rowCount() === 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT j.id, j.rule_id, j.idempotency_key, j.payload_json, j.retries, j.max_retries, r.rule_key, r.is_enabled, r.config_json FROM workflow_jobs j LEFT JOIN workflow_rules r ON r.id = j.rule_id WHERE j.id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Esedékes pending jobok feldolgozása. Fájl lock biztosítja, hogy egyszerre csak egy futás legyen.
 */
function tb_workflow_run_pending(int $limit = TB_WORKFLOW_BATCH, ?int $onlyJobId = null): array
{
    $lockPath = dirname(__DIR__, 2) . '/logs/workflow-runner.lock';
    if (!is_dir(dirname($lockPath))) {
        @mkdir(dirname($lockPath), 0775, true);
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock !== false) {
            fclose($lock);
        }
        return ['locked' => true, 'processed' => []];
    }
    $processed = [];
    try {
        $stale = db()->prepare("UPDATE workflow_jobs SET status = 'pending', last_error = 'Megszakadt feldolgozás, újrapróbálás.' WHERE status = 'processing' AND started_at < (NOW() - INTERVAL ? MINUTE)");
        $stale->execute([TB_WORKFLOW_STALE_MINUTES]);

        if ($onlyJobId !== null) {
            $ids = [$onlyJobId];
        } else {
            $stmt = db()->prepare("SELECT id FROM workflow_jobs WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY created_at ASC, id ASC LIMIT ?");
            $stmt->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        }
        foreach ($ids as $jobId) {
            $job = tb_workflow_claim($jobId);
            if ($job === null) {
                continue;
            }
            $processed[] = tb_workflow_execute_job($job);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return ['locked' => false, 'processed' => $processed];
}

/**
 * Cron nélküli automatika: időzített jobok sorba állítása + esedékesek futtatása.
 * Legfeljebb 5 percenként fut (session-független, fájl alapú időbélyeg).
 */
function tb_automation_tick(bool $force = false): array
{
    $stampPath = dirname(__DIR__, 2) . '/logs/automation-tick.stamp';
    $last = is_file($stampPath) ? (int) @file_get_contents($stampPath) : 0;
    if (!$force && $last > time() - 300) {
        return ['skipped' => true];
    }
    @file_put_contents($stampPath, (string) time(), LOCK_EX);
    try {
        tb_workflow_enqueue_scheduled();
        return tb_workflow_run_pending();
    } catch (Throwable $e) {
        app_log_error('automation_tick_failed', $e);
        return ['error' => true];
    }
}

final class TbWorkflowPermanentError extends RuntimeException
{
}

/* ---------- Handlerek ---------- */

function tb_wf_quote_request_to_lead(array $payload, array $config): array
{
    $quoteId = (int) ($payload['quote_id'] ?? 0);
    $existing = db()->prepare('SELECT id FROM leads WHERE quote_id = ? LIMIT 1');
    $existing->execute([$quoteId]);
    $lead = $existing->fetch();
    if ($lead) {
        return ['lead_id' => (int) $lead['id'], 'created' => false];
    }
    $quote = db()->prepare('SELECT id, name, email, phone, work_type, message FROM quotes WHERE id = ? LIMIT 1');
    $quote->execute([$quoteId]);
    $row = $quote->fetch();
    if (!$row) {
        throw new TbWorkflowPermanentError('Ajánlatkérés nem található: #' . $quoteId);
    }
    $insert = db()->prepare('INSERT INTO leads (quote_id, name, email, phone, source, note, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$quoteId, $row['name'], $row['email'], $row['phone'], 'quote_form', clean_string((string) $row['work_type'] . ': ' . (string) $row['message'], 5000), 'new']);
    return ['lead_id' => (int) db()->lastInsertId(), 'created' => true];
}

function tb_wf_quote_sent_followup(array $payload, array $config): array
{
    $quoteId = (int) ($payload['crm_quote_id'] ?? 0);
    $hours = max(1, min(720, (int) ($config['delay_hours'] ?? 72)));
    $stmt = db()->prepare("UPDATE crm_quotes SET next_action_at = (NOW() + INTERVAL ? HOUR), next_action_note = COALESCE(next_action_note, 'Automatikus follow-up: ajánlat visszajelzés kérése.') WHERE id = ? AND next_action_at IS NULL AND status IN ('sent','viewed')");
    $stmt->execute([$hours, $quoteId]);
    return ['crm_quote_id' => $quoteId, 'scheduled' => $stmt->rowCount() > 0, 'delay_hours' => $hours];
}

function tb_wf_accepted_quote_to_project(array $payload, array $config): array
{
    $quoteId = (int) ($payload['crm_quote_id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, lead_id, user_id, project_id, title, amount_cents, status FROM crm_quotes WHERE id = ? FOR UPDATE');
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch();
        if (!$quote) {
            throw new TbWorkflowPermanentError('Ajánlat nem található: #' . $quoteId);
        }
        if ((int) ($quote['project_id'] ?? 0) > 0) {
            $pdo->commit();
            return ['project_id' => (int) $quote['project_id'], 'created' => false];
        }
        if ((string) $quote['status'] !== 'accepted') {
            $pdo->commit();
            return ['skipped' => 'not_accepted'];
        }
        $insert = $pdo->prepare('INSERT INTO projects (user_id, lead_id, title, budget_cents, status) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$quote['user_id'], $quote['lead_id'], clean_string((string) $quote['title'], 180), $quote['amount_cents'], 'approved']);
        $projectId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE crm_quotes SET project_id = ? WHERE id = ?')->execute([$projectId, $quoteId]);
        $pdo->prepare('INSERT INTO project_timeline (project_id, actor_user_id, event_type, event_note, related_type, related_id) VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$projectId, 'created', 'Projekt automatikusan létrehozva elfogadott ajánlatból.', 'crm_quote', $quoteId]);
        if ((int) ($quote['lead_id'] ?? 0) > 0) {
            $pdo->prepare("UPDATE leads SET status = 'won' WHERE id = ? AND status NOT IN ('archived')")->execute([(int) $quote['lead_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    tb_notify(null, 'admin', 'Új projekt elfogadott ajánlatból', 'Ajánlat #' . $quoteId . ' → projekt #' . $projectId, '/admin#projects', true);
    return ['project_id' => $projectId, 'created' => true];
}

function tb_wf_project_closed_review_request(array $payload, array $config): array
{
    return tb_review_request_create((string) ($payload['source_type'] ?? 'project'), (int) ($payload['source_id'] ?? 0));
}

function tb_wf_warranty_expiry_reminder(array $payload, array $config): array
{
    $days = max(1, min(180, (int) ($config['days_before'] ?? 14)));
    $stmt = db()->prepare("SELECT id, user_id, subject, warranty_end FROM service_tickets WHERE warranty_active = 1 AND warranty_end IS NOT NULL AND warranty_end BETWEEN CURDATE() AND (CURDATE() + INTERVAL ? DAY) LIMIT 200");
    $stmt->execute([$days]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $msg = 'Ticket #' . (int) $row['id'] . ' (' . $row['subject'] . ') garanciája lejár: ' . $row['warranty_end'];
        if (tb_notify(null, 'admin', 'Garancia lejárat közeleg', $msg, '/admin#service', true)) {
            $count++;
        }
        if ((int) ($row['user_id'] ?? 0) > 0) {
            tb_notify((int) $row['user_id'], 'user', 'Garancia lejárat közeleg', 'A(z) "' . $row['subject'] . '" garanciája ' . $row['warranty_end'] . ' napon lejár.', null, true);
        }
    }
    return ['notified' => $count, 'days_before' => $days];
}

function tb_wf_urgent_ticket_alert(array $payload, array $config): array
{
    return tb_sla_emergency_alert((int) ($payload['ticket_id'] ?? 0));
}

function tb_wf_service_sla_monitor(array $payload, array $config): array
{
    return tb_sla_run($config);
}

function tb_wf_invoice_prepare(array $payload, array $config): array
{
    return tb_invoice_prepare((string) ($payload['source_type'] ?? ''), (int) ($payload['source_id'] ?? 0));
}

function tb_wf_negative_review_followup(array $payload, array $config): array
{
    return tb_review_negative_followup((int) ($payload['review_id'] ?? 0));
}
