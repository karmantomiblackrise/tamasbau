<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$healthcheckKeyConfigured = strlen(HEALTHCHECK_KEY) >= 24;
$providedKey = (string) ($_POST['healthcheck_key'] ?? '');
$authorized = $healthcheckKeyConfigured && $providedKey !== '' && hash_equals(HEALTHCHECK_KEY, $providedKey);
$wantsRepair = ($_SERVER['REQUEST_METHOD'] === 'POST')
    && (string) ($_POST['mode'] ?? '') === 'repair'
    && (string) ($_POST['confirm'] ?? '') === 'YES';
$messages = [];
$checks = [];
$usersSchemaCompatible = false;

try {
    $pdo = db();
    $checks[] = ['Adatbázis kapcsolat', 'OK'];

    $usersExists = table_exists($pdo, 'users');
    $checks[] = ['users tábla', $usersExists ? 'OK' : 'Hiányzik'];

    if ($usersExists) {
        $requiredColumns = ['username', 'password_hash', 'role', 'is_active'];
        $missingColumns = [];
        foreach ($requiredColumns as $column) {
            if (!column_exists($pdo, 'users', $column)) {
                $missingColumns[] = $column;
            }
        }

        if ($missingColumns) {
            $checks[] = ['users mezők', 'Hiányzó mezők: ' . implode(', ', $missingColumns)];
            $adminCount = 0;
            $usersSchemaCompatible = false;
        } else {
            $checks[] = ['users mezők', 'OK'];
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            $checks[] = ['Admin rekord', $adminCount > 0 ? 'OK' : 'Hiányzik'];
            $usersSchemaCompatible = true;
        }
    } else {
        $adminCount = 0;
        $usersSchemaCompatible = true;
    }

    if ($authorized && $wantsRepair && csrf_validate($_POST['csrf_token'] ?? null)) {
        if (!$usersSchemaCompatible) {
            $messages[] = ['error', 'A users tábla szerkezete nem kompatibilis. Futtass teljes schema importot a helyreállítás előtt.'];
            throw new RuntimeException('Inkompatibilis users séma');
        }

        $newPassword = (string) ($_POST['new_password'] ?? '');
        if (mb_strlen($newPassword) < 12) {
            $messages[] = ['error', 'Az új admin jelszó legalább 12 karakter legyen.'];
            throw new RuntimeException('Gyenge helyreállítási jelszó');
        }

        if ($adminCount > 0) {
            $messages[] = ['error', 'Létező admin rekord esetén ez az endpoint nem írja felül a jelszót. Használd a jelszócserét az admin felületen.'];
            throw new RuntimeException('Létező admin felülírás tiltva');
        }

        $adminUsernameExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn() > 0;
        if ($adminUsernameExists) {
            $messages[] = ['error', 'Már létezik admin felhasználónév, de nincs admin szerepkör. Ez manuális felülvizsgálatot igényel.'];
            throw new RuntimeException('Inkonzisztens admin rekord');
        }

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
            VALUES ('admin', :password_hash, 'admin', 1)");
        $seedStmt->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);

        $pdo->commit();
        app_log('setup-check helyreállítás futtatva: hiányzó admin rekord létrehozva.');
        $messages[] = ['success', 'Helyreállítás lefutott. Az admin rekord létrehozva a megadott jelszóval.'];
    } elseif ($wantsRepair && !$authorized) {
        $messages[] = ['error', 'Helyreállításhoz érvényes kulcs szükséges.'];
    } elseif ($wantsRepair) {
        $messages[] = ['error', 'CSRF vagy session hiba: a helyreállítás nem futott le.'];
    }

    if (!$healthcheckKeyConfigured) {
        $messages[] = ['error', 'A HEALTHCHECK_KEY nincs biztonságosan beállítva (min. 24 karakter szükséges).'];
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!($exception instanceof RuntimeException)) {
        $checks[] = ['Adatbázis kapcsolat', db_connection_error_message($exception)];
    }
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
        <form method="post" action="<?= h(app_url('admin/setup-check.php')) ?>">
            <input type="hidden" name="mode" value="repair">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label for="healthcheck_key">HEALTHCHECK kulcs</label>
            <input id="healthcheck_key" name="healthcheck_key" type="password" required>
            <label for="new_password">Új admin jelszó (min. 12 karakter)</label>
            <input id="new_password" name="new_password" type="password" minlength="12" required>
            <label for="confirm">Írd be: YES</label>
            <input id="confirm" name="confirm" type="text" required>
            <button class="btn" type="submit" style="margin-top:10px;">Helyreállítás futtatása</button>
        </form>
        <p class="helper" style="margin-top:10px;">A helyreállítás futtatásához add meg a `config.php` fájlban beállított HEALTHCHECK kulcsot.</p>
    </div>
</main>
</body>
</html>
