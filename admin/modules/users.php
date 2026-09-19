<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Felhasználók';
$activeNav = 'users';
$error = null;

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_validate($_POST['csrf_token'] ?? null)) {
            $error = 'CSRF vagy session hiba történt.';
        } else {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            $currentUserId = (int) $_SESSION['auth']['user_id'];

            if ($targetId < 1) {
                $error = 'Érvénytelen felhasználó.';
            } else {
                $userStmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id LIMIT 1');
                $userStmt->execute(['id' => $targetId]);
                $targetUser = $userStmt->fetch();
                $activeAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();

                if (!$targetUser) {
                    $error = 'A felhasználó nem található.';
                } elseif ($action === 'toggle_role' && $targetId === $currentUserId) {
                    $error = 'A saját szerepkör nem módosítható ezen a felületen.';
                } elseif ($action === 'toggle_active' && $targetId === $currentUserId) {
                    $error = 'A saját felhasználó nem deaktiválható.';
                } elseif (
                    $targetUser['role'] === 'admin'
                    && (
                        ($action === 'toggle_role')
                        || ($action === 'toggle_active' && (int) $targetUser['is_active'] === 1)
                    )
                    && $activeAdminCount <= 1
                ) {
                    $error = 'Az utolsó aktív admin nem vehető el vagy nem deaktiválható.';
                }

                if ($error === null) {
                    if ($action === 'toggle_active') {
                        $stmt = $pdo->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = :id');
                        $stmt->execute(['id' => $targetId]);
                        flash_set('success', 'Felhasználó státusz frissítve.');
                    } elseif ($action === 'toggle_role') {
                        $stmt = $pdo->prepare("UPDATE users SET role = IF(role = 'admin', 'editor', 'admin'), updated_at = NOW() WHERE id = :id");
                        $stmt->execute(['id' => $targetId]);
                        flash_set('success', 'Felhasználó szerepkör frissítve.');
                    }
                    redirect('admin/modules/users.php');
                }
            }
        }
    }

    $users = $pdo->query('SELECT id, username, role, is_active, last_login_at, created_at FROM users ORDER BY id ASC')->fetchAll() ?: [];
} catch (Throwable $exception) {
    $users = [];
    $error = db_connection_error_message($exception);
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<?php if ($error): ?>
    <div class="flash flash--error"><?= h($error) ?></div>
<?php endif; ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Felhasználónév</th><th>Szerepkör</th><th>Aktív</th><th>Utoljára belépett</th><th>Műveletek</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= h((string) $user['id']) ?></td>
                <td><?= h($user['username']) ?></td>
                <td><?= h($user['role']) ?></td>
                <td><?= (int) $user['is_active'] === 1 ? 'Igen' : 'Nem' ?></td>
                <td><?= h((string) ($user['last_login_at'] ?? '-')) ?></td>
                <td>
                    <form class="inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= h((string) $user['id']) ?>">
                        <input type="hidden" name="action" value="toggle_role">
                        <button class="btn btn--inline" type="submit">Szerepkör váltás</button>
                    </form>
                    <form class="inline-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= h((string) $user['id']) ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <button class="btn btn--inline" type="submit">Aktiválás/deaktiválás</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="helper" style="margin-top:10px;">Megjegyzés: új felhasználó létrehozása ennél a minimál stabilizációs verziónál SQL vagy dedikált regisztrációs folyamaton keresztül történik.</p>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
