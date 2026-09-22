<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function smtp_validate_base_config(array $mailConfig): void
{
    if (($mailConfig['host'] ?? '') === '') {
        throw new InvalidArgumentException('Hiányzik az SMTP host beállítás.');
    }
    if ((int) ($mailConfig['port'] ?? 0) <= 0) {
        throw new InvalidArgumentException('Hiányzik vagy érvénytelen az SMTP port.');
    }
    if (($mailConfig['username'] ?? '') === '') {
        throw new InvalidArgumentException('Hiányzik az SMTP felhasználónév/e-mail beállítás.');
    }
    if (($mailConfig['password'] ?? '') === '') {
        throw new InvalidArgumentException('Hiányzik az SMTP jelszó beállítás.');
    }

    $secure = strtolower((string) ($mailConfig['secure'] ?? ''));
    if (!in_array($secure, ['', 'tls', 'ssl'], true)) {
        throw new InvalidArgumentException('Az SMTP titkosítás értéke csak tls, ssl vagy üres lehet.');
    }
}

function smtp_sanitize_error_message(string $message): string
{
    $mail = app_config()['mail'];
    $sensitiveValues = [
        (string) ($mail['password'] ?? ''),
        (string) ($mail['username'] ?? ''),
        (string) ($mail['from_address'] ?? ''),
    ];

    foreach ($sensitiveValues as $value) {
        if ($value !== '') {
            $message = str_replace($value, '[rejtett]', $message);
        }
    }

    $message = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/u', '[rejtett e-mail]', $message) ?? $message;
    return trim($message);
}

function smtp_build_phpmailer(array $mailConfig): \PHPMailer\PHPMailer\PHPMailer
{
    ensure_phpmailer_available();

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) $mailConfig['host'];
    $mailer->Port = (int) $mailConfig['port'];
    $mailer->Timeout = (int) ($mailConfig['timeout'] ?? 20);
    $mailer->Timelimit = (int) ($mailConfig['timeout'] ?? 20);
    $mailer->SMTPAuth = (bool) ($mailConfig['auth'] ?? true) && ((string) ($mailConfig['username'] ?? '') !== '');
    $mailer->Username = (string) ($mailConfig['username'] ?? '');
    $mailer->Password = (string) ($mailConfig['password'] ?? '');
    $mailer->CharSet = 'UTF-8';
    $mailer->XMailer = 'Tamás Bau SMTP diagnosztika';

    $secure = strtolower((string) ($mailConfig['secure'] ?? ''));
    if ($secure === 'tls') {
        $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->SMTPAutoTLS = true;
    } elseif ($secure === 'ssl') {
        $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mailer->SMTPAutoTLS = false;
    } else {
        $mailer->SMTPSecure = '';
        $mailer->SMTPAutoTLS = false;
    }

    return $mailer;
}

function smtp_check_connection(array $mailConfig): array
{
    $steps = [];
    smtp_validate_base_config($mailConfig);
    $steps[] = 'Konfiguráció ellenőrizve.';

    $mailer = smtp_build_phpmailer($mailConfig);
    $steps[] = 'PHPMailer betöltve.';

    $connected = false;
    try {
        $connected = $mailer->smtpConnect();
        if ($connected !== true) {
            throw new RuntimeException('Az SMTP kapcsolat létrehozása sikertelen.');
        }
        $steps[] = 'SMTP kapcsolat és hitelesítés sikeres.';

        return [
            'success' => true,
            'message' => 'Az SMTP kapcsolat és hitelesítés sikeres.',
            'details' => $steps,
        ];
    } finally {
        if ($connected) {
            $mailer->smtpClose();
        }
    }
}

function smtp_send_test_mail(array $mailConfig, string $recipientEmail): array
{
    smtp_validate_base_config($mailConfig);

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Érvénytelen címzett e-mail cím.');
    }

    if (($mailConfig['from_address'] ?? '') === '' || !filter_var((string) $mailConfig['from_address'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A feladó e-mail cím (MAIL_FROM_ADDRESS) hiányzik vagy érvénytelen.');
    }

    $mailer = smtp_build_phpmailer($mailConfig);
    $mailer->setFrom((string) $mailConfig['from_address'], (string) ($mailConfig['from_name'] ?? 'Tamás Bau Kft.'));
    $mailer->addAddress($recipientEmail, $recipientEmail);
    $mailer->isHTML(true);
    $mailer->Subject = 'Tamás Bau SMTP teszt – sikeres kézbesítés ellenőrzése';
    $mailer->Body = '<p>Ez egy rendszer által küldött SMTP teszt e-mail.</p><p>Ha ezt megkapta, az SMTP küldés működik.</p>';
    $mailer->AltBody = "Ez egy rendszer által küldött SMTP teszt e-mail.\nHa ezt megkapta, az SMTP küldés működik.";
    $mailer->send();

    return [
        'success' => true,
        'message' => 'A próba e-mail sikeresen elküldve.',
        'details' => [
            'SMTP kapcsolat és hitelesítés sikeres.',
            'A teszt e-mail kézbesítésre átadva.',
        ],
        'recipient' => $recipientEmail,
    ];
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$payload = get_json_input();
$action = clean_string($_GET['action'] ?? ($payload['action'] ?? 'status'));

$user = require_admin();
$mailConfig = app_config()['mail'];

if ($method === 'GET') {
    send_json([
        'ok' => true,
        'action' => 'status',
        'config' => smtp_config_summary(),
    ]);
}

if ($method !== 'POST') {
    send_json(['ok' => false, 'error' => 'Nem támogatott HTTP metódus.'], 405);
}

if (!in_array($action, ['check', 'send_test'], true)) {
    send_json(['ok' => false, 'error' => 'Ismeretlen SMTP művelet.'], 422);
}

try {
    if ($action === 'check') {
        $result = smtp_check_connection($mailConfig);
        send_json([
            'ok' => true,
            'action' => 'check',
            'config' => smtp_config_summary(),
            'result' => $result,
            'meta' => ['requested_by' => (int) ($user['id'] ?? 0), 'checked_at' => date('c')],
        ]);
    }

    $recipient = clean_string((string) ($payload['recipient_email'] ?? ''), 190);
    $confirmed = (bool) ($payload['confirm'] ?? false);
    if (!$confirmed) {
        send_json(['ok' => false, 'error' => 'A próba e-mail küldéséhez megerősítés szükséges.'], 422);
    }

    $result = smtp_send_test_mail($mailConfig, $recipient);
    send_json([
        'ok' => true,
        'action' => 'send_test',
        'config' => smtp_config_summary(),
        'result' => $result,
        'meta' => ['requested_by' => (int) ($user['id'] ?? 0), 'checked_at' => date('c')],
    ]);
} catch (Throwable $e) {
    $message = smtp_sanitize_error_message($e->getMessage());
    $fallback = $action === 'send_test'
        ? 'A próba e-mail küldése sikertelen.'
        : 'Az SMTP kapcsolatellenőrzés sikertelen.';

    send_json([
        'ok' => true,
        'action' => $action,
        'config' => smtp_config_summary(),
        'result' => [
            'success' => false,
            'message' => $message !== '' ? $message : $fallback,
            'details' => [$fallback],
        ],
        'meta' => ['checked_at' => date('c')],
    ]);
}
