<?php
/**
 * Shared helpers for the registration API.
 *
 * Everything that must not be trusted from the browser lives here: prices,
 * the early-bird cutoff, and input validation.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

function config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    // Preferred: above public_html, where the web server cannot serve it.
    $candidates = [
        __DIR__ . '/../../marathon-config.php',
        __DIR__ . '/../marathon-config.php',
        __DIR__ . '/config.php',   // local testing only; gitignored
    ];

    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $cfg = require $path;
            return $cfg;
        }
    }

    http_response_code(500);
    if (admin_context()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Server is not configured.

"
           . "marathon-config.php was not found above public_html.";
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server is not configured.']);
    exit;
}

/**
 * The admin panel shares these helpers but renders HTML, so a JSON error body
 * would show a user a wall of braces instead of a message they can act on.
 */
function admin_context(): bool
{
    return defined('MARATHON_ADMIN');
}

function cfg(string $key, $default = null)
{
    $c = config();
    return $c[$key] ?? $default;
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * Locks the API to our own origins. The reference site this was modelled on
 * used `Access-Control-Allow-Origin: *`, which lets any website on the
 * internet create orders against the merchant account.
 */
function send_cors(): void
{
    $allowed = cfg('ALLOWED_ORIGINS', []);
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(405, 'Method not allowed.');
    }
}

/**
 * Reads the request body as JSON, or falls back to $_POST when the browser
 * sent multipart/form-data (which it must, to carry the ID proof file).
 */
function json_body(): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'multipart/form-data') !== false) {
        return $_POST;
    }

    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ok(array $payload): void
{
    echo json_encode(['ok' => true] + $payload);
    exit;
}

/**
 * Public message only. Internal detail goes to the error log, never to the
 * browser — the reference site returned its SMTP host, port and username in a
 * failure response.
 */
function fail(int $status, string $message, string $internal = ''): void
{
    if ($internal !== '') {
        error_log('[marathon-api] ' . $internal);
    }
    http_response_code($status);

    if (admin_context()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        if (cfg('DEBUG') && $internal !== '') {
            echo "

" . $internal;
        }
        exit;
    }

    $out = ['ok' => false, 'error' => $message];
    if (cfg('DEBUG') && $internal !== '') {
        $out['debug'] = $internal;
    }
    echo json_encode($out);
    exit;
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // DB_DSN is an escape hatch for running the site locally against SQLite,
    // where there is no MySQL to point at. Production leaves it unset and gets
    // the MySQL DSN below.
    $dsn = (string) cfg('DB_DSN', '');

    if ($dsn === '') {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            cfg('DB_HOST', 'localhost'),
            cfg('DB_NAME')
        );
    }

    $isSqlite = str_starts_with($dsn, 'sqlite:');

    try {
        $pdo = new PDO($dsn, $isSqlite ? null : cfg('DB_USER'), $isSqlite ? null : cfg('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        fail(500, 'Could not reach the registration database.', 'DB: ' . $e->getMessage());
    }

    return $pdo;
}

// ---------------------------------------------------------------------------
// Prices — the single source of truth
// ---------------------------------------------------------------------------

/**
 * Amounts are in paise. The browser sends a CATEGORY, never an amount: if the
 * client could name its own price, anyone could register for one rupee.
 */
const CATEGORIES = [
    'half'  => ['label' => 'Half Marathon',     'distance' => '21 KM', 'base_paise' => 150000],
    'mini'  => ['label' => 'Mini Marathon',     'distance' => '10 KM', 'base_paise' => 100000],
    'cause' => ['label' => 'Run for Cause',     'distance' => '5 KM',  'base_paise' => 65000],
    'para'  => ['label' => 'Disabled Category', 'distance' => '1 KM',  'base_paise' => 0],
];

/** Assembly and flag-off, as published on the site. Used in the email. */
const RACE_TIMES = [
    'half'  => '5:00 AM / 5:30 AM',
    'mini'  => '5:50 AM / 6:20 AM',
    'cause' => '7:00 AM / 7:15 AM',
    'para'  => '6:30 AM / 6:40 AM',
];

/** Accepted photo IDs, verified in person at bib collection. */
const ID_PROOF_TYPES = ['Aadhaar', 'PAN', 'Passport', 'Driving Licence', 'Voter ID'];

const EARLY_BIRD_PERCENT = 20;
const EARLY_BIRD_UNTIL   = '2026-11-07 23:59:59';   // IST, inclusive

/** Evaluated server-side so a changed device clock cannot buy the old price. */
function early_bird_active(): bool
{
    return new DateTimeImmutable('now') <= new DateTimeImmutable(EARLY_BIRD_UNTIL);
}

function category_exists(string $key): bool
{
    return isset(CATEGORIES[$key]);
}

/**
 * @return array{base:int, payable:int, early:bool, label:string, distance:string}
 */
function price_for(string $key): array
{
    $c     = CATEGORIES[$key];
    $base  = $c['base_paise'];
    $early = $base > 0 && early_bird_active();

    $payable = $early
        ? (int) round($base * (100 - EARLY_BIRD_PERCENT) / 100)
        : $base;

    return [
        'base'     => $base,
        'payable'  => $payable,
        'early'    => $early,
        'label'    => $c['label'],
        'distance' => $c['distance'],
    ];
}

function rupees(int $paise): string
{
    return number_format($paise / 100, 2);
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

/**
 * Returns [cleanFields, errors]. Never trusts anything for length or type.
 */
function validate_runner(array $in): array
{
    $e = [];
    $v = [];

    $v['full_name'] = trim((string) ($in['fullName'] ?? ''));
    if (mb_strlen($v['full_name']) < 2 || mb_strlen($v['full_name']) > 120) {
        $e['fullName'] = 'Please enter your full name.';
    }

    $v['email'] = trim((string) ($in['email'] ?? ''));
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 190) {
        $e['email'] = 'Please enter a valid email address.';
    }

    // Indian mobile: 10 digits starting 6-9, optionally +91 / 0 prefixed.
    $mobile = preg_replace('/[^0-9]/', '', (string) ($in['mobile'] ?? ''));
    $mobile = preg_replace('/^(91|0)(?=\d{10}$)/', '', $mobile);
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        $e['mobile'] = 'Please enter a valid 10-digit mobile number.';
    }
    $v['mobile'] = $mobile;

    $age = filter_var($in['age'] ?? null, FILTER_VALIDATE_INT);
    if ($age === false || $age < 5 || $age > 100) {
        $e['age'] = 'Please enter an age between 5 and 100.';
    }
    $v['age'] = (int) $age;

    $v['gender'] = (string) ($in['gender'] ?? '');
    if (!in_array($v['gender'], ['Male', 'Female', 'Other'], true)) {
        $e['gender'] = 'Please select a gender.';
    }

    $v['city'] = trim((string) ($in['city'] ?? ''));
    if ($v['city'] === '' || mb_strlen($v['city']) > 90) {
        $e['city'] = 'Please enter your city.';
    }

    $v['tshirt_size'] = (string) ($in['tshirtSize'] ?? '');
    if (!in_array($v['tshirt_size'], ['XS', 'S', 'M', 'L', 'XL', 'XXL'], true)) {
        $e['tshirtSize'] = 'Please choose a T-shirt size.';
    }

    // Which photo ID the runner will bring. Only the TYPE is recorded — the
    // document itself is checked in person at kit collection, so there is no
    // reason to hold an Aadhaar or PAN number in the database.
    $v['id_proof_type'] = (string) ($in['idProofType'] ?? '');
    if (!in_array($v['id_proof_type'], ID_PROOF_TYPES, true)) {
        $e['idProofType'] = 'Please select an ID proof type.';
    }

    $v['emergency_name'] = mb_substr(trim((string) ($in['emergencyName'] ?? '')), 0, 120);

    $ec = preg_replace('/[^0-9]/', '', (string) ($in['emergencyPhone'] ?? ''));
    $v['emergency_phone'] = mb_substr($ec, 0, 20);

    $v['category'] = (string) ($in['category'] ?? '');
    if (!category_exists($v['category'])) {
        $e['category'] = 'Please choose a race category.';
    }

    // Minimum ages published on the site.
    if (!isset($e['category']) && !isset($e['age'])) {
        $min = ['half' => 18, 'mini' => 18, 'cause' => 12, 'para' => 0];
        if ($v['age'] < $min[$v['category']]) {
            $e['age'] = sprintf(
                '%s is open to runners aged %d and over.',
                CATEGORIES[$v['category']]['label'],
                $min[$v['category']]
            );
        }
    }

    if (empty($in['declaration'])) {
        $e['declaration'] = 'Please confirm the health declaration.';
    }

    return [$v, $e];
}

function new_registration_id(): string
{
    return 'MSL26-' . strtoupper(bin2hex(random_bytes(4)));
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// ---------------------------------------------------------------------------
// ID proof upload
// ---------------------------------------------------------------------------

const ID_UPLOAD_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB

/** Real MIME (sniffed from content) => the extension we will give the file. */
const ID_UPLOAD_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

/**
 * Where uploaded IDs are kept. This MUST be outside public_html: these are
 * identity documents, and anything under the web root is one guessed URL away
 * from being downloaded by anyone.
 */
function id_upload_dir(): string
{
    $dir = (string) cfg('UPLOAD_DIR', dirname(__DIR__, 2) . '/marathon-uploads/id-proofs');

    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        fail(500, 'Could not store your ID proof. Please try again.', 'mkdir failed: ' . $dir);
    }

    // Belt and braces in case the directory ever ends up served by Apache.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
    }

    return $dir;
}

/**
 * Validates and stores the uploaded ID proof.
 *
 * @return array{0:?string,1:?string} [storedFilename, errorMessage]
 */
function store_id_proof(string $field = 'idProofFile'): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [null, 'Please attach a photo or PDF of your ID proof.'];
    }

    $f = $_FILES[$field];

    switch ($f['error'] ?? UPLOAD_ERR_NO_FILE) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return [null, 'Please attach a photo or PDF of your ID proof.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [null, 'That file is too large. Please upload one under 5 MB.'];
        default:
            return [null, 'The upload did not complete. Please try again.'];
    }

    // Guards against a forged path being passed off as an upload.
    if (!is_uploaded_file($f['tmp_name'])) {
        return [null, 'The upload could not be verified. Please try again.'];
    }

    if (($f['size'] ?? 0) <= 0) {
        return [null, 'That file appears to be empty.'];
    }
    if ($f['size'] > ID_UPLOAD_MAX_BYTES) {
        return [null, 'That file is too large. Please upload one under 5 MB.'];
    }

    // Trust the file's actual content, never its name or the browser's
    // declared type — a .php renamed to .jpg would sail past an extension check.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($f['tmp_name']);

    if (!isset(ID_UPLOAD_TYPES[$mime])) {
        return [null, 'Please upload a JPG, PNG, WEBP or PDF.'];
    }

    // Our own random name: the original is attacker-controlled and may contain
    // path traversal or a second extension.
    $name = date('Ymd') . '-' . bin2hex(random_bytes(16)) . '.' . ID_UPLOAD_TYPES[$mime];
    $dest = id_upload_dir() . '/' . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return [null, 'Could not save your ID proof. Please try again.'];
    }

    @chmod($dest, 0640);

    return [$name, null];
}
