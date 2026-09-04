<?php
/**
 * One-time helper: turns a password you type into a hash to paste into
 * marathon-config.php.
 *
 * It disables ITSELF the moment ADMIN_PASSWORD_HASH is filled in, so it cannot
 * be left lying around as an open page. Delete the file afterwards anyway.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';

no_store();

if ((string) cfg('ADMIN_PASSWORD_HASH', '') !== '') {
    http_response_code(404);
    exit('Not found.');
}

$hash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pw = (string) ($_POST['password'] ?? '');
    if (strlen($pw) < 12) {
        $hash = 'Use at least 12 characters.';
    } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Create admin password</title>
<link rel="stylesheet" href="assets/admin.css?v=20260904-5">
</head>
<body class="login-body">
<main class="login">
  <h1>Create the admin password</h1>
  <p class="login__note">
    Type a password, copy the hash it gives you into <code>marathon-config.php</code>
    as <code>ADMIN_PASSWORD_HASH</code>, then delete this file.
  </p>

  <form method="post" autocomplete="off">
    <label for="password">New password (12+ characters)</label>
    <input type="password" id="password" name="password" required autofocus>
    <button type="submit" class="btn btn--primary">Generate hash</button>
  </form>

  <?php if ($hash !== ''): ?>
    <p class="alert">Paste this value:</p>
    <textarea readonly rows="3" class="hashout"><?= h($hash) ?></textarea>
  <?php endif; ?>
</main>
</body>
</html>
