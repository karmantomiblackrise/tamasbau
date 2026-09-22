<?php
declare(strict_types=1);

load_env_file(dirname(__DIR__) . '/.env');
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function load_env_file(string $path): void
{
    static $loaded = [];
    if (isset($loaded[$path]) || !is_file($path) || !is_readable($path)) {
        return;
    }
    $loaded[$path] = true;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $delimiterPos = strpos($line, '=');
        if ($delimiterPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $delimiterPos));
        $value = trim(substr($line, $delimiterPos + 1));
        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function cpanel_config_fallback(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $cached = [];
    $path = __DIR__ . '/config.local.php';
    if (!is_file($path) || !is_readable($path)) {
        return $cached;
    }

    $data = require $path;
    if (!is_array($data)) {
        return $cached;
    }

    $cached = $data;
    return $cached;
}

function cpanel_config_value(string $key, ?string $default = null): ?string
{
    $config = cpanel_config_fallback();
    if (array_key_exists($key, $config)) {
        $value = $config[$key];
        if ($value === null || $value === '') {
            return $default;
        }
        return is_scalar($value) ? (string) $value : $default;
    }

    if (str_starts_with($key, 'MAIL_')) {
        $mailKey = strtolower(substr($key, 5));
        if (isset($config['mail']) && is_array($config['mail']) && array_key_exists($mailKey, $config['mail'])) {
            $value = $config['mail'][$mailKey];
            if ($value === null || $value === '') {
                return $default;
            }
            return is_scalar($value) ? (string) $value : $default;
        }
    }

    return $default;
}

function env_or_fallback(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        $value = env_value($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    foreach ($keys as $key) {
        $value = cpanel_config_value($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function app_env(): string
{
    return strtolower((string) env_or_fallback(['APP_ENV'], 'production'));
}

function app_is_development(): bool
{
    return app_env() === 'development';
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $mailPort = (int) env_or_fallback(['MAIL_PORT', 'TB_MAIL_PORT'], '587');
    $config = [
        'app_name' => env_or_fallback(['TB_APP_NAME'], 'Tamás Bau Kft.'),
        'app_url' => rtrim((string) env_or_fallback(['TB_APP_URL'], 'http://localhost:8000'), '/'),
        'mail' => [
            'host' => trim((string) env_or_fallback(['MAIL_HOST', 'TB_MAIL_HOST'], '')),
            'port' => $mailPort > 0 ? $mailPort : 587,
            'username' => trim((string) env_or_fallback(['MAIL_USERNAME', 'TB_MAIL_USERNAME'], '')),
            'password' => (string) env_or_fallback(['MAIL_PASSWORD', 'TB_MAIL_PASSWORD'], ''),
            'secure' => strtolower(trim((string) env_or_fallback(['MAIL_ENCRYPTION', 'TB_MAIL_ENCRYPTION'], 'tls'))),
            'from_address' => trim((string) env_or_fallback(['MAIL_FROM_ADDRESS', 'TB_MAIL_FROM_ADDRESS'], '')),
            'from_name' => trim((string) env_or_fallback(['MAIL_FROM_NAME', 'TB_MAIL_FROM_NAME'], env_or_fallback(['TB_APP_NAME'], 'Tamás Bau Kft.'))),
            'auth' => strtolower((string) env_or_fallback(['MAIL_AUTH', 'TB_MAIL_AUTH'], 'true')) !== 'false',
            'timeout' => max(5, (int) env_or_fallback(['MAIL_TIMEOUT', 'TB_MAIL_TIMEOUT'], '20')),
            'ehlo_domain' => trim((string) env_or_fallback(['MAIL_EHLO_DOMAIN', 'TB_MAIL_EHLO_DOMAIN'], parse_url((string) env_or_fallback(['TB_APP_URL'], 'http://localhost'), PHP_URL_HOST) ?: 'localhost')),
            'dev_log' => env_or_fallback(['TB_DEV_MAIL_LOG'], dirname(__DIR__) . '/logs/mail-dev.log'),
        ],
    ];

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('TB_DB_HOST', '127.0.0.1');
    $port = env_value('TB_DB_PORT', '3306');
    $name = env_value('TB_DB_NAME', 'tamasbau');
    $user = env_value('TB_DB_USER', 'root');
    $pass = env_value('TB_DB_PASS', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, (string) $user, (string) $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function send_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function clean_string(?string $value, int $max = 255): string
{
    $value = trim((string) $value);
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }
    return $value;
}

function is_state_changing_method(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function validate_csrf_token(): void
{
    if (!is_state_changing_method()) {
        return;
    }
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || $provided === '' || !hash_equals(csrf_token(), $provided)) {
        send_json(['ok' => false, 'error' => 'Érvénytelen CSRF token.'], 403);
    }
}

function current_user(): ?array
{
    static $cached = null;
    static $cachedUser = null;
    static $cachedForUserId = null;
    $sessionUserId = $_SESSION['user_id'] ?? null;

    if ($cached !== null && $cachedForUserId === $sessionUserId) {
        return $cachedUser;
    }

    if (empty($sessionUserId)) {
        $cached = true;
        $cachedUser = null;
        $cachedForUserId = null;
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, phone, created_at, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$sessionUserId]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        $cached = true;
        $cachedUser = null;
        $cachedForUserId = null;
        return null;
    }

    $user['id'] = (int) $user['id'];
    $user['is_active'] = (int) $user['is_active'];
    $cached = true;
    $cachedUser = $user;
    $cachedForUserId = $sessionUserId;
    return $cachedUser;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        send_json(['ok' => false, 'error' => 'Bejelentkezés szükséges.'], 401);
    }
    validate_csrf_token();
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? 'user') !== 'admin') {
        send_json(['ok' => false, 'error' => 'Nincs jogosultsága ehhez a művelethez.'], 403);
    }
    return $user;
}

function quote_statuses(): array
{
    return ['new', 'in_progress', 'answered', 'closed'];
}

function is_valid_quote_status(string $status): bool
{
    return in_array($status, quote_statuses(), true);
}

function format_html_email(string $text): string
{
    return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

function build_text_body_from_html(string $html): string
{
    $normalized = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
    $normalized = preg_replace('/<\/p>/i', "\n\n", $normalized) ?? $normalized;
    return trim(html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function append_dev_mail_log(array $payload): void
{
    $path = (string) app_config()['mail']['dev_log'];
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $line = sprintf(
        "[%s] to=%s subject=%s body=%s\n",
        date('c'),
        $payload['to'] ?? '',
        $payload['subject'] ?? '',
        str_replace(["\r", "\n"], [' ', ' '], $payload['body'] ?? '')
    );
    @file_put_contents($path, $line, FILE_APPEND);
}

function smtp_mailer_configured(): bool
{
    $mail = app_config()['mail'];
    return $mail['host'] !== '' && $mail['port'] > 0 && $mail['from_address'] !== '';
}

function smtp_config_summary(): array
{
    $mail = app_config()['mail'];
    $secure = strtolower((string) ($mail['secure'] ?? ''));
    $secureLabel = $secure === 'tls' ? 'TLS (STARTTLS)' : ($secure === 'ssl' ? 'SSL/TLS' : 'nincs');

    return [
        'host' => (string) ($mail['host'] ?? ''),
        'port' => (int) ($mail['port'] ?? 0),
        'encryption' => $secureLabel,
        'username' => (string) ($mail['username'] ?? ''),
        'password_status' => ((string) ($mail['password'] ?? '') !== '') ? 'beállítva' : 'nincs beállítva',
        'from_address' => (string) ($mail['from_address'] ?? ''),
        'from_name' => (string) ($mail['from_name'] ?? ''),
        'timeout' => (int) ($mail['timeout'] ?? 20),
    ];
}

function ensure_phpmailer_available(): void
{
    $phpMailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
    if (class_exists($phpMailerClass)) {
        return;
    }

    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
    }
    if (class_exists($phpMailerClass)) {
        return;
    }

    $manualBase = dirname(__DIR__) . '/vendor/PHPMailer/src/';
    $manualFiles = ['Exception.php', 'PHPMailer.php', 'SMTP.php'];
    foreach ($manualFiles as $file) {
        $path = $manualBase . $file;
        if (!is_file($path)) {
            throw new RuntimeException('A PHPMailer nincs telepítve. Telepítse Composerrel, vagy töltse fel a vendor/PHPMailer/src fájlokat cPanel File Managerrel.');
        }
        require_once $path;
    }

    if (!class_exists($phpMailerClass)) {
        throw new RuntimeException('A PHPMailer betöltése sikertelen. Ellenőrizze a vendor/PHPMailer/src fájlszerkezetet.');
    }
}

function mime_header_encode(string $value): string
{
    $sanitized = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($sanitized, 'UTF-8', 'B', "\r\n");
    }
    return $sanitized;
}

function smtp_read_response($socket): string
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtp_expect_response($socket, array $expectedCodes, string $context): string
{
    $response = smtp_read_response($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException($context . ' SMTP hibával leállt: ' . trim($response));
    }
    return $response;
}

function smtp_send_command($socket, string $command, array $expectedCodes, string $context): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect_response($socket, $expectedCodes, $context);
}

function send_mail_via_socket(array $mailConfig, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): void
{
    $transportPrefix = $mailConfig['secure'] === 'ssl' ? 'ssl://' : '';
    $target = $transportPrefix . $mailConfig['host'] . ':' . $mailConfig['port'];
    $socket = @stream_socket_client($target, $errno, $errstr, (float) $mailConfig['timeout'], STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('Nem sikerült kapcsolódni az SMTP szerverhez.');
    }

    stream_set_timeout($socket, (int) $mailConfig['timeout']);

    try {
        smtp_expect_response($socket, [220], 'Kapcsolódás');
        smtp_send_command($socket, 'EHLO ' . $mailConfig['ehlo_domain'], [250], 'EHLO');

        if ($mailConfig['secure'] === 'tls') {
            smtp_send_command($socket, 'STARTTLS', [220], 'STARTTLS');
            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('A TLS titkosítás nem aktiválható az SMTP kapcsolaton.');
            }
            smtp_send_command($socket, 'EHLO ' . $mailConfig['ehlo_domain'], [250], 'EHLO');
        }

        if ($mailConfig['auth'] && $mailConfig['username'] !== '') {
            smtp_send_command($socket, 'AUTH LOGIN', [334], 'SMTP hitelesítés');
            smtp_send_command($socket, base64_encode($mailConfig['username']), [334], 'SMTP felhasználónév');
            smtp_send_command($socket, base64_encode($mailConfig['password']), [235], 'SMTP jelszó');
        }

        smtp_send_command($socket, 'MAIL FROM:<' . $mailConfig['from_address'] . '>', [250], 'MAIL FROM');
        smtp_send_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');
        smtp_send_command($socket, 'DATA', [354], 'DATA');

        $boundary = 'tb-mail-' . bin2hex(random_bytes(12));
        $subjectHeader = mime_header_encode($subject);
        $fromNameHeader = mime_header_encode($mailConfig['from_name']);
        $toNameHeader = mime_header_encode($toName !== '' ? $toName : $toEmail);

        $headers = [
            'MIME-Version: 1.0',
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromNameHeader . ' <' . $mailConfig['from_address'] . '>',
            'To: ' . $toNameHeader . ' <' . $toEmail . '>',
            'Subject: ' . $subjectHeader,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $bodyParts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($textBody)),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($htmlBody)),
            '--' . $boundary . '--',
            '',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $bodyParts);
        $message = str_replace(["\r\n.\r\n", "\n.\n"], ["\r\n..\r\n", "\n..\n"], $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect_response($socket, [250], 'Üzenetküldés');
        smtp_send_command($socket, 'QUIT', [221], 'QUIT');
    } finally {
        fclose($socket);
    }
}

function send_app_mail(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $textBody = null): array
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Érvénytelen e-mail cím.');
    }

    $textBody ??= build_text_body_from_html($htmlBody);
    $mailConfig = app_config()['mail'];

    if (!smtp_mailer_configured()) {
        if (!app_is_development()) {
            throw new RuntimeException('Az e-mail küldés nincs konfigurálva ezen a környezeten.');
        }

        append_dev_mail_log([
            'to' => $toEmail,
            'subject' => $subject,
            'body' => $textBody,
        ]);

        return [
            'mode' => 'dev_log',
            'dev' => [
                'to' => $toEmail,
                'subject' => $subject,
                'log_path' => (string) $mailConfig['dev_log'],
                'body' => $textBody,
            ],
        ];
    }

    $phpMailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
    if (class_exists($phpMailerClass)) {
        $mailer = new $phpMailerClass(true);
        $mailer->isSMTP();
        $mailer->Host = $mailConfig['host'];
        $mailer->Port = (int) $mailConfig['port'];
        $mailer->SMTPAuth = (bool) $mailConfig['auth'] && $mailConfig['username'] !== '';
        $mailer->Username = $mailConfig['username'];
        $mailer->Password = $mailConfig['password'];
        $mailer->Timeout = (int) $mailConfig['timeout'];
        $mailer->CharSet = 'UTF-8';
        if ($mailConfig['secure'] === 'tls') {
            $mailer->SMTPSecure = constant('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS');
        } elseif ($mailConfig['secure'] === 'ssl') {
            $mailer->SMTPSecure = constant('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_SMTPS');
        }
        $mailer->setFrom($mailConfig['from_address'], $mailConfig['from_name']);
        $mailer->addAddress($toEmail, $toName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();

        return ['mode' => 'phpmailer'];
    }

    send_mail_via_socket($mailConfig, $toEmail, $toName, $subject, $htmlBody, $textBody);
    return ['mode' => 'smtp_socket'];
}
