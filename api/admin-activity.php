<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
}

require_admin();
enforce_rate_limit('admin_activity_get', 120, 900);

$limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
$stmt = db()->prepare(
    'SELECT l.id, l.admin_user_id, u.name AS admin_name, l.event_type, l.target_type, l.target_id, l.details, l.created_at
     FROM admin_activity_logs l
     INNER JOIN users u ON u.id = l.admin_user_id
     ORDER BY l.created_at DESC
     LIMIT ?'
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$logs = array_map(static function (array $row): array {
    $details = [];
    if (!empty($row['details'])) {
        $decoded = json_decode((string) $row['details'], true);
        if (is_array($decoded)) {
            $details = $decoded;
        }
    }
    return [
        'id' => (int) $row['id'],
        'admin_user_id' => (int) $row['admin_user_id'],
        'admin_name' => $row['admin_name'],
        'event_type' => $row['event_type'],
        'target_type' => $row['target_type'],
        'target_id' => $row['target_id'] !== null ? (int) $row['target_id'] : null,
        'details' => $details,
        'created_at' => $row['created_at'],
    ];
}, $rows);

send_json(['ok' => true, 'logs' => $logs]);
