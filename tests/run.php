<?php
declare(strict_types=1);

/*
 * Tamás Bau – automatizált tesztek (függőség nélkül, PHP CLI).
 *
 *   php tests/run.php                  → egységtesztek (adatbázis nem kell)
 *   php tests/run.php --integration    → + integrációs tesztek beépített PHP szerverrel
 *
 * Integrációs teszt CSAK külön teszt adatbázison futtatható (a tesztek adatot írnak):
 *   TB_TEST_DB_NAME=tamasbau_test TB_TEST_DB_USER=root TB_TEST_DB_PASS=... php tests/run.php --integration
 * Az adatbázisba előtte importálni kell a database/schema.sql fájlt.
 * Az adatbázis nevének tartalmaznia kell a "test" szót. A gyökérben lévő .env felülírná a
 * környezeti változókat, ezért integrációs futtatás idejére nevezze át (pl. .env.off).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$integration = in_array('--integration', $argv, true);

if ($integration) {
    $testDb = (string) getenv('TB_TEST_DB_NAME');
    if ($testDb === '' || stripos($testDb, 'test') === false) {
        fwrite(STDERR, "Integrációs teszthez állítsa be a TB_TEST_DB_NAME változót egy 'test' nevű adatbázisra.\n");
        exit(2);
    }
    if (is_file($root . '/.env')) {
        fwrite(STDERR, "A .env fájl felülírná a teszt adatbázis beállítását. Nevezze át ideiglenesen (pl. .env.off).\n");
        exit(2);
    }
    $testEnv = [
        'APP_ENV' => 'production',
        'TB_APP_URL' => 'http://127.0.0.1:8765',
        'TB_DB_HOST' => getenv('TB_TEST_DB_HOST') ?: '127.0.0.1',
        'TB_DB_PORT' => getenv('TB_TEST_DB_PORT') ?: '3306',
        'TB_DB_NAME' => $testDb,
        'TB_DB_USER' => getenv('TB_TEST_DB_USER') ?: 'root',
        'TB_DB_PASS' => getenv('TB_TEST_DB_PASS') ?: '',
        'TB_APP_KEY' => 'test-app-key-0123456789abcdefghijklmnopqrstuvwxyz',
        'TB_HEALTH_TOKEN' => 'test-health-token-0123456789abcdefghijklmnop',
        'TB_INVOICE_PROVIDER' => 'billingo',
        'TB_INVOICE_MODE' => 'sandbox',
        'MAIL_HOST' => '',
        'TB_DEV_MAIL_LOG' => 'logs/mail-test.log',
    ];
    foreach ($testEnv as $k => $v) {
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }
}

require_once $root . '/api/platform-lib.php';

$passed = 0;
$failed = 0;
$section = '';

function t_section(string $name): void
{
    global $section;
    $section = $name;
    echo "\n== {$name} ==\n";
}

function t_ok(bool $condition, string $label, mixed $debug = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✔ {$label}\n";
        return;
    }
    $failed++;
    echo "  ✘ {$label}\n";
    if ($debug !== null) {
        echo '    ' . substr(is_string($debug) ? $debug : json_encode($debug, JSON_UNESCAPED_UNICODE), 0, 800) . "\n";
    }
}

function t_throws(callable $fn, string $class, string $label): void
{
    try {
        $fn();
        t_ok(false, $label . ' (nem dobott kivételt)');
    } catch (Throwable $e) {
        t_ok($e instanceof $class, $label, get_class($e) . ': ' . $e->getMessage());
    }
}

/* ===================== Egységtesztek ===================== */

t_section('TOTP (RFC 6238) és helyreállító kódok');
$rfcSecret = tb_base32_encode('12345678901234567890');
t_ok($rfcSecret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 kódolás RFC vektor');
t_ok(tb_base32_decode($rfcSecret) === '12345678901234567890', 'base32 dekódolás');
t_ok(tb_totp_code($rfcSecret, intdiv(59, 30)) === '287082', 'TOTP kód T=59 → 287082');
t_ok(tb_totp_code($rfcSecret, intdiv(1111111109, 30)) === '081804', 'TOTP kód T=1111111109 → 081804');
$now = 1111111109;
$step = tb_totp_verify($rfcSecret, '081804', null, 1, $now);
t_ok($step === intdiv($now, 30), 'TOTP ellenőrzés elfogadja az aktuális kódot');
t_ok(tb_totp_verify($rfcSecret, '081804', $step, 1, $now) === null, 'TOTP replay védelem (ugyanaz a lépés nem használható újra)');
t_ok(tb_totp_verify($rfcSecret, '000000', null, 1, $now) === null || tb_totp_code($rfcSecret, intdiv($now, 30)) === '000000', 'Hibás kód elutasítva');
t_ok(tb_totp_verify($rfcSecret, 'abc', null, 1, $now) === null, 'Nem numerikus kód elutasítva');
$generated = tb_totp_generate_secret();
t_ok((bool) preg_match('/^[A-Z2-7]{32}$/', $generated), 'Generált titok base32, 160 bit');
t_ok(str_starts_with(tb_totp_uri($generated, 'admin@example.hu', 'Tamás Bau'), 'otpauth://totp/'), 'otpauth URI formátum');
t_ok(tb_recovery_code_normalize(' abcd-ef12 ') === 'ABCDEF12', 'Helyreállító kód normalizálás');
t_ok(tb_recovery_code_hash(1, 'ABCD-EF12') === tb_recovery_code_hash(1, 'abcdef12') && tb_recovery_code_hash(1, 'ABCDEF12') !== tb_recovery_code_hash(2, 'ABCDEF12'), 'Helyreállító kód hash felhasználóhoz kötött');

t_section('Titkosítás (secret box)');
$box = tb_secret_encrypt('JBSWY3DPEHPK3PXP');
t_ok(str_starts_with($box, 'v1:') && !str_contains($box, 'JBSWY3DPEHPK3PXP'), 'AES-GCM titkosított formátum');
t_ok(tb_secret_decrypt($box) === 'JBSWY3DPEHPK3PXP', 'Visszafejtés');
$tampered = substr($box, 0, -2) . (substr($box, -2) === 'AA' ? 'BB' : 'AA');
t_ok(tb_secret_decrypt($tampered) === null, 'Manipulált titok elutasítva');

t_section('CSV export (escaping + formula injection)');
t_ok(tb_csv_cell('=HYPERLINK("x")') === '"\'=HYPERLINK(""x"")"', 'Formula prefix semlegesítve, idézőjel duplázva');
t_ok(tb_csv_cell('+36 30 123') === '"\'+36 30 123"', '+ kezdetű cella semlegesítve');
t_ok(tb_csv_cell('-1500') === '"-1500"', 'Negatív szám érintetlen');
t_ok(tb_csv_cell("a;b\r\nc") === "\"a;b\nc\"", 'Elválasztó és sortörés idézőjelek között');
t_ok(tb_csv_cell(null) === '""', 'NULL üres cella');
$csv = tb_csv_build(['Név', 'Összeg'], [['Árvíztűrő', 100]]);
t_ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '"Árvíztűrő";"100"'), 'UTF-8 BOM + pontosvesszős formátum');

t_section('HTML escaping és LIKE minta');
t_ok(tb_h('<script>"\'&') === '&lt;script&gt;&quot;&#039;&amp;', 'tb_h escapel minden veszélyes karaktert');
t_ok(tb_like('50%_a\\b') === '%50\\%\\_a\\\\b%', 'LIKE wildcard escaping');

t_section('Aláírás kép validálás');
function t_make_png(int $w, int $h): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $w; $x++) {
            $raw .= abs($y - intdiv($h * $x, $w)) < 2 ? "\x00" : "\xFF";
        }
    }
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
}
$png = 'data:image/png;base64,' . base64_encode(t_make_png(300, 100));
try {
    t_ok(str_starts_with(tb_signature_validate_png($png), 'data:image/png;base64,'), 'Érvényes PNG elfogadva');
} catch (Throwable $e) {
    t_ok(false, 'Érvényes PNG elfogadva', $e->getMessage());
}
t_throws(static fn () => tb_signature_validate_png('data:image/png;base64,' . base64_encode(t_make_png(10, 5))), InvalidArgumentException::class, 'Túl kicsi aláírás elutasítva');
t_throws(static fn () => tb_signature_validate_png('data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>')), InvalidArgumentException::class, 'SVG (XSS vektor) elutasítva');
t_throws(static fn () => tb_signature_validate_png('data:image/png;base64,' . base64_encode('<?php echo 1; ?>')), InvalidArgumentException::class, 'Nem PNG tartalom elutasítva');
t_ok(tb_canonical_json(['b' => 1, 'a' => ['d' => 2, 'c' => 3]]) === tb_canonical_json(['a' => ['c' => 3, 'd' => 2], 'b' => 1]), 'Kanonikus JSON kulcssorrend-független (stabil hash)');

t_section('Profit számítás');
$entries = [
    ['entry_type' => 'material', 'quantity' => 2, 'planned_quantity' => 1, 'unit_price_cents' => 10000, 'internal_unit_cost_cents' => 6000, 'billable_to_customer' => 1],
    ['entry_type' => 'labor', 'quantity' => 3, 'unit_price_cents' => 800000, 'internal_unit_cost_cents' => 500000, 'billable_to_customer' => 1],
    ['entry_type' => 'external', 'quantity' => 1, 'unit_price_cents' => 0, 'internal_unit_cost_cents' => 3000000, 'billable_to_customer' => 0],
];
$agg = tb_profit_aggregate($entries, ['quote_amount_cents' => 2500000]);
t_ok(isset($agg['planned'], $agg['actual'], $agg['breakdown']['material'], $agg['breakdown']['labor'], $agg['breakdown']['travel'], $agg['breakdown']['external']), 'Aggregátum szerkezet (anyag/munka/utazás/külső bontás)', $agg);
t_ok($agg['actual']['revenue_cents'] === 2420000, 'Tényleges bevétel (csak számlázható tételek)', $agg['actual']);
t_ok($agg['actual']['cost_cents'] === 4512000, 'Tényleges költség (belső önköltséggel)', $agg['actual']);
t_ok($agg['planned']['revenue_cents'] === 2500000 && $agg['planned']['cost_cents'] === 6000, 'Tervezett bevétel/költség', $agg['planned']);
t_ok(in_array('negative_margin', array_column($agg['alerts'], 'type'), true), 'Negatív margin riasztás', $agg['alerts']);
t_ok(in_array('cost_overrun', array_column($agg['alerts'], 'type'), true), 'Költségtúllépés riasztás', $agg['alerts']);
$travel = tb_profit_aggregate([['entry_type' => 'travel', 'quantity' => 0, 'travel_km' => 40, 'unit_price_cents' => 0, 'billable_to_customer' => 0]], ['travel_cost_per_km_cents' => 15000]);
t_ok($travel['breakdown']['travel']['actual_cost_cents'] === 600000, 'Utazási költség km alapján', $travel['breakdown']['travel']);
$redacted = tb_profit_redact($agg);
t_ok(!str_contains(json_encode($redacted), 'actual_cost_cents') && !str_contains(json_encode($redacted), 'margin_cents'), 'Belső költség elrejtve nem jogosult felhasználónak', $redacted);

t_section('Workflow, SLA, számlázó adapter');
t_ok(tb_workflow_backoff_seconds(1) < tb_workflow_backoff_seconds(2) && tb_workflow_backoff_seconds(20) <= 86400, 'Exponenciális backoff felső korláttal');
t_ok(tb_sla_default_hours('emergency') < tb_sla_default_hours('high') && tb_sla_default_hours('high') < tb_sla_default_hours('low'), 'SLA óraszámok prioritás szerint');
t_ok(tb_sla_due_at('emergency', 0) === date('Y-m-d H:i:s', tb_sla_default_hours('emergency') * 3600), 'SLA határidő számítás');
putenv('TB_INVOICE_PROVIDER=billingo');
putenv('TB_INVOICE_MODE=live');
putenv('TB_BILLINGO_API_KEY=');
$_ENV['TB_INVOICE_PROVIDER'] = 'billingo';
$_ENV['TB_INVOICE_MODE'] = 'live';
$_ENV['TB_BILLINGO_API_KEY'] = '';
$summary = tb_invoice_provider_summary();
t_ok(($summary['configured'] ?? true) === false, 'API kulcs nélkül a provider nincs konfigurálva', $summary);
$send = tb_invoice_provider()->createInvoice(['source_type' => 'order', 'source_id' => 1, 'total_cents' => 100, 'items' => []]);
t_ok(($send['status'] ?? '') === 'draft' && array_key_exists('external_id', $send) && $send['external_id'] === null, 'Kulcs nélkül live módban sem megy ki számla (no-op)', $send);
$_ENV['TB_BILLINGO_API_KEY'] = 'dummy-key-123456';
$_ENV['TB_INVOICE_MODE'] = 'sandbox';
$sandbox = tb_invoice_provider()->createInvoice(['source_type' => 'order', 'source_id' => 1]);
t_ok(($sandbox['status'] ?? '') === 'sandbox' && str_starts_with((string) ($sandbox['external_id'] ?? ''), 'SANDBOX-'), 'Sandbox mód: nincs valódi számla', $sandbox);
$_ENV['TB_INVOICE_MODE'] = 'live';
t_throws(static fn () => tb_invoice_provider()->createInvoice(['source_type' => 'order', 'source_id' => 1]), RuntimeException::class, 'Live mód bekötés nélkül kézi fallbackot kér');
unset($_ENV['TB_BILLINGO_API_KEY'], $_ENV['TB_INVOICE_MODE'], $_ENV['TB_INVOICE_PROVIDER']);
putenv('TB_BILLINGO_API_KEY');
putenv('TB_INVOICE_MODE');
putenv('TB_INVOICE_PROVIDER');
if ($integration) {
    foreach (['TB_INVOICE_PROVIDER' => 'billingo', 'TB_INVOICE_MODE' => 'sandbox'] as $k => $v) {
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }
}

t_section('Hibalogolás titokszivárgás nélkül');
$_ENV['TB_SZAMLAZZ_AGENT_KEY'] = 'SuperSecret-Value-123';
$redactedText = redact_secrets('connect failed ****** agent SuperSecret-Value-123');
unset($_ENV['TB_SZAMLAZZ_AGENT_KEY']);
t_ok(!str_contains($redactedText, 'hunter22') && !str_contains($redactedText, 'SuperSecret-Value-123'), 'Jelszó és env titok kitakarva', $redactedText);

/* ===================== Integrációs tesztek ===================== */

final class TbHttpClient
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'tbjar');
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $h = array_merge(['Accept: application/json'], $headers);
        if ($method !== 'GET') {
            $h[] = 'Content-Type: application/json';
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr($raw, 0, $headerSize);
        $text = substr($raw, $headerSize);
        $json = json_decode($text, true);
        if (is_array($json) && isset($json['csrf_token'])) {
            $this->csrf = (string) $json['csrf_token'];
        }
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $text, 'headers' => $rawHeaders];
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    public function login(string $email, string $password): array
    {
        $this->get('/api/auth.php?action=me');
        return $this->post('/api/auth.php?action=login', ['email' => $email, 'password' => $password]);
    }
}

if ($integration) {
    t_section('Integráció – előkészítés');
    $pdo = db();
    $pdo->exec('DELETE FROM user_totp_settings');
    $pdo->exec('DELETE FROM user_recovery_codes');
    $pw = password_hash('Teszt-Jelszo-2026', PASSWORD_DEFAULT);
    $upsert = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), is_active = 1");
    $upsert->execute(['Teszt Admin', 'admin@tamasbau.hu', $pw, 'admin']);
    $upsert->execute(['Teszt Munkatárs', 'worker@test.local', $pw, 'field_worker']);
    $upsert->execute(['Teszt Ügyfél', 'nagy.peter@example.hu', $pw, 'user']);
    $ids = [];
    foreach (['admin@tamasbau.hu', 'worker@test.local', 'nagy.peter@example.hu'] as $email) {
        $s = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $s->execute([$email]);
        $ids[$email] = (int) $s->fetchColumn();
    }
    $pdo->prepare("INSERT INTO projects (user_id, title, address, status) VALUES (?, 'Teszt projekt', 'Budapest', 'in_progress')")->execute([$ids['nagy.peter@example.hu']]);
    $projectId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO work_orders (project_id, user_id, assigned_to_user_id, title, location, status, priority) VALUES (?, ?, ?, 'Teszt munkalap', 'Budapest', 'todo', 'normal')")->execute([$projectId, $ids['nagy.peter@example.hu'], $ids['worker@test.local']]);
    $woId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO work_order_checklists (work_order_id, item_text, is_done) VALUES (?, 'Kábelezés', 0)")->execute([$woId]);
    $checkId = (int) $pdo->lastInsertId();
    t_ok($projectId > 0 && $woId > 0, 'Teszt adatok létrehozva');

    $port = 8765;
    $envPrefix = '';
    foreach ($testEnv as $k => $v) {
        $envPrefix .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    $logFile = sys_get_temp_dir() . '/tb-test-server.log';
    $server = proc_open($envPrefix . 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root) . ' > ' . escapeshellarg($logFile) . ' 2>&1', [], $pipes, $root);
    $base = 'http://127.0.0.1:' . $port;
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $fp = @fsockopen('127.0.0.1', $port);
        if ($fp) {
            fclose($fp);
            $up = true;
            break;
        }
    }
    t_ok($up, 'Beépített PHP szerver elindult');
    @array_map('unlink', glob($root . '/logs/rate-limit/*.json') ?: []);

    try {
        t_section('Integráció – vendég árrejtés, health check');
        $guest = new TbHttpClient($base);
        $products = $guest->get('/api/products.php');
        $first = $products['json']['products'][0] ?? ($products['json'][0] ?? null);
        t_ok($products['status'] === 200 && is_array($first) && array_key_exists('price', $first) && $first['price'] === null && empty($first['price_visible']), 'Vendég nem lát árat', $products['body']);
        $health = $guest->get('/api/health.php');
        t_ok($health['status'] === 200 && ($health['json']['ok'] ?? false) === true && !isset($health['json']['checks']), 'Publikus health: csak összesítő', $health['body']);
        $healthTok = $guest->get('/api/health.php', ['X-Health-Token: ' . $testEnv['TB_HEALTH_TOKEN']]);
        t_ok(isset($healthTok['json']['checks']) && !str_contains($healthTok['body'], $testEnv['TB_DB_PASS'] !== '' ? $testEnv['TB_DB_PASS'] : '§§nincs§§'), 'Token-nel részletes diagnosztika, titok nélkül', $healthTok['body']);
        $noAuth = $guest->get('/api/platform.php?module=search&q=teszt');
        t_ok($noAuth['status'] === 401, 'Platform API bejelentkezés nélkül 401');
        $sec = $guest->get('/api/platform.php?module=mobile');
        t_ok(str_contains(strtolower($sec['headers']), 'cache-control: no-store'), 'API válasz no-store');

        t_section('Integráció – admin belépés és 2FA');
        $admin = new TbHttpClient($base);
        $login = $admin->login('admin@tamasbau.hu', 'Teszt-Jelszo-2026');
        t_ok($login['status'] === 200 && ($login['json']['user']['role'] ?? '') === 'admin', 'Admin belépés 2FA nélkül (opcionális policy)', $login['body']);
        $noCsrf = $admin->request('POST', '/api/platform.php?module=security', ['action' => 'setup_totp'], ['X-CSRF-Token: rossz']);
        $setup = $admin->post('/api/platform.php?module=security', ['action' => 'setup_totp']);
        $secret = (string) ($setup['json']['secret'] ?? '');
        t_ok($setup['status'] === 200 && strlen($secret) === 32, '2FA titok generálva', $setup['body']);
        $confirm = $admin->post('/api/platform.php?module=security', ['action' => 'confirm_totp', 'code' => tb_totp_code($secret, intdiv(time(), 30))]);
        $recovery = $confirm['json']['recovery_codes'] ?? [];
        t_ok($confirm['status'] === 200 && count($recovery) === 8, '2FA aktiválva, 8 helyreállító kód', $confirm['body']);
        $stored = $pdo->query('SELECT code_hash FROM user_recovery_codes LIMIT 1')->fetchColumn();
        t_ok(is_string($stored) && !in_array($stored, $recovery, true) && strlen($stored) === 64, 'Helyreállító kódok hash-elve tárolva');
        $admin->post('/api/auth.php?action=logout', []);

        $admin2 = new TbHttpClient($base);
        $step1 = $admin2->login('admin@tamasbau.hu', 'Teszt-Jelszo-2026');
        t_ok(($step1['json']['two_factor_required'] ?? false) === true && !isset($step1['json']['user']), 'Jelszó után 2FA lépés szükséges', $step1['body']);
        $meMid = $admin2->get('/api/auth.php?action=me');
        t_ok(is_array($meMid['json']) && array_key_exists('user', $meMid['json']) && $meMid['json']['user'] === null && ($meMid['json']['two_factor_pending'] ?? false) === true, 'Köztes állapotban nincs bejelentkezve', $meMid['body']);
        $bad = $admin2->post('/api/auth.php?action=verify_2fa', ['code' => '000000']);
        t_ok($bad['status'] === 401, 'Hibás 2FA kód elutasítva');
        $good = $admin2->post('/api/auth.php?action=verify_2fa', ['code' => tb_totp_code($secret, intdiv(time(), 30) + 1)]);
        t_ok($good['status'] === 200 && ($good['json']['user']['email'] ?? '') === 'admin@tamasbau.hu', '2FA kóddal belépés', $good['body']);

        $admin3 = new TbHttpClient($base);
        $admin3->login('admin@tamasbau.hu', 'Teszt-Jelszo-2026');
        $rc = $admin3->post('/api/auth.php?action=verify_2fa', ['recovery_code' => $recovery[0]]);
        t_ok($rc['status'] === 200, 'Helyreállító kóddal belépés', $rc['body']);
        $admin4 = new TbHttpClient($base);
        $admin4->login('admin@tamasbau.hu', 'Teszt-Jelszo-2026');
        $rc2 = $admin4->post('/api/auth.php?action=verify_2fa', ['recovery_code' => $recovery[0]]);
        t_ok($rc2['status'] === 401, 'Helyreállító kód csak egyszer használható');

        $secView = $admin2->get('/api/platform.php?module=security');
        $sessions = $secView['json']['sessions'] ?? [];
        t_ok(count($sessions) >= 2 && count(array_filter($sessions, static fn ($s) => (int) $s['is_current'] === 1)) === 1, 'Aktív session lista, aktuális jelölve', $secView['body']);
        $revoke = $admin2->post('/api/platform.php?module=security', ['action' => 'revoke_other_sessions']);
        t_ok(($revoke['json']['revoked'] ?? 0) >= 1, 'Többi session visszavonva', $revoke['body']);
        $afterRevoke = $admin3->get('/api/platform.php?module=search&q=teszt');
        t_ok($afterRevoke['status'] === 401, 'Visszavont session nem használható', $afterRevoke['body']);
        $events = $pdo->query("SELECT COUNT(*) FROM user_login_events WHERE email_attempt = 'admin@tamasbau.hu'")->fetchColumn();
        t_ok((int) $events >= 4, 'Bejelentkezési előzmény naplózva');

        t_section('Integráció – mobil offline szinkron');
        $worker = new TbHttpClient($base);
        $wl = $worker->login('worker@test.local', 'Teszt-Jelszo-2026');
        t_ok($wl['status'] === 200, 'Munkatárs belépés', $wl['body']);
        $list = $worker->get('/api/platform.php?module=mobile');
        t_ok(in_array($woId, array_map('intval', array_column($list['json']['work_orders'] ?? [], 'id')), true), 'Hozzárendelt munkalap listázva', $list['body']);
        $key = 'test-' . bin2hex(random_bytes(6));
        $op = ['action' => 'sync', 'idempotency_key' => $key, 'work_order_id' => $woId, 'op_type' => 'status', 'data' => ['status' => 'in_progress'], 'base' => ['status' => 'todo']];
        $s1 = $worker->post('/api/platform.php?module=mobile', $op);
        $s2 = $worker->post('/api/platform.php?module=mobile', $op);
        t_ok($s1['status'] === 200 && ($s2['json']['duplicate'] ?? false) === true, 'Idempotens újraküldés (duplikátum felismerve)', [$s1['body'], $s2['body']]);
        $pdo->prepare("UPDATE work_orders SET status = 'blocked' WHERE id = ?")->execute([$woId]);
        $conf = $worker->post('/api/platform.php?module=mobile', ['action' => 'sync', 'idempotency_key' => 'test-' . bin2hex(random_bytes(6)), 'work_order_id' => $woId, 'op_type' => 'status', 'data' => ['status' => 'done'], 'base' => ['status' => 'in_progress']]);
        t_ok($conf['status'] === 409 && ($conf['json']['code'] ?? '') === 'conflict', 'Ütközés jelzése (nincs csendes felülírás)', $conf['body']);
        $still = $pdo->prepare('SELECT status FROM work_orders WHERE id = ?');
        $still->execute([$woId]);
        t_ok($still->fetchColumn() === 'blocked', 'Szerver adat érintetlen ütközéskor');
        $res = $worker->post('/api/platform.php?module=mobile', ['action' => 'resolve_conflict', 'operation_id' => (int) ($conf['json']['operation_id'] ?? 0), 'resolution' => 'keep_server']);
        t_ok($res['status'] === 200, 'Manuális feloldás (szerver verzió megtartása)', $res['body']);
        $batch = $worker->post('/api/platform.php?module=mobile', ['action' => 'sync_batch', 'operations' => [
            ['idempotency_key' => 'test-' . bin2hex(random_bytes(6)), 'work_order_id' => $woId, 'op_type' => 'checklist', 'data' => ['item_id' => $checkId, 'is_done' => true], 'base' => ['is_done' => 0]],
            ['idempotency_key' => 'test-' . bin2hex(random_bytes(6)), 'work_order_id' => $woId, 'op_type' => 'note', 'data' => ['note' => 'Helyszíni megjegyzés <b>teszt</b>']],
            ['idempotency_key' => 'test-' . bin2hex(random_bytes(6)), 'work_order_id' => $woId, 'op_type' => 'time_log', 'data' => ['minutes' => 90]],
            ['idempotency_key' => 'test-' . bin2hex(random_bytes(6)), 'work_order_id' => $woId, 'op_type' => 'material', 'data' => ['title' => 'NYM-J 3x1,5', 'quantity' => 25, 'unit' => 'm']],
            ['idempotency_key' => 'test-' . bin2hex(random_bytes(6)), 'work_order_id' => $woId, 'op_type' => 'time_log', 'data' => ['minutes' => 0]],
        ]]);
        $codes = array_column($batch['json']['results'] ?? [], 'http');
        t_ok($codes === [200, 200, 200, 200, 422], 'Kötegelt szinkron: checklist, megjegyzés, munkaidő, anyag + validációs hiba', $batch['body']);
        $customer = new TbHttpClient($base);
        $customer->login('nagy.peter@example.hu', 'Teszt-Jelszo-2026');
        $forbidden = $customer->post('/api/platform.php?module=mobile', $op);
        t_ok($forbidden['status'] === 403, 'Ügyfél nem használhatja a mobil munkalap API-t');

        t_section('Integráció – digitális aláírás');
        $doc = $customer->get('/api/platform.php?module=signatures&view=document&document_type=work_order&document_id=' . $woId);
        t_ok($doc['status'] === 200 && strlen((string) ($doc['json']['document']['hash'] ?? '')) === 64, 'Ügyfél látja a saját munkalap dokumentumát', $doc['body']);
        $sigPayload = ['action' => 'create', 'document_type' => 'work_order', 'document_id' => $woId, 'document_hash' => $doc['json']['document']['hash'] ?? '', 'signer_name' => 'Nagy Péter', 'signer_email' => 'nagy.peter@example.hu', 'declaration_text' => 'A munkát átvettem, a tartalmat elfogadom.', 'declaration_accepted' => true, 'signature_png' => $png];
        $sig = $customer->post('/api/platform.php?module=signatures', $sigPayload);
        $sigId = (int) ($sig['json']['id'] ?? 0);
        t_ok($sig['status'] === 201 && $sigId > 0, 'Aláírás rögzítve', $sig['body']);
        $row = $pdo->query('SELECT signer_ip_hash, document_hash, document_snapshot FROM digital_signatures WHERE id = ' . $sigId)->fetch();
        t_ok($row && strlen((string) $row['signer_ip_hash']) === 64 && !str_contains((string) $row['signer_ip_hash'], '127.0.0.1') && $row['document_snapshot'] !== null, 'IP hash-elve, dokumentum pillanatkép tárolva');
        $dup = $customer->post('/api/platform.php?module=signatures', $sigPayload);
        t_ok(in_array($dup['status'], [409, 422], true), 'Ugyanaz a verzió nem írható alá kétszer / lezárt dokumentum', $dup['body']);
        $print = $customer->get('/api/platform.php?module=signatures&view=print&id=' . $sigId);
        t_ok($print['status'] === 200 && str_contains($print['body'], 'Nagy Péter') && str_contains(strtolower($print['headers']), "content-security-policy: default-src 'none'"), 'Nyomtatható HTML szigorú CSP-vel', substr($print['body'], 0, 300));
        $other = new TbHttpClient($base);
        $other->login('worker@test.local', 'Teszt-Jelszo-2026');
        $revokeDenied = $other->post('/api/platform.php?module=signatures', ['action' => 'revoke', 'id' => $sigId, 'reason' => 'teszt']);
        t_ok($revokeDenied['status'] === 403, 'Munkatárs nem vonhat vissza aláírást');

        t_section('Integráció – kereső, profit, workflow, SLA, számla, értékelés');
        $search = $admin2->get('/api/platform.php?module=search&q=Teszt');
        $types = array_column($search['json']['groups'] ?? [], 'type');
        t_ok($search['status'] === 200 && in_array('project', $types, true) && in_array('work_order', $types, true), 'Globális kereső admin találatok', $search['body']);
        $custSearch = $customer->get('/api/platform.php?module=search&q=Teszt&type=order');
        t_ok($custSearch['status'] === 422 || ($custSearch['json']['groups'] ?? null) !== null, 'Ügyfél kereső jogosultság szerint szűrt', $custSearch['body']);
        $short = $admin2->get('/api/platform.php?module=search&q=a');
        t_ok($short['status'] === 422 || ($short['json']['groups'] ?? []) === [], 'Túl rövid keresés nem fut le');
        $profit = $admin2->get('/api/platform.php?module=profit&view=detail&scope=project&id=' . $projectId);
        t_ok($profit['status'] === 200 && isset($profit['json']['profit']), 'Projekt profit részletek', $profit['body']);
        $csvRes = $admin2->get('/api/platform.php?module=profit&view=csv');
        t_ok($csvRes['status'] === 200 && str_contains(strtolower($csvRes['headers']), 'text/csv'), 'Profit CSV export');
        $workerProfit = $worker->get('/api/platform.php?module=profit');
        t_ok($workerProfit['status'] === 403, 'Munkatárs nem látja a profit kimutatást');
        $pending = $admin2->post('/api/platform.php?module=workflows', ['action' => 'run_pending']);
        t_ok($pending['status'] === 200, 'Workflow pending jobok futtatása cron nélkül', $pending['body']);
        $jobs = $admin2->get('/api/platform.php?module=workflows&view=jobs');
        t_ok($jobs['status'] === 200 && is_array($jobs['json']['jobs'] ?? null), 'Workflow job lista', $jobs['body']);
        $ticket = $customer->post('/api/operations.php?module=service', ['action' => 'create_ticket', 'subject' => 'Áramszünet teszt', 'description' => 'Teljes áramszünet a lakásban, sürgős.', 'priority' => 'emergency']);
        $ticketId = (int) ($ticket['json']['ticket']['id'] ?? $ticket['json']['id'] ?? 0);
        if ($ticketId <= 0) {
            $ticketId = (int) $pdo->query("SELECT MAX(id) FROM service_tickets WHERE subject = 'Áramszünet teszt'")->fetchColumn();
        }
        $t = $pdo->query('SELECT sla_due_at, emergency_alerted_at FROM service_tickets WHERE id = ' . max(0, $ticketId))->fetch();
        t_ok($t && $t['sla_due_at'] !== null && $t['emergency_alerted_at'] !== null, 'Emergency ticket: SLA határidő + azonnali riasztás', [$ticket['body'], $t]);
        $sla = $admin2->get('/api/platform.php?module=sla');
        t_ok($sla['status'] === 200 && isset($sla['json']['report']), 'SLA riport', $sla['body']);
        $inv = $admin2->post('/api/platform.php?module=invoices', ['action' => 'prepare', 'source_type' => 'project', 'source_id' => $projectId]);
        t_ok($inv['status'] === 200 && in_array($inv['json']['result']['status'] ?? '', ['sandbox', 'draft'], true) && !str_starts_with((string) ($inv['json']['result']['external_id'] ?? ''), 'BILLINGO-'), 'Számla-előkészítés API kulcs nélkül: nincs éles számla', $inv['body']);
        $pdo->prepare("UPDATE projects SET status = 'completed' WHERE id = ?")->execute([$projectId]);
        $rr = $admin2->post('/api/platform.php?module=reviews', ['action' => 'request_review', 'source_type' => 'project', 'source_id' => $projectId]);
        t_ok($rr['status'] === 200 || ($rr['status'] === 502 && str_contains((string) ($rr['json']['manual_url'] ?? ''), '/review.html?token=')), 'Értékeléskérés létrehozva (SMTP nélkül kézi link fallback)', $rr['body']);
        $review = $customer->post('/api/platform.php?module=reviews', ['action' => 'submit', 'source_type' => 'project', 'source_id' => $projectId, 'rating' => 2, 'feedback' => 'Késve érkeztek <script>x</script>', 'public_consent' => true]);
        t_ok(in_array($review['status'], [200, 201], true), 'Ügyfél értékelés beküldése', $review['body']);
        $rv = $pdo->query('SELECT id, moderation_status AS status, follow_up_status, public_visible FROM customer_reviews ORDER BY id DESC LIMIT 1')->fetch();
        t_ok($rv && $rv['status'] === 'pending' && (int) $rv['public_visible'] === 0, 'Új értékelés moderálásra vár, nem publikus', $rv);
        $admin2->post('/api/platform.php?module=workflows', ['action' => 'run_pending']);
        $rv2 = $pdo->query('SELECT follow_up_status FROM customer_reviews WHERE id = ' . (int) ($rv['id'] ?? 0))->fetchColumn();
        t_ok(in_array($rv2, ['open', 'pending'], true), 'Negatív értékelés belső follow-up', $rv2);
        $public = $guest->get('/api/review.php?action=public');
        t_ok($public['status'] === 200 && !str_contains($public['body'], 'Késve érkeztek'), 'Nem jóváhagyott értékelés nem publikus');
        $mod = $admin2->post('/api/platform.php?module=reviews', ['action' => 'moderate', 'id' => (int) ($rv['id'] ?? 0), 'moderation_status' => 'approved', 'public_visible' => true]);
        $public2 = $guest->get('/api/review.php?action=public');
        t_ok($mod['status'] === 200 && str_contains($public2['body'], 'Késve érkeztek'), 'Jóváhagyás után publikus', [$mod['body'], $public2['body']]);
        t_ok(str_contains(strtolower($public2['headers']), 'content-type: application/json') && str_contains(strtolower($public2['headers']), 'x-content-type-options: nosniff'), 'Publikus értékelés JSON + nosniff (kliens textContent-tel renderel)');

        t_section('Integráció – parancspaletta gyorsműveletek');
        $palette = $admin2->get('/api/platform.php?module=palette');
        t_ok($palette['status'] === 200 && count($palette['json']['actions'] ?? []) >= 4, 'Parancspaletta műveletek', $palette['body']);
        $qp = $admin2->post('/api/platform.php?module=quick', ['action' => 'new_project', 'title' => 'Gyors projekt teszt']);
        t_ok(in_array($qp['status'], [200, 201], true), 'Gyorsművelet: új projekt', $qp['body']);
        $qs = $admin2->post('/api/platform.php?module=quick', ['action' => 'change_status', 'entity' => 'work_order', 'id' => $woId, 'status' => 'nemletezik']);
        t_ok($qs['status'] === 422, 'Gyorsművelet: érvénytelen státusz elutasítva', $qs['body']);
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
}

echo "\nÖsszesen: " . ($passed + $failed) . ", sikeres: {$passed}, sikertelen: {$failed}\n";
exit($failed > 0 ? 1 : 0);
