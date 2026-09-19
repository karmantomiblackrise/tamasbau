<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'Jelszócsere';
$activeNav = 'change-password';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF vagy session hiba történt.';
    } else {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || mb_strlen($newPassword) < 10) {
            $error = 'Az új jelszó legalább 10 karakter legyen.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Az új jelszó és megerősítés nem egyezik.';
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => (int) $_SESSION['auth']['user_id']]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                    $error = 'A jelenlegi jelszó hibás.';
                } else {
                    $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
                    $update->execute([
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'id' => (int) $user['id'],
                    ]);
                    flash_set('success', 'Jelszó sikeresen módosítva.');
                    redirect('admin/change-password.php');
                }
            } catch (Throwable $exception) {
                $error = db_connection_error_message($exception);
            }
        }
    }
}

require_once __DIR__ . '/partials/header.php';
?>
<div class="form-card">
    <?php if ($error): ?>
        <div class="flash flash--error"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <label for="current_password">Jelenlegi jelszó</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>

        <label for="new_password" style="margin-top:10px;display:block;">Új jelszó (min. 10 karakter)</label>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>

        <label for="confirm_password" style="margin-top:10px;display:block;">Új jelszó megerősítése</label>
        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>

        <button class="btn" type="submit" style="margin-top:14px;">Jelszó mentése</button>
    </form>
</div>
<?php require_once __DIR__ . '/partials/footer.php';
