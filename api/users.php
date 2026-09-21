<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$admin = require_admin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $stmt = db()->query('SELECT id, name, email, role, phone, created_at, is_active FROM users ORDER BY created_at DESC');
    $users = array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        return $row;
    }, $stmt->fetchAll());

    send_json(['ok' => true, 'users' => $users]);
}

if ($method === 'POST') {
    $action = clean_string($payload['action'] ?? '');
    $id = (int) ($payload['id'] ?? 0);

    if ($id <= 0) {
        send_json(['ok' => false, 'error' => 'Érvénytelen felhasználó.'], 422);
    }

    if ($action === 'update') {
        $fields = [];
        $values = [];

        if (isset($payload['role'])) {
            $role = clean_string((string) $payload['role']);
            if (!in_array($role, ['user', 'admin'], true)) {
                send_json(['ok' => false, 'error' => 'Érvénytelen szerepkör.'], 422);
            }
            $fields[] = 'role = ?';
            $values[] = $role;
        }

        if (array_key_exists('is_active', $payload)) {
            $fields[] = 'is_active = ?';
            $values[] = ((int) $payload['is_active']) === 1 ? 1 : 0;
        }

        if (!$fields) {
            send_json(['ok' => false, 'error' => 'Nincs módosítandó adat.'], 422);
        }

        $values[] = $id;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        if ((int) $admin['id'] === $id) {
            send_json(['ok' => false, 'error' => 'Saját admin fiók nem törölhető.'], 422);
        }

        $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
