<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'POST' && clean_string($payload['action'] ?? 'create') === 'create') {
    $name = clean_string($payload['name'] ?? '', 120);
    $phone = clean_string($payload['phone'] ?? '', 40);
    $email = clean_string($payload['email'] ?? '', 190);
    $workType = clean_string($payload['work_type'] ?? '', 100);
    $message = clean_string($payload['message'] ?? '', 2000);

    if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $workType === '' || $message === '') {
        send_json(['ok' => false, 'error' => 'Hiányos ajánlatkérési adatok.'], 422);
    }

    $stmt = db()->prepare('INSERT INTO quotes (name, phone, email, work_type, message, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $email, $workType, $message, 'new']);
    send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
}

require_admin();

if ($method === 'GET') {
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
    $id = (int) ($payload['id'] ?? 0);

    if ($id <= 0) {
        send_json(['ok' => false, 'error' => 'Érvénytelen ajánlatkérés.'], 422);
    }

    if ($action === 'update_status') {
        $status = clean_string($payload['status'] ?? '', 30);
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            send_json(['ok' => false, 'error' => 'Érvénytelen státusz.'], 422);
        }

        $stmt = db()->prepare('UPDATE quotes SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
