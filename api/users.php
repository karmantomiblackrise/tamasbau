<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$admin = require_admin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$assignableRoles = ['user', 'admin', 'superadmin'];
$privilegedRoles = ['admin', 'superadmin'];

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
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lockedTargetStmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $lockedTargetStmt->execute([$id]);
            $lockedTarget = $lockedTargetStmt->fetch();
            if (!$lockedTarget) {
                throw new RuntimeException('Felhasználó nem található.');
            }

            if (isset($payload['role'])) {
                $role = clean_string((string) $payload['role']);
                if (!in_array($role, $assignableRoles, true)) {
                    throw new RuntimeException('Érvénytelen szerepkör.');
                }
                if ($role === 'superadmin' && ($admin['role'] ?? 'admin') !== 'superadmin') {
                    throw new DomainException('Superadmin szerepkör kiosztásához superadmin jogosultság szükséges.');
                }
                if (($lockedTarget['role'] ?? 'user') === 'superadmin' && ($admin['role'] ?? 'admin') !== 'superadmin') {
                    throw new DomainException('Superadmin felhasználó csak superadmin által módosítható.');
                }
                if ((int) $admin['id'] === $id && $role === 'superadmin' && ($admin['role'] ?? 'admin') !== 'superadmin') {
                    throw new DomainException('Saját superadmin jogosultság emelés nem engedélyezett.');
                }
                if ((int) $admin['id'] === $id && !in_array($role, ['admin', 'superadmin'], true)) {
                    throw new DomainException('Saját admin szerepkör nem vehető el.');
                }
                $fields[] = 'role = ?';
                $values[] = $role;
            }

            if (array_key_exists('is_active', $payload)) {
                $newActive = ((int) $payload['is_active']) === 1 ? 1 : 0;
                if ((int) $admin['id'] === $id && $newActive !== 1) {
                    throw new DomainException('Saját admin fiók nem tiltható le.');
                }
                if (in_array((string) ($lockedTarget['role'] ?? 'user'), $privilegedRoles, true) && $newActive !== 1) {
                    $activeAdminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role IN ('admin', 'superadmin') AND is_active = 1 FOR UPDATE");
                    $activeAdminCount = (int) ($activeAdminCountStmt->fetchColumn() ?: 0);
                    if ($activeAdminCount <= 1) {
                        throw new DomainException('Az utolsó aktív admin nem tiltható le.');
                    }
                }
                $fields[] = 'is_active = ?';
                $values[] = $newActive;
            }

            $effectiveRole = isset($role) ? $role : (string) ($lockedTarget['role'] ?? 'user');
            $effectiveActive = isset($newActive) ? $newActive : (int) ($lockedTarget['is_active'] ?? 0);
            $wasPrivilegedActive = in_array((string) ($lockedTarget['role'] ?? 'user'), $privilegedRoles, true) && (int) ($lockedTarget['is_active'] ?? 0) === 1;
            $willRemainPrivilegedActive = in_array($effectiveRole, $privilegedRoles, true) && $effectiveActive === 1;
            if ($wasPrivilegedActive && !$willRemainPrivilegedActive) {
                $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'superadmin') AND is_active = 1 AND id <> ? FOR UPDATE");
                $remainingStmt->execute([$id]);
                $remaining = (int) ($remainingStmt->fetchColumn() ?: 0);
                if ($remaining <= 0) {
                    throw new DomainException('Az utolsó aktív admin nem alakítható át vagy tiltható le.');
                }
            }

            if (!$fields) {
                throw new RuntimeException('Nincs módosítandó adat.');
            }

            $values[] = $id;
            $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($values);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof DomainException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'Felhasználó nem található.') {
                send_json(['ok' => false, 'error' => $e->getMessage()], 404);
            }
            if ($e instanceof RuntimeException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            send_json(['ok' => false, 'error' => 'A felhasználó frissítése sikertelen.'], 500);
        }
        send_json(['ok' => true]);
    }

    if ($action === 'change_password') {
        $newPassword = (string) ($payload['password'] ?? '');
        if (mb_strlen($newPassword) < 8) {
            send_json(['ok' => false, 'error' => 'A jelszónak legalább 8 karakter hosszúnak kell lennie.'], 422);
        }

        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
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
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lockedTargetStmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $lockedTargetStmt->execute([$id]);
            $lockedTarget = $lockedTargetStmt->fetch();
            if (!$lockedTarget) {
                throw new RuntimeException('Felhasználó nem található.');
            }
            if (($lockedTarget['role'] ?? 'user') === 'superadmin' && ($admin['role'] ?? 'admin') !== 'superadmin') {
                throw new DomainException('Superadmin felhasználó csak superadmin által törölhető.');
            }

            if (in_array((string) ($lockedTarget['role'] ?? 'user'), $privilegedRoles, true) && (int) $lockedTarget['is_active'] === 1) {
                $activeAdminCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role IN ('admin', 'superadmin') AND is_active = 1 FOR UPDATE");
                $activeAdminCount = (int) ($activeAdminCountStmt->fetchColumn() ?: 0);
                if ($activeAdminCount <= 1) {
                    throw new DomainException('Az utolsó aktív admin nem törölhető.');
                }
            }

            $orderCountStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
            $orderCountStmt->execute([$id]);
            if ((int) $orderCountStmt->fetchColumn() > 0) {
                throw new DomainException('Rendeléssel rendelkező felhasználó nem törölhető.');
            }
            $estimateCountStmt = $pdo->prepare('SELECT COUNT(*) FROM saved_estimates WHERE user_id = ?');
            $estimateCountStmt->execute([$id]);
            if ((int) $estimateCountStmt->fetchColumn() > 0) {
                throw new DomainException('Mentett kalkulációval rendelkező felhasználó nem törölhető.');
            }
            $quoteReplyCountStmt = $pdo->prepare('SELECT COUNT(*) FROM quote_replies WHERE admin_user_id = ?');
            $quoteReplyCountStmt->execute([$id]);
            if ((int) $quoteReplyCountStmt->fetchColumn() > 0) {
                throw new DomainException('Ajánlatkérés-válasszal rendelkező admin felhasználó nem törölhető.');
            }

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Felhasználó nem található.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof DomainException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'Felhasználó nem található.') {
                send_json(['ok' => false, 'error' => $e->getMessage()], 404);
            }
            if ($e instanceof RuntimeException) {
                send_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            send_json(['ok' => false, 'error' => 'A felhasználó törlése sikertelen.'], 500);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
