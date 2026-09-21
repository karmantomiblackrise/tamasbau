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
    $targetStmt = db()->prepare('SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1');
    $targetStmt->execute([$id]);
    $targetUser = $targetStmt->fetch();
    if (!$targetUser) {
        send_json(['ok' => false, 'error' => 'Felhasználó nem található.'], 404);
    }

    if ($action === 'update') {
        $fields = [];
        $values = [];

        if (isset($payload['role'])) {
            $role = clean_string((string) $payload['role']);
            if (!in_array($role, ['user', 'admin'], true)) {
                send_json(['ok' => false, 'error' => 'Érvénytelen szerepkör.'], 422);
            }
            if ((int) $admin['id'] === $id && $role !== 'admin') {
                send_json(['ok' => false, 'error' => 'Saját admin szerepkör nem vehető el.'], 422);
            }
            $fields[] = 'role = ?';
            $values[] = $role;
        }

        if (array_key_exists('is_active', $payload)) {
            $newActive = ((int) $payload['is_active']) === 1 ? 1 : 0;
            if ((int) $admin['id'] === $id && $newActive !== 1) {
                send_json(['ok' => false, 'error' => 'Saját admin fiók nem tiltható le.'], 422);
            }
            if (($targetUser['role'] ?? 'user') === 'admin' && $newActive !== 1) {
                $activeAdminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                if ($activeAdminCount <= 1) {
                    send_json(['ok' => false, 'error' => 'Az utolsó aktív admin nem tiltható le.'], 422);
                }
            }
            $fields[] = 'is_active = ?';
            $values[] = $newActive;
        }

        if (!$fields) {
            send_json(['ok' => false, 'error' => 'Nincs módosítandó adat.'], 422);
        }

        $values[] = $id;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                send_json(['ok' => false, 'error' => 'Felhasználó nem található.'], 404);
            }
        }
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        if ((int) $admin['id'] === $id) {
            send_json(['ok' => false, 'error' => 'Saját admin fiók nem törölhető.'], 422);
        }
        if (($targetUser['role'] ?? 'user') === 'admin' && (int) $targetUser['is_active'] === 1) {
            $activeAdminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
            if ($activeAdminCount <= 1) {
                send_json(['ok' => false, 'error' => 'Az utolsó aktív admin nem törölhető.'], 422);
            }
        }
        $orderCountStmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
        $orderCountStmt->execute([$id]);
        if ((int) $orderCountStmt->fetchColumn() > 0) {
            send_json(['ok' => false, 'error' => 'Rendeléssel rendelkező felhasználó nem törölhető.'], 422);
        }
        $estimateCountStmt = db()->prepare('SELECT COUNT(*) FROM saved_estimates WHERE user_id = ?');
        $estimateCountStmt->execute([$id]);
        if ((int) $estimateCountStmt->fetchColumn() > 0) {
            send_json(['ok' => false, 'error' => 'Mentett kalkulációval rendelkező felhasználó nem törölhető.'], 422);
        }

        $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Felhasználó nem található.'], 404);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
