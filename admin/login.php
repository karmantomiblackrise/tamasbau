<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (is_admin_user()) {
    redirect('admin/dashboard.php');
}

$error = null;
$username = '';
$message = isset($_GET['logged_out']) ? 'Sikeres kijelentkezés.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Biztonsági hiba: CSRF vagy session probléma. Frissítsd az oldalt és próbáld újra.';
    } elseif ($username === '' || $password === '') {
        $error = 'Kérlek add meg a felhasználónevet és jelszót.';
    } else {
        try {
            $pdo = db();

            if (!table_exists($pdo, 'users')) {
                $error = 'Hiányzó users tábla az adatbázisban.';
            } else {
                $requiredColumns = ['username', 'password_hash', 'role', 'is_active'];
                $missingColumns = [];
                foreach ($requiredColumns as $column) {
                    if (!column_exists($pdo, 'users', $column)) {
                        $missingColumns[] = $column;
                    }
                }

                if ($missingColumns) {
                    $error = 'A users tábla nem kompatibilis, hiányzó mezők: ' . implode(', ', $missingColumns) . '.';
                } else {
                    $user = find_user_by_username($pdo, $username);

                    if (
                        !$user
                        || !password_verify($password, $user['password_hash'])
                        || (int) $user['is_active'] !== 1
                        || $user['role'] !== 'admin'
                    ) {
                        $error = 'Hibás felhasználónév vagy jelszó.';
                    } else {
                        login_user($user);
                        $updateLogin = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
                        $updateLogin->execute(['id' => (int) $user['id']]);

                        flash_set('success', 'Sikeres bejelentkezés.');
                        redirect('admin/dashboard.php');
                    }
                }
            }
        } catch (Throwable $exception) {
            $error = db_connection_error_message($exception);
        }
    }
}
?><!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin bejelentkezés</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/admin.css')) ?>">
</head>
<body>
<main style="display:grid;place-items:center;min-height:100vh;padding:16px;">
    <div class="form-card" style="width:100%;max-width:420px;">
        <h1 style="margin-top:0;">Admin bejelentkezés</h1>
        <p class="helper">Lépj be az admin felületre.</p>

        <?php if ($error): ?>
            <div class="flash flash--error"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="flash flash--success"><?= h($message) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label for="username">Felhasználónév</label>
            <input id="username" name="username" type="text" autocomplete="username" value="<?= h($username) ?>" required>

            <label for="password" style="margin-top:10px;display:block;">Jelszó</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button class="btn" type="submit" style="margin-top:14px;">Belépés</button>
        </form>
    </div>
</main>
</body>
</html>
