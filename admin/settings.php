<?php
/**
 * Payment settings, editable by an owner.
 *
 * Once setup.php has run it makes itself inert, which left no way to add the
 * Razorpay keys or the webhook signing secret without editing
 * marathon-config.php by hand over FTP. This is that missing page.
 *
 * It rewrites the same config file it read, and it only ever touches four
 * keys. Everything else in the file — database credentials, SMTP, allowed
 * origins — is carried through untouched.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/mailer.php';

require_admin();
require_can('manage_settings');

$path   = config_path();
$errors = [];
$flash  = '';

/** Only these may be changed here. Anything else is copied through verbatim. */
const EDITABLE = [
    'PAYMENTS_ENABLED',
    'RAZORPAY_KEY_ID',
    'RAZORPAY_KEY_SECRET',
    'RAZORPAY_WEBHOOK_SECRET',
];

/**
 * Rewrites the config file.
 *
 * The new contents are written to a temporary file and PARSED before they are
 * allowed to replace the real one. A malformed config does not break one
 * setting, it takes the entire site and this page with it — at which point the
 * only way back is FTP.
 *
 * @return string|null an error message, or null on success
 */
function write_config(string $path, array $values): ?string
{
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '/**';
    $lines[] = ' * Updated from the admin settings page on ' . date('j F Y, g:i A') . '.';
    $lines[] = ' * Holds live credentials. Keep it here, above public_html.';
    $lines[] = ' */';
    $lines[] = '';
    $lines[] = 'return [';

    foreach ($values as $k => $v) {
        if (is_bool($v)) {
            $lines[] = '    ' . var_export($k, true) . ' => ' . ($v ? 'true' : 'false') . ',';
        } elseif (is_int($v)) {
            $lines[] = '    ' . var_export($k, true) . ' => ' . $v . ',';
        } elseif (is_array($v)) {
            $lines[] = '    ' . var_export($k, true) . ' => [';
            foreach ($v as $item) {
                $lines[] = '        ' . var_export($item, true) . ',';
            }
            $lines[] = '    ],';
        } else {
            $lines[] = '    ' . var_export($k, true) . ' => ' . var_export((string) $v, true) . ',';
        }
    }

    $lines[] = '];';
    $lines[] = '';
    $php = implode(PHP_EOL, $lines);

    // A unique name per attempt: the opcode cache keys on the file path, and
    // reusing one name can hand back a previously compiled version instead of
    // the file just written.
    $tmp = $path . '.new-' . bin2hex(random_bytes(4));

    if (@file_put_contents($tmp, $php) === false) {
        return 'Could not write next to ' . $path . '. Check the folder permissions.';
    }

    $parsed = null;
    try {
        $parsed = @include $tmp;
    } catch (Throwable $e) {
        @unlink($tmp);
        return 'The new settings file was rejected: ' . $e->getMessage();
    }

    // It must parse, come back as an array, and still carry a way to reach the
    // database — either the MySQL name or the DSN override. Checking for one
    // specific key would reject a perfectly good file in the other shape.
    $hasDatabase = is_array($parsed)
        && (($parsed['DB_NAME'] ?? '') !== '' || ($parsed['DB_DSN'] ?? '') !== '');

    if (!$hasDatabase) {
        @unlink($tmp);
        return 'The new settings file came out without database details, so nothing was changed.';
    }

    // Keep a copy of what was working before replacing it.
    @copy($path, $path . '.bak');

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return 'Could not replace the settings file.';
    }

    @chmod($path, 0600);
    return null;
}

// ---------------------------------------------------------------------------

$section = (string) ($_POST['section'] ?? 'payments');

// ---- Email settings --------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $section === 'email') {
    csrf_check();

    if ($path === null) {
        $errors[] = 'No configuration file was found, so there is nothing to update.';
    } else {
        $current = require $path;

        $runner  = trim((string) ($_POST['admin_email'] ?? ''));
        $sponsor = trim((string) ($_POST['sponsor_email'] ?? ''));
        $user    = trim((string) ($_POST['smtp_user'] ?? ''));
        $pass    = (string) ($_POST['smtp_pass'] ?? '');
        $host    = trim((string) ($_POST['smtp_host'] ?? ''));
        $port    = (int) ($_POST['smtp_port'] ?? 465);

        if ($pass === '') { $pass = (string) ($current['SMTP_PASS'] ?? ''); }   // blank keeps it
        if ($host === '') { $host = 'smtp.hostinger.com'; }

        foreach (['Runner registrations' => $runner, 'Sponsor enquiries' => $sponsor, 'Sending mailbox' => $user] as $label => $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $label . ': enter a valid email address.';
            }
        }
        if (!in_array($port, [465, 587], true)) {
            $errors[] = 'Port must be 465 (SSL) or 587 (STARTTLS).';
        }
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            $errors[] = 'That SMTP server name does not look right.';
        }

        if (!$errors) {
            $new = $current;
            $new['ADMIN_EMAIL']   = $runner;
            $new['SPONSOR_EMAIL'] = $sponsor;
            $new['SMTP_HOST']     = $host;
            $new['SMTP_PORT']     = $port;
            $new['SMTP_USER']     = $user;
            $new['SMTP_PASS']     = $pass;
            $new['SMTP_FROM']     = $user;   // Hostinger only relays mail from the signed-in mailbox
            $new['SMTP_FROM_NAME'] = (string) ($current['SMTP_FROM_NAME'] ?? 'Milkha Singh Legacy Marathon');

            $err = write_config($path, $new);
            if ($err !== null) {
                $errors[] = $err;
            } else {
                audit('settings_updated', 'email');
                header('Location: settings.php?done=email');
                exit;
            }
        }
    }
}

// ---- Test email --------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $section === 'test_email') {
    csrf_check();

    $to = trim((string) ($_POST['test_to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid address to send the test to.';
    } elseif (!mail_configured()) {
        $errors[] = 'Save the sending mailbox and its password first.';
    } else {
        $m  = smtp_mailer();
        $ok = $m->send(
            (string) cfg('SMTP_FROM', cfg('SMTP_USER')),
            (string) cfg('SMTP_FROM_NAME', 'Milkha Singh Legacy Marathon'),
            $to, '', 'Test email - Milkha Singh Legacy Marathon',
            '<p style="font-family:Arial,sans-serif">This is a test from the admin Settings page. '
            . 'If you can read this, registration and sponsorship emails will be delivered.</p>'
        );
        if ($ok) {
            audit('test_email_sent', $to);
            header('Location: settings.php?done=test&to=' . urlencode($to));
            exit;
        }
        $errors[] = 'The test email was not sent. The mail server said: ' . $m->lastError()
                  . ' — check the mailbox address and password in Hostinger › Emails.';
    }
}

// ---- Payment settings --------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $section === 'payments') {
    csrf_check();

    if ($path === null) {
        $errors[] = 'No configuration file was found, so there is nothing to update.';
    } else {
        $current = require $path;

        $keyId     = trim((string) ($_POST['rzp_key_id'] ?? ''));
        $keySecret = trim((string) ($_POST['rzp_key_secret'] ?? ''));
        $hookSecret = trim((string) ($_POST['rzp_webhook_secret'] ?? ''));
        $enabled   = isset($_POST['payments_enabled']);

        // A blank secret means "leave it alone", so an owner changing the
        // webhook secret does not have to retype the key secret they cannot see.
        if ($keySecret === '')  { $keySecret  = (string) ($current['RAZORPAY_KEY_SECRET'] ?? ''); }
        if ($hookSecret === '') { $hookSecret = (string) ($current['RAZORPAY_WEBHOOK_SECRET'] ?? ''); }
        if ($keyId === '')      { $keyId      = (string) ($current['RAZORPAY_KEY_ID'] ?? ''); }

        if ($keyId !== '' && !preg_match('/^rzp_(test|live)_/', $keyId)) {
            $errors[] = 'That does not look like a Razorpay key id. They start with rzp_test_ or rzp_live_.';
        }
        if ($enabled && ($keyId === '' || $keySecret === '')) {   // after the carry-forward above
            $errors[] = 'Online payment needs both a key id and a key secret. '
                      . 'Without them the checkout fails at the moment someone tries to pay.';
        }

        if (!$errors) {
            $new = $current;
            $new['PAYMENTS_ENABLED']        = $enabled;
            $new['RAZORPAY_KEY_ID']         = $keyId;
            $new['RAZORPAY_KEY_SECRET']     = $keySecret;
            $new['RAZORPAY_WEBHOOK_SECRET'] = $hookSecret;

            $err = write_config($path, $new);
            if ($err !== null) {
                $errors[] = $err;
            } else {
                audit('settings_updated', 'payments ' . ($enabled ? 'on' : 'off'));
                header('Location: settings.php?done=1');
                exit;
            }
        }
    }
}

$cfgNow  = $path !== null ? (require $path) : [];
$done    = (string) ($_GET['done'] ?? '');
$flash   = match ($done) {
    ''      => '',
    'email' => 'Email settings saved. Send a test email below to confirm they work.',
    'test'  => 'Test email sent to ' . (string) ($_GET['to'] ?? '') . '. Check that inbox, including spam.',
    default => 'Settings saved.',
};
$isLive  = str_starts_with((string) ($cfgNow['RAZORPAY_KEY_ID'] ?? ''), 'rzp_live_');

/** Never echo a secret back. Only whether one is set. */
function secret_state($v): string
{
    return ((string) $v) !== '' ? 'Set' : 'Not set';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Settings &middot; Marathon Admin</title>
<style>
  .pwform fieldset { border: 0; padding: 0; margin: 0 0 6px; }
  .pwform .pair { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
  @media (max-width: 560px) { .pwform .pair { grid-template-columns: 1fr; } }
</style>
<link rel="stylesheet" href="assets/admin.css?v=20260917-4">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap wrap--narrow">
  <h1 class="pagetitle">Settings</h1>
  <p class="pagesub">
    Stored in the configuration file above <code>public_html</code>, never in the
    database and never sent to a browser.
  </p>

  <?php if ($flash !== ''): ?>
    <p class="alert alert--ok"><?= h($flash) ?></p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert--error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($path === null): ?>
    <p class="alert alert--warn">No configuration file was found.</p>
  <?php else: ?>

  <section class="panel">
    <h2>Current state</h2>
    <dl class="dl">
      <dt>Online payment</dt>
      <dd>
        <span class="pill pill--<?= !empty($cfgNow['PAYMENTS_ENABLED']) ? 'paid' : 'pending' ?>">
          <?= !empty($cfgNow['PAYMENTS_ENABLED']) ? 'On' : 'Off' ?>
        </span>
        <?php if (empty($cfgNow['PAYMENTS_ENABLED'])): ?>
          <span class="muted">Entries are stored as awaiting payment for the team to collect.</span>
        <?php endif; ?>
      </dd>
      <dt>Mode</dt>
      <dd>
        <?php if (($cfgNow['RAZORPAY_KEY_ID'] ?? '') === ''): ?>
          <span class="muted">No key set</span>
        <?php else: ?>
          <b><?= $isLive ? 'LIVE — real money' : 'Test — no real money' ?></b>
          <span class="muted"><?= h($cfgNow['RAZORPAY_KEY_ID']) ?></span>
        <?php endif; ?>
      </dd>
      <dt>Key secret</dt><dd><?= h(secret_state($cfgNow['RAZORPAY_KEY_SECRET'] ?? '')) ?></dd>
      <dt>Webhook secret</dt>
      <dd>
        <?= h(secret_state($cfgNow['RAZORPAY_WEBHOOK_SECRET'] ?? '')) ?>
        <?php if ((string) ($cfgNow['RAZORPAY_WEBHOOK_SECRET'] ?? '') === ''): ?>
          <span class="muted">Razorpay's calls are being rejected until this is set.</span>
        <?php endif; ?>
      </dd>
    </dl>
  </section>

  <section class="panel">
    <h2>Update</h2>
    <form method="post" autocomplete="off" class="pwform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="payments">

      <label>
        <span>Key id</span>
        <input name="rzp_key_id" value="<?= h($cfgNow['RAZORPAY_KEY_ID'] ?? '') ?>"
               placeholder="rzp_test_... or rzp_live_...">
      </label>

      <label>
        <span>Key secret</span>
        <input name="rzp_key_secret" type="password" placeholder="Leave blank to keep the current one">
      </label>

      <label>
        <span>Webhook signing secret</span>
        <input name="rzp_webhook_secret" type="password" placeholder="Leave blank to keep the current one">
        <small>
          From Razorpay &rsaquo; Account &amp; Settings &rsaquo; Webhooks, shown when you
          create or edit the webhook. Until it is set, every call from Razorpay
          is refused &mdash; which is deliberate, since that endpoint can mark a
          registration paid.
        </small>
      </label>

      <label class="checkline">
        <input type="checkbox" name="payments_enabled" value="1"
               <?= !empty($cfgNow['PAYMENTS_ENABLED']) ? 'checked' : '' ?>>
        <span>
          <b>Take payment online</b>
          <small>
            On: runners pay by card or UPI during registration. Off: the entry is
            saved as awaiting payment and your team collects the fee.
          </small>
        </span>
      </label>

      <button type="submit" class="btn btn--primary">Save settings</button>
    </form>
  </section>

  <h1 class="pagetitle" style="margin-top:34px">Email</h1>
  <p class="pagesub">
    Who receives the website's emails, and the mailbox they are sent from.
  </p>

  <section class="panel">
    <h2>Current state</h2>
    <dl class="dl">
      <dt>Runner registrations</dt><dd><?= h(runner_desk_email()) ?></dd>
      <dt>Sponsor enquiries</dt><dd><?= h(sponsor_desk_email()) ?></dd>
      <dt>Sent from</dt>
      <dd>
        <?php if (mail_configured()): ?>
          <?= h((string) cfg('SMTP_USER')) ?> <span class="muted">password set</span>
        <?php else: ?>
          <span class="pill pill--pending">Not set</span>
          <span class="muted">No emails are sent until a mailbox and password are saved.</span>
        <?php endif; ?>
      </dd>
    </dl>
  </section>

  <section class="panel">
    <h2>Update</h2>
    <form method="post" autocomplete="off" class="pwform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="email">

      <label>
        <span>Runner registrations go to</span>
        <input name="admin_email" type="email" required value="<?= h(runner_desk_email()) ?>">
        <small>Gets an alert for every new runner. Runners who reply to their confirmation reach this inbox.</small>
      </label>

      <label>
        <span>Sponsor enquiries go to</span>
        <input name="sponsor_email" type="email" required value="<?= h(sponsor_desk_email()) ?>">
        <small>Gets every "Become a Sponsor" enquiry. Sponsors who reply to their acknowledgement reach this inbox.</small>
      </label>

      <fieldset>
        <label>
          <span>Sending mailbox</span>
          <input name="smtp_user" type="email" required
                 value="<?= h((string) cfg('SMTP_USER', '') !== '' ? (string) cfg('SMTP_USER') : DEFAULT_RUNNER_EMAIL) ?>">
          <small>A mailbox that exists in Hostinger › Emails. Every email is sent from it.</small>
        </label>

        <label>
          <span>Mailbox password</span>
          <input name="smtp_pass" type="password"
                 placeholder="<?= mail_configured() ? 'Leave blank to keep the current one' : 'The password of the mailbox above' ?>">
        </label>

        <div class="pair">
          <label>
            <span>SMTP server</span>
            <input name="smtp_host" value="<?= h((string) cfg('SMTP_HOST', 'smtp.hostinger.com')) ?>">
          </label>
          <label>
            <span>Port</span>
            <input name="smtp_port" type="number" value="<?= (int) cfg('SMTP_PORT', 465) ?>">
          </label>
        </div>
      </fieldset>

      <button type="submit" class="btn btn--primary">Save email settings</button>
    </form>
  </section>

  <section class="panel">
    <h2>Send a test email</h2>
    <form method="post" class="pwform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="test_email">
      <label>
        <span>Send to</span>
        <input name="test_to" type="email" required value="<?= h(runner_desk_email()) ?>">
      </label>
      <button type="submit" class="btn">Send test email</button>
    </form>
  </section>

  <p class="pagesub">
    The previous file is kept as <code>marathon-config.php.bak</code> next to it,
    and a new file is checked for validity before it replaces the working one.
  </p>

  <?php endif; ?>
</main>

</body>
</html>
