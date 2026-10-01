<?php
declare(strict_types=1);

/*
 * TOTP (RFC 6238) kétlépcsős azonosítás, egyszer használatos recovery kódok
 * és bejelentkezési kockázati események.
 */

const TB_TOTP_PERIOD = 30;
const TB_TOTP_DIGITS = 6;
const TB_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function tb_base32_encode(string $binary): string
{
    $bits = '';
    foreach (str_split($binary) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $output .= TB_BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $output;
}

function tb_base32_decode(string $secret): string
{
    $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    foreach (str_split($secret) as $char) {
        $val = strpos(TB_BASE32_ALPHABET, $char);
        if ($val === false) {
            continue;
        }
        $buffer = (($buffer << 5) | $val) & 0xFFFFFF;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $output;
}

function tb_totp_generate_secret(): string
{
    return tb_base32_encode(random_bytes(20));
}

function tb_totp_code(string $secretBase32, int $timeStep): string
{
    $key = tb_base32_decode($secretBase32);
    $counter = pack('N2', ($timeStep >> 32) & 0xFFFFFFFF, $timeStep & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($binary % (10 ** TB_TOTP_DIGITS)), TB_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Ellenőrzi a kódot ±$window időablakban. Visszatérési érték: az elfogadott time step, vagy null.
 * A $lastUsedStep-nél nem nagyobb lépést nem fogad el (replay védelem).
 */
function tb_totp_verify(string $secretBase32, string $code, ?int $lastUsedStep = null, int $window = 1, ?int $now = null): ?int
{
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== TB_TOTP_DIGITS || tb_base32_decode($secretBase32) === '') {
        return null;
    }
    $current = intdiv($now ?? time(), TB_TOTP_PERIOD);
    for ($offset = -$window; $offset <= $window; $offset++) {
        $step = $current + $offset;
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }
        if (hash_equals(tb_totp_code($secretBase32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function tb_totp_uri(string $secret, string $accountLabel, string $issuer = 'TamasBau'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountLabel)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . TB_TOTP_DIGITS . '&period=' . TB_TOTP_PERIOD;
}

function tb_recovery_code_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function tb_recovery_code_hash(int $userId, string $code): string
{
    return hash_hmac('sha256', $userId . '|' . tb_recovery_code_normalize($code), tb_app_key());
}

/**
 * Új recovery kódok kiadása (a régiek törlődnek). Csak a hash kerül adatbázisba.
 */
function tb_issue_recovery_codes(int $userId, int $count = 8): array
{
    $pdo = db();
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash, is_used) VALUES (?, ?, 0)');
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));
        $display = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        $insert->execute([$userId, tb_recovery_code_hash($userId, $raw)]);
        $codes[] = $display;
    }
    return $codes;
}

/**
 * Recovery kód felhasználása (egyszer használatos). A régi, sima sha256 hash-t is elfogadja.
 */
function tb_consume_recovery_code(int $userId, string $code): bool
{
    $normalized = tb_recovery_code_normalize($code);
    if (strlen($normalized) < 8) {
        return false;
    }
    $stmt = db()->prepare('UPDATE user_recovery_codes SET is_used = 1, used_at = NOW() WHERE user_id = ? AND is_used = 0 AND code_hash IN (?, ?) LIMIT 1');
    $stmt->execute([$userId, tb_recovery_code_hash($userId, $normalized), hash('sha256', $normalized)]);
    return $stmt->rowCount() > 0;
}

function tb_totp_settings(int $userId): ?array
{
    $stmt = db()->prepare('SELECT user_id, secret_encrypted, secret_hint, is_enabled, enabled_at, last_verified_at, last_used_step FROM user_totp_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * TOTP kód ellenőrzése a tárolt titokkal, sikeres ellenőrzéskor a time step mentése (replay védelem).
 */
function tb_verify_user_totp(int $userId, string $code, bool $requireEnabled = true): bool
{
    $settings = tb_totp_settings($userId);
    if (!$settings || ($requireEnabled && (int) $settings['is_enabled'] !== 1)) {
        return false;
    }
    $secret = tb_secret_decrypt((string) $settings['secret_encrypted']);
    if ($secret === null || $secret === '') {
        return false;
    }
    $lastStep = $settings['last_used_step'] !== null ? (int) $settings['last_used_step'] : null;
    $step = tb_totp_verify($secret, $code, $lastStep);
    if ($step === null) {
        return false;
    }
    $update = db()->prepare('UPDATE user_totp_settings SET last_used_step = ?, last_verified_at = NOW() WHERE user_id = ? AND (last_used_step IS NULL OR last_used_step < ?)');
    $update->execute([$step, $userId, $step]);
    return $update->rowCount() > 0;
}

/**
 * Bejelentkezési kockázat becslése: sok sikertelen próbálkozás vagy új eszköz/IP.
 */
function tb_login_risk_level(?int $userId, string $email, string $ipHash, bool $success): string
{
    try {
        $failures = db()->prepare('SELECT COUNT(*) AS c FROM user_login_events WHERE email_attempt = ? AND is_success = 0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)');
        $failures->execute([$email]);
        $failureCount = (int) ($failures->fetch()['c'] ?? 0);
        if ($failureCount >= 5) {
            return 'high';
        }
        if ($success && $userId !== null) {
            $known = db()->prepare('SELECT COUNT(*) AS c FROM user_login_events WHERE user_id = ? AND is_success = 1 AND ip_hash = ?');
            $known->execute([$userId, $ipHash]);
            $any = db()->prepare('SELECT COUNT(*) AS c FROM user_login_events WHERE user_id = ? AND is_success = 1');
            $any->execute([$userId]);
            if ((int) ($any->fetch()['c'] ?? 0) > 0 && (int) ($known->fetch()['c'] ?? 0) === 0) {
                return 'medium';
            }
        }
        if ($failureCount >= 3) {
            return 'medium';
        }
    } catch (Throwable $e) {
        return 'low';
    }
    return 'low';
}
