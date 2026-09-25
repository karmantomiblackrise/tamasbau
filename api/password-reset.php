<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();
$action = clean_string($payload['action'] ?? '');

if ($method !== 'POST') {
    send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
}

if ($action === 'request') {
    enforce_rate_limit('password_reset_request', 5, 900);
    $email = clean_string($payload['email'] ?? '', 190);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen e-mail cím.'], 422);
    }

    $stmt = db()->prepare('SELECT id, email, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $response = ['ok' => true, 'message' => 'Ha létezik ilyen e-mail cím, elküldtük a jelszó-visszaállítási adatokat.'];
    if (!$user || (int) $user['is_active'] !== 1) {
        send_json($response);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $invalidateStmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $invalidateStmt->execute([(int) $user['id']]);

        $insertStmt = $pdo->prepare('INSERT INTO password_resets (user_id, email, token_hash, expires_at, used_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NULL)');
        $insertStmt->execute([(int) $user['id'], $user['email'], $tokenHash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_json(['ok' => false, 'error' => 'A jelszó-visszaállítási kérés nem menthető.'], 500);
    }

    $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'production'));
    if ($appEnv === 'development') {
        $resetLink = '/index.html?reset_token=' . urlencode($token);
        $response['dev'] = [
            'token' => $token,
            'reset_link' => $resetLink,
        ];

        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logLine = sprintf(
            "[%s] email=%s token=%s link=%s\n",
            date('c'),
            $user['email'],
            $token,
            $resetLink
        );
        @file_put_contents($logDir . '/password-reset.log', $logLine, FILE_APPEND);
    }

    send_json($response);
}

if ($action === 'validate') {
    enforce_rate_limit('password_reset_validate', 30, 900);
    $token = trim((string) ($payload['token'] ?? ''));
    if ($token === '') {
        send_json(['ok' => false, 'error' => 'Hiányzó token.'], 422);
    }

    $stmt = db()->prepare('SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    send_json(['ok' => true, 'valid' => (bool) $stmt->fetch()]);
}

if ($action === 'reset') {
    enforce_rate_limit('password_reset_apply', 8, 900);
    $token = trim((string) ($payload['token'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    if ($token === '' || mb_strlen($password) < 8) {
        send_json(['ok' => false, 'error' => 'Érvénytelen token vagy túl rövid jelszó.'], 422);
    }

    $tokenHash = hash('sha256', $token);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $findStmt = $pdo->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE');
        $findStmt->execute([$tokenHash]);
        $resetRow = $findStmt->fetch();
        if (!$resetRow) {
            throw new RuntimeException('A jelszó-visszaállító token érvénytelen vagy lejárt.');
        }

        $userStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND is_active = 1');
        $userStmt->execute([password_hash($password, PASSWORD_DEFAULT), (int) $resetRow['user_id']]);
        if ($userStmt->rowCount() === 0) {
            throw new RuntimeException('A felhasználó nem módosítható ezzel a tokennel.');
        }

        $useStmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
        $useStmt->execute([(int) $resetRow['id']]);
        $invalidateUserTokensStmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $invalidateUserTokensStmt->execute([(int) $resetRow['user_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            send_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        send_json(['ok' => false, 'error' => 'A jelszó visszaállítása sikertelen.'], 500);
    }

    send_json(['ok' => true, 'message' => 'A jelszó sikeresen frissítve.']);
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
