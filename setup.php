<?php
/**
 * One-time setup wizard.
 *
 * Does the four things that otherwise have to be done by hand in Hostinger's
 * File Manager and phpMyAdmin:
 *
 *   1. checks the server can actually run the site
 *   2. creates the database tables
 *   3. writes marathon-config.php ABOVE public_html, where it cannot be served
 *   4. creates the ID-proof upload folder outside the web root
 *
 * It refuses to run once a config file exists, so the moment it succeeds it
 * becomes inert. Delete it afterwards anyway — it is printed as the last step.
 *
 * Deliberately standalone: it cannot use lib.php, because lib.php is exactly
 * what fails when there is no configuration yet.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------------
// Where things go
// ---------------------------------------------------------------------------

/** public_html itself. */
$webroot = __DIR__;

/** One level up — not served by the web server. */
$home = dirname($webroot);

$configPath = $home . '/marathon-config.php';
$uploadPath = $home . '/marathon-uploads/id-proofs';

// The guard. Once configured, this page does not exist.
$alreadyConfigured = is_readable($configPath)
    || is_readable($webroot . '/marathon-config.php')
    || is_readable($webroot . '/api/config.php');

// ---------------------------------------------------------------------------
// Preflight
// ---------------------------------------------------------------------------

function check(string $label, bool $ok, string $fix = ''): array
{
    return ['label' => $label, 'ok' => $ok, 'fix' => $fix];
}

$checks = [
    check('PHP 8.0 or newer (found ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80000,
        'Set PHP 8 in Hostinger > Advanced > PHP Configuration.'),
    check('MySQL driver (pdo_mysql)', extension_loaded('pdo_mysql'),
        'Enable pdo_mysql in Hostinger > Advanced > PHP Configuration > Extensions.'),
    check('File type detection (fileinfo)', extension_loaded('fileinfo'),
        'Enable fileinfo. Without it, uploaded ID proofs cannot be checked.'),
    check('Text handling (mbstring)', extension_loaded('mbstring'),
        'Enable mbstring.'),
    // Only create-order.php needs it, and only when payments are on — but a
    // missing curl there is a fatal error at the moment someone tries to pay,
    // which is the worst possible time to find out.
    check('Outbound HTTP (curl) — needed for online payment', extension_loaded('curl'),
        'Enable curl. Without it the Razorpay checkout cannot create an order.'),
    check('Can write above public_html', is_writable($home),
        'The folder ' . $home . ' is not writable by PHP. Create marathon-config.php by hand instead.'),
];

$preflightOk = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $preflightOk = false;
    }
}

// ---------------------------------------------------------------------------
// Schema — inlined, because the build strips .sql files from the upload
// ---------------------------------------------------------------------------

$SCHEMA = [
"CREATE TABLE IF NOT EXISTS registrations (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id     VARCHAR(32)  NOT NULL,
  category            VARCHAR(16)  NOT NULL,
  full_name           VARCHAR(120) NOT NULL,
  email               VARCHAR(190) NOT NULL,
  mobile              VARCHAR(20)  NOT NULL,
  age                 SMALLINT UNSIGNED NOT NULL,
  dob                 DATE         DEFAULT NULL,
  gender              VARCHAR(16)  NOT NULL,
  city                VARCHAR(90)  NOT NULL,
  tshirt_size         VARCHAR(8)   NOT NULL,
  id_proof_type       VARCHAR(20)  NOT NULL,
  id_proof_file       VARCHAR(120) DEFAULT NULL,
  emergency_name      VARCHAR(120) DEFAULT NULL,
  emergency_phone     VARCHAR(20)  DEFAULT NULL,
  amount_paise        INT UNSIGNED NOT NULL DEFAULT 0,
  early_bird          TINYINT(1)   NOT NULL DEFAULT 0,
  status              ENUM('pending','awaiting','paid','free','failed') NOT NULL DEFAULT 'pending',
  razorpay_order_id   VARCHAR(64)  DEFAULT NULL,
  razorpay_payment_id VARCHAR(64)  DEFAULT NULL,
  receipt_emailed     TINYINT(1)   NOT NULL DEFAULT 0,
  ip_address          VARCHAR(45)  DEFAULT NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at             DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_registration_id (registration_id),
  UNIQUE KEY uniq_order_id (razorpay_order_id),
  KEY idx_email (email),
  KEY idx_status (status),
  KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(40)  NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(190) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('owner','manager','viewer') NOT NULL DEFAULT 'viewer',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  must_change   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS admin_audit (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action     VARCHAR(40)  NOT NULL,
  subject    VARCHAR(64)  DEFAULT NULL,
  ip_address VARCHAR(45)  DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// Brings an older install up to date. Harmless on a fresh one; the ALTERs are
// wrapped in the loop's try/catch because MySQL errors on a duplicate column.
"ALTER TABLE registrations
   MODIFY COLUMN status
   ENUM('pending','awaiting','paid','free','failed') NOT NULL DEFAULT 'pending'",

"ALTER TABLE registrations ADD COLUMN dob DATE DEFAULT NULL AFTER age",

"ALTER TABLE admin_audit ADD COLUMN actor VARCHAR(40) DEFAULT NULL AFTER action",
"ALTER TABLE admin_login_attempts ADD COLUMN username VARCHAR(40) DEFAULT NULL AFTER ip_address",
];

// ---------------------------------------------------------------------------
// Config file
// ---------------------------------------------------------------------------

/**
 * Builds the contents of marathon-config.php.
 *
 * Every value goes through var_export(), so a password containing a quote,
 * a backslash or a dollar sign cannot break out of the string and corrupt the
 * file. A malformed config here would take the whole site down, not just the
 * setting that was wrong.
 */
function build_config_php(array $v): string
{
    $q = static fn (string $s): string => var_export($s, true);

    $lines = [];
    $lines[] = '<?php';
    $lines[] = '/**';
    $lines[] = ' * Written by setup.php on ' . date('j F Y, g:i A') . '.';
    $lines[] = ' * Holds live credentials. Keep it here, above public_html.';
    $lines[] = ' */';
    $lines[] = '';
    $lines[] = 'return [';
    $lines[] = '    // true  = Razorpay checkout runs and the runner pays online.';
    $lines[] = '    // false = the site takes registrations and the team collects the fee.';
    $lines[] = '    // Payment is only ever switched on when a key pair is present, so this';
    $lines[] = '    // cannot end up true with nothing behind it.';
    $lines[] = "    'PAYMENTS_ENABLED' => " . ($v['payments_enabled'] ? 'true' : 'false') . ',';
    $lines[] = '';
    $lines[] = "    'RAZORPAY_KEY_ID'     => " . $q($v['rzp_key_id']) . ',';
    $lines[] = "    'RAZORPAY_KEY_SECRET' => " . $q($v['rzp_key_secret']) . ',';
    $lines[] = '';
    $lines[] = '    // From Razorpay > Settings > Webhooks. Without it the webhook endpoint';
    $lines[] = '    // refuses every call, so a payment whose browser died stays unconfirmed.';
    $lines[] = "    'RAZORPAY_WEBHOOK_SECRET' => " . $q($v['rzp_webhook_secret']) . ',';
    $lines[] = '';
    $lines[] = "    'DB_HOST' => " . $q($v['db_host']) . ',';
    $lines[] = "    'DB_NAME' => " . $q($v['db_name']) . ',';
    $lines[] = "    'DB_USER' => " . $q($v['db_user']) . ',';
    $lines[] = "    'DB_PASS' => " . $q($v['db_pass']) . ',';
    $lines[] = '';
    $lines[] = "    'UPLOAD_DIR' => " . $q($v['upload_dir']) . ',';
    $lines[] = '';
    $lines[] = "    'ADMIN_USER'          => " . $q($v['admin_user']) . ',';
    $lines[] = "    'ADMIN_PASSWORD_HASH' => " . $q($v['admin_hash']) . ',';
    $lines[] = '';
    $lines[] = "    'SMTP_HOST'      => " . $q($v['smtp_host']) . ',';
    $lines[] = "    'SMTP_PORT'      => 465,";
    $lines[] = "    'SMTP_USER'      => " . $q($v['smtp_user']) . ',';
    $lines[] = "    'SMTP_PASS'      => " . $q($v['smtp_pass']) . ',';
    $lines[] = "    'SMTP_FROM'      => " . $q($v['smtp_user']) . ',';
    $lines[] = "    'SMTP_FROM_NAME' => 'Milkha Singh Legacy Marathon',";
    $lines[] = '';
    $lines[] = "    'ADMIN_EMAIL' => " . $q($v['admin_email']) . ',';
    $lines[] = '';
    $lines[] = "    'ALLOWED_ORIGINS' => [";
    foreach ($v['origins'] as $o) {
        $lines[] = '        ' . $q($o) . ',';
    }
    $lines[] = '    ],';
    $lines[] = '';
    $lines[] = "    'DEBUG' => false,";
    $lines[] = '];';
    $lines[] = '';

    return implode(PHP_EOL, $lines);
}

/* Lets the generator be exercised on its own without rendering the page. */
if (defined('MARATHON_SETUP_TEST')) {
    return;
}

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------

$errors  = [];
$done    = false;
$steps   = [];
$posted  = [];

if (!$alreadyConfigured && $preflightOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $f = static function (string $k): string {
        return trim((string) ($_POST[$k] ?? ''));
    };

    $posted = [
        'db_host'    => $f('db_host') !== '' ? $f('db_host') : 'localhost',
        'db_name'    => $f('db_name'),
        'db_user'    => $f('db_user'),
        'admin_user' => $f('admin_user') !== '' ? $f('admin_user') : 'admin',
        'smtp_host'  => $f('smtp_host'),
        'smtp_user'  => $f('smtp_user'),
        'admin_email'=> $f('admin_email'),
        'domain'     => $f('domain'),
        'rzp_key_id' => $f('rzp_key_id'),
    ];

    $rzpKeyId         = trim((string) ($_POST['rzp_key_id'] ?? ''));
    $rzpKeySecret     = trim((string) ($_POST['rzp_key_secret'] ?? ''));
    $rzpWebhookSecret = trim((string) ($_POST['rzp_webhook_secret'] ?? ''));

    $dbPass    = (string) ($_POST['db_pass'] ?? '');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $smtpPass  = (string) ($_POST['smtp_pass'] ?? '');

    if ($posted['db_name'] === '') { $errors[] = 'Enter the database name.'; }
    if ($posted['db_user'] === '') { $errors[] = 'Enter the database user.'; }
    if (strlen($adminPass) < 12)   { $errors[] = 'The admin password must be at least 12 characters.'; }
    if ($posted['domain'] === '')  { $errors[] = 'Enter the site address, e.g. https://milkhasinghlegacymarathon.com'; }

    if (($rzpKeyId === '') !== ($rzpKeySecret === '')) {
        $errors[] = 'Enter both the Razorpay key id and the key secret, or leave both blank.';
    }
    if ($rzpKeyId !== '' && !preg_match('/^rzp_(test|live)_/', $rzpKeyId)) {
        $errors[] = 'That does not look like a Razorpay key id. They start with rzp_test_ or rzp_live_.';
    }

    // ---- Database ----
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $posted['db_host'], $posted['db_name']),
                $posted['db_user'],
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $steps[] = 'Connected to the database.';
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database: ' . $e->getMessage();
        }
    }

    if (!$errors && $pdo instanceof PDO) {
        foreach ($SCHEMA as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // The ALTER is a no-op on a fresh database in some MySQL builds.
                if (stripos($sql, 'ALTER TABLE') === false) {
                    $errors[] = 'Could not create the tables: ' . $e->getMessage();
                    break;
                }
            }
        }
        if (!$errors) {
            $steps[] = 'Created the registrations, users, login-attempts and audit tables.';
        }

        // The first owner is a real user row, not just a line in the config, so
        // the panel has someone to attribute actions to from the very first
        // sign-in. The config credential stays as a way back in if the account
        // is ever lost.
        if (!$errors) {
            try {
                $exists = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
                if ($exists === 0) {
                    $ins = $pdo->prepare(
                        'INSERT INTO admin_users
                            (username, full_name, password_hash, role, is_active, must_change)
                         VALUES (?, ?, ?, ?, 1, 0)'
                    );
                    $ins->execute([
                        $posted['admin_user'],
                        'Owner',
                        password_hash($adminPass, PASSWORD_DEFAULT),
                        'owner',
                    ]);
                    $steps[] = 'Created the owner account "' . $posted['admin_user'] . '".';
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not create the owner account: ' . $e->getMessage();
            }
        }
    }

    // ---- Upload folder ----
    if (!$errors) {
        if (!is_dir($uploadPath) && !@mkdir($uploadPath, 0750, true) && !is_dir($uploadPath)) {
            $errors[] = 'Could not create the upload folder at ' . $uploadPath;
        } else {
            @file_put_contents($uploadPath . '/.htaccess', "Require all denied\nOptions -Indexes\n");
            $steps[] = 'Created the ID-proof folder outside public_html.';
        }
    }

    // ---- Config file ----
    if (!$errors) {
        $domain = rtrim($posted['domain'], '/');
        if (!preg_match('~^https?://~', $domain)) {
            $domain = 'https://' . $domain;
        }
        $host = parse_url($domain, PHP_URL_HOST) ?: '';
        $origins = array_values(array_unique(array_filter([
            $domain,
            $host !== '' ? 'https://' . (str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host) : '',
        ])));

        $php = build_config_php([
            'db_host'     => $posted['db_host'],
            'db_name'     => $posted['db_name'],
            'db_user'     => $posted['db_user'],
            'db_pass'     => $dbPass,
            'upload_dir'  => $uploadPath,
            'admin_user'  => $posted['admin_user'],
            'admin_hash'  => password_hash($adminPass, PASSWORD_DEFAULT),
            'smtp_host'   => $posted['smtp_host'],
            'smtp_user'   => $posted['smtp_user'],
            'smtp_pass'   => $smtpPass,
            'admin_email' => $posted['admin_email'] !== '' ? $posted['admin_email'] : $posted['smtp_user'],
            'origins'     => $origins,
            // Online payment turns on only when both keys are present. Half a key
            // pair would mean a checkout that fails at the moment someone pays.
            'payments_enabled'   => $rzpKeyId !== '' && $rzpKeySecret !== '',
            'rzp_key_id'         => $rzpKeyId,
            'rzp_key_secret'     => $rzpKeySecret,
            'rzp_webhook_secret' => $rzpWebhookSecret,
        ]);

        if (@file_put_contents($configPath, $php) === false) {
            $errors[] = 'Could not write ' . $configPath . '. Create it by hand instead.';
        } else {
            @chmod($configPath, 0600);
            $steps[] = 'Wrote marathon-config.php above public_html.';
            $done = true;
        }
    }
}

$v = static function (string $k, string $fallback = '') use ($posted): string {
    return htmlspecialchars($posted[$k] ?? $fallback, ENT_QUOTES, 'UTF-8');
};
$e = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Setup &middot; Milkha Singh Legacy Marathon</title>
<link rel="stylesheet" href="admin/assets/admin.css?v=20260905-9">
<style>
  .setup { max-width: 720px; margin: 40px auto; padding: 0 20px 80px; }
  .setup h1 { color: var(--navy); margin: 0 0 6px; }
  .setup .lede { color: var(--ink-mute); margin: 0 0 26px; }
  .card { background: #fff; border: 1px solid var(--border); border-top: 3px solid var(--saffron);
          border-radius: 10px; padding: 22px; margin-bottom: 18px; box-shadow: var(--shadow); }
  .card h2 { margin: 0 0 4px; font-size: .95rem; color: var(--navy); text-transform: none; letter-spacing: 0; }
  .card p.hint { margin: 0 0 16px; font-size: .84rem; color: var(--ink-mute); }
  .field { margin-bottom: 14px; }
  .field label { display: block; font-size: .84rem; font-weight: 600; color: var(--ink-dim); margin-bottom: 5px; }
  .field input { width: 100%; min-height: 42px; padding: 9px 12px; font: inherit;
                 border: 1px solid var(--border); border-radius: 6px; }
  .field input:focus { outline: 2px solid var(--saffron-deep); outline-offset: 1px; }
  .field small { display: block; margin-top: 4px; font-size: .78rem; color: var(--ink-mute); }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  @media (max-width: 560px) { .row { grid-template-columns: 1fr; } }
  ul.checks { list-style: none; padding: 0; margin: 0; }
  ul.checks li { padding: 7px 0; border-bottom: 1px solid var(--border); font-size: .88rem; }
  ul.checks li:last-child { border-bottom: 0; }
  .yes { color: var(--green-deep); font-weight: 700; }
  .no  { color: #B42318; font-weight: 700; }
  ol.next { margin: 14px 0 0; padding-left: 20px; font-size: .9rem; line-height: 1.9; }
  code { word-break: break-all; }
</style>
</head>
<body>

<main class="setup">

<?php if ($alreadyConfigured): ?>

  <h1>Already set up</h1>
  <p class="lede">A configuration file is in place, so this page has switched itself off.</p>
  <div class="card">
    <p style="margin:0 0 14px">If you need to change a setting, edit
      <code><?= $e($configPath) ?></code> directly.</p>
    <p style="margin:0"><b>Delete this file (<code>setup.php</code>) from public_html now.</b></p>
    <p style="margin:14px 0 0"><a class="btn btn--primary" href="admin/">Go to the admin panel</a></p>
  </div>

<?php elseif ($done): ?>

  <h1>Setup complete</h1>
  <p class="lede">The registration form and the admin panel are live.</p>

  <div class="card">
    <h2>What was done</h2>
    <ul class="checks">
      <?php foreach ($steps as $s): ?>
        <li><span class="yes">&#10003;</span> <?= $e($s) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <h2>Do these two things now</h2>
    <ol class="next">
      <li><b>Delete <code>setup.php</code></b> from public_html. It writes credentials, so it must not stay on a live site.</li>
      <li><b>Delete <code>admin/hash-tool.php</code></b> as well. It is no longer needed.</li>
    </ol>
    <p style="margin:18px 0 0">
      <a class="btn btn--primary" href="admin/">Sign in to the admin panel</a>
      <a class="btn" href="index.html#register">Try the registration form</a>
    </p>
  </div>

<?php elseif (!$preflightOk): ?>

  <h1>Server check failed</h1>
  <p class="lede">Fix the items marked below, then reload this page.</p>
  <div class="card">
    <ul class="checks">
      <?php foreach ($checks as $c): ?>
        <li>
          <span class="<?= $c['ok'] ? 'yes' : 'no' ?>"><?= $c['ok'] ? '&#10003;' : '&#10007;' ?></span>
          <?= $e($c['label']) ?>
          <?php if (!$c['ok'] && $c['fix'] !== ''): ?>
            <small style="display:block;color:#B42318"><?= $e($c['fix']) ?></small>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

<?php else: ?>

  <h1>Set up the marathon site</h1>
  <p class="lede">
    This runs once. It creates the database tables and writes your settings to a file
    above <code>public_html</code>, where the web server cannot serve it.
  </p>

  <?php if ($errors): ?>
    <div class="card" style="border-top-color:#B42318">
      <h2 style="color:#B42318">That did not work</h2>
      <ul class="checks">
        <?php foreach ($errors as $err): ?>
          <li><span class="no">&#10007;</span> <?= $e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Server check</h2>
    <ul class="checks">
      <?php foreach ($checks as $c): ?>
        <li><span class="yes">&#10003;</span> <?= $e($c['label']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <form method="post" autocomplete="off">

    <div class="card">
      <h2>Database</h2>
      <p class="hint">From Hostinger &rsaquo; Databases &rsaquo; MySQL. Create the database there first if you have not.</p>

      <div class="field">
        <label for="db_name">Database name</label>
        <input id="db_name" name="db_name" required value="<?= $v('db_name') ?>" placeholder="u123456789_marathon">
      </div>
      <div class="row">
        <div class="field">
          <label for="db_user">Database user</label>
          <input id="db_user" name="db_user" required value="<?= $v('db_user') ?>" placeholder="u123456789_marathon">
        </div>
        <div class="field">
          <label for="db_pass">Database password</label>
          <input id="db_pass" name="db_pass" type="password" required>
        </div>
      </div>
      <div class="field">
        <label for="db_host">Database host</label>
        <input id="db_host" name="db_host" value="<?= $v('db_host', 'localhost') ?>">
        <small>Leave as <code>localhost</code> unless Hostinger shows something different.</small>
      </div>
    </div>

    <div class="card">
      <h2>Admin panel login</h2>
      <p class="hint">
        This creates the first <b>owner</b> account &mdash; the one that can add
        other staff and set what each of them may do. The password is stored
        only as a hash.
      </p>

      <div class="row">
        <div class="field">
          <label for="admin_user">Username</label>
          <input id="admin_user" name="admin_user" value="<?= $v('admin_user', 'admin') ?>">
        </div>
        <div class="field">
          <label for="admin_pass">Password</label>
          <input id="admin_pass" name="admin_pass" type="password" required minlength="12">
          <small>At least 12 characters. Write it down; it cannot be recovered.</small>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Site address</h2>
      <div class="field">
        <label for="domain">Website address</label>
        <input id="domain" name="domain" required
               value="<?= $v('domain', 'https://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>">
        <small>Only this address may submit the registration form.</small>
      </div>
    </div>

    <div class="card">
      <h2>Online payment <span style="font-weight:400;color:var(--ink-mute)">(optional)</span></h2>
      <p class="hint">
        From Razorpay &rsaquo; Account &amp; Settings &rsaquo; API Keys. <b>Leave blank</b> to take
        registrations without payment &mdash; entries are stored as &ldquo;awaiting payment&rdquo;
        and your team collects the fee. Fill both in and the checkout goes live.
      </p>

      <div class="row">
        <div class="field">
          <label for="rzp_key_id">Key id</label>
          <input id="rzp_key_id" name="rzp_key_id" value="<?= $v('rzp_key_id') ?>"
                 placeholder="rzp_test_... or rzp_live_...">
        </div>
        <div class="field">
          <label for="rzp_key_secret">Key secret</label>
          <input id="rzp_key_secret" name="rzp_key_secret" type="password">
          <small>Never sent to the browser. Stored above public_html only.</small>
        </div>
      </div>
      <div class="field">
        <label for="rzp_webhook_secret">Webhook secret</label>
        <input id="rzp_webhook_secret" name="rzp_webhook_secret" type="password">
        <small>
          From Razorpay &rsaquo; Settings &rsaquo; Webhooks. Needed so a payment still
          confirms when the runner&rsquo;s browser closes mid-checkout.
        </small>
      </div>
    </div>

    <div class="card">
      <h2>Confirmation email <span style="font-weight:400;color:var(--ink-mute)">(optional)</span></h2>
      <p class="hint">
        From Hostinger &rsaquo; Emails. Leave blank for now if the mailbox does not exist yet —
        registrations will still be saved, runners just will not get an email.
      </p>

      <div class="row">
        <div class="field">
          <label for="smtp_user">Email address</label>
          <input id="smtp_user" name="smtp_user" type="email" value="<?= $v('smtp_user') ?>"
                 placeholder="register@<?= $e(preg_replace('~^www\.~', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) ?>">
        </div>
        <div class="field">
          <label for="smtp_pass">Mailbox password</label>
          <input id="smtp_pass" name="smtp_pass" type="password">
        </div>
      </div>
      <div class="row">
        <div class="field">
          <label for="smtp_host">SMTP server</label>
          <input id="smtp_host" name="smtp_host" value="<?= $v('smtp_host', 'smtp.hostinger.com') ?>">
        </div>
        <div class="field">
          <label for="admin_email">Send alerts to</label>
          <input id="admin_email" name="admin_email" type="email" value="<?= $v('admin_email') ?>"
                 placeholder="info@<?= $e(preg_replace('~^www\.~', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) ?>">
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn--primary btn--lg" style="width:100%;min-height:48px">
      Run setup
    </button>
  </form>

<?php endif; ?>

</main>
</body>
</html>
