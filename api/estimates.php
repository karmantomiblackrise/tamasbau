<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$user = require_login();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $stmt = db()->prepare('SELECT id, area, wiring_type, alarm_qty, camera_qty, intercom, total, created_at
                           FROM saved_estimates
                           WHERE user_id = ?
                           ORDER BY created_at DESC');
    $stmt->execute([(int) $user['id']]);

    $estimates = array_map(static function (array $e): array {
        return [
            'id' => (int) $e['id'],
            'area' => (int) $e['area'],
            'wiring_type' => $e['wiring_type'],
            'alarm_qty' => (int) $e['alarm_qty'],
            'camera_qty' => (int) $e['camera_qty'],
            'intercom' => (int) $e['intercom'] === 1,
            'total' => (int) $e['total'],
            'date' => substr((string) $e['created_at'], 0, 10),
            'created_at' => $e['created_at'],
        ];
    }, $stmt->fetchAll());

    send_json(['ok' => true, 'estimates' => $estimates]);
}

if ($method === 'POST') {
    $area = max(0, (int) ($payload['area'] ?? 0));
    $wiringType = clean_string($payload['wiring_type'] ?? '', 40);
    $alarmQty = max(0, (int) ($payload['alarm_qty'] ?? 0));
    $cameraQty = max(0, (int) ($payload['camera_qty'] ?? 0));
    $intercom = ((int) ($payload['intercom'] ?? 0)) === 1 ? 1 : 0;
    $wiringRates = ['partial' => 8000, 'full' => 14000, 'premium' => 20000];

    if ($area <= 0 || !isset($wiringRates[$wiringType])) {
        send_json(['ok' => false, 'error' => 'Érvénytelen kalkulációs adatok.'], 422);
    }
    $total = ($area * $wiringRates[$wiringType]) + ($alarmQty * 120000) + ($cameraQty * 45000) + ($intercom === 1 ? 65000 : 0);

    $stmt = db()->prepare('INSERT INTO saved_estimates (user_id, area, wiring_type, alarm_qty, camera_qty, intercom, total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int) $user['id'], $area, $wiringType, $alarmQty, $cameraQty, $intercom, $total]);

    send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
