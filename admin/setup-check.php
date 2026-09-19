<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$providedKey = (string) ($_GET['key'] ?? '');
$authorized = $providedKey !== '' && hash_equals(HEALTHCHECK_KEY, $providedKey);
$wantsRepair = ($_SERVER['REQUEST_METHOD'] === 'POST')
    && (string) ($_POST['mode'] ?? '') === 'repair'
    && (string) ($_POST['confirm'] ?? '') === 'YES';
$repairPassword = null;

$messages = [];
$checks = [];

try {
    $pdo = db();
    $checks[] = ['Adatbázis kapcsolat', 'OK'];

    $usersExists = table_exists($pdo, 'users');
    $checks[] = ['users tábla', $usersExists ? 'OK' : 'Hiányzik'];

    if ($usersExists) {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $checks[] = ['Admin rekord', $adminCount > 0 ? 'OK' : 'Hiányzik'];
    } else {
        $adminCount = 0;
    }

    if ($authorized && $wantsRepair && csrf_validate($_POST['csrf_token'] ?? null)) {
        $repairPassword = bin2hex(random_bytes(8));
        $pdo->beginTransaction();

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','editor') NOT NULL DEFAULT 'editor',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $seedStmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, is_active)
            VALUES ('admin', :password_hash, 'admin', 1)
            ON DUPLICATE KEY UPDATE
                password_hash = VALUES(password_hash),
                role = VALUES(role),
                is_active = VALUES(is_active)");
        $seedStmt->execute([
            'password_hash' => password_hash($repairPassword, PASSWORD_DEFAULT),
        ]);

        $pdo->commit();
        app_log('setup-check helyreállítás futtatva: users tábla/admin seed biztosítva.');
        $messages[] = ['success', 'Helyreállítás lefutott. Az új ideiglenes jelszó: ' . $repairPassword];
    } elseif ($authorized && $wantsRepair) {
        $messages[] = ['error', 'CSRF vagy session hiba: a helyreállítás nem futott le.'];
    } elseif ($wantsRepair && !$authorized) {
        $messages[] = ['error', 'Helyreállításhoz érvényes kulcs szükséges.'];
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $checks[] = ['Adatbázis kapcsolat', db_connection_error_message($exception)];
}
?><!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin setup-check</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/admin.css')) ?>">
</head>
<body>
<main style="max-width:920px;margin:20px auto;padding:0 16px;">
    <h1>Admin setup-check</h1>
    <p class="helper">Ez az oldal alapértelmezetten csak ellenőriz. Javítás kizárólag kulccsal és explicit megerősítéssel fut.</p>

    <?php foreach ($messages as [$type, $message]): ?>
        <div class="flash flash--<?= h($type) ?>"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Ellenőrzés</th><th>Eredmény</th></tr></thead>
            <tbody>
            <?php foreach ($checks as [$name, $result]): ?>
                <tr><td><?= h($name) ?></td><td><?= h($result) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="form-card" style="margin-top:16px;">
        <h2>Biztonságos helyreállítás</h2>
        <p class="helper">Csak akkor futtasd, ha biztosan friss telepítésen dolgozol és helyre kell állítani az admin alaprekordot.</p>
        <form method="post" action="<?= h(app_url('admin/setup-check.php') . ($providedKey !== '' ? '?key=' . urlencode($providedKey) : '')) ?>">
            <input type="hidden" name="mode" value="repair">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label for="confirm">Írd be: YES</label>
            <input id="confirm" name="confirm" type="text" required>
            <button class="btn" type="submit" style="margin-top:10px;">Helyreállítás futtatása</button>
        </form>
        <?php if (!$authorized): ?>
            <p class="helper" style="margin-top:10px;">Nincs érvényes kulcs. Használat: <code>?key=SAJAT_KULCS</code></p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
