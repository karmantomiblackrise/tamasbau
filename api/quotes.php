<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'POST') {
    validate_csrf_token();
}

if ($method === 'POST' && clean_string($payload['action'] ?? 'create') === 'create') {
    $name = clean_string($payload['name'] ?? '', 120);
    $phone = clean_string($payload['phone'] ?? '', 40);
    $email = clean_string($payload['email'] ?? '', 190);
    $workType = clean_string($payload['work_type'] ?? ($payload['type'] ?? ''), 100);
    $message = clean_string($payload['message'] ?? '', 2000);

    if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $workType === '' || $message === '') {
        send_json(['ok' => false, 'error' => 'Hiányos ajánlatkérési adatok.'], 422);
    }

    $stmt = db()->prepare('INSERT INTO quotes (name, phone, email, work_type, message, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $email, $workType, $message, 'new']);
    send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
}

if ($method === 'GET') {
    require_admin();
    $stmt = db()->query('SELECT id, name, phone, email, work_type, message, status, created_at FROM quotes ORDER BY created_at DESC');
    $quotes = array_map(static function (array $q): array {
        return [
            'id' => (int) $q['id'],
            'name' => $q['name'],
            'phone' => $q['phone'],
            'email' => $q['email'],
            'type' => $q['work_type'],
            'message' => $q['message'],
            'status' => $q['status'],
            'date' => substr((string) $q['created_at'], 0, 10),
            'created_at' => $q['created_at'],
        ];
    }, $stmt->fetchAll());

    send_json(['ok' => true, 'quotes' => $quotes]);
}

if ($method === 'POST') {
    $action = clean_string($payload['action'] ?? '');

    if ($action === 'update_status') {
        require_admin();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlatkérés.'], 422);
        }
        $status = clean_string($payload['status'] ?? '', 30);
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen státusz.'], 422);
        }

        $stmt = db()->prepare('UPDATE quotes SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM quotes WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                send_json(['ok' => false, 'error' => 'Ajánlatkérés nem található.'], 404);
            }
        }
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        require_admin();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen ajánlatkérés.'], 422);
        }
        $stmt = db()->prepare('DELETE FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Ajánlatkérés nem található.'], 404);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
