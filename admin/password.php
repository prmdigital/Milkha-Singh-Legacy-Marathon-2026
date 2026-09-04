<?php
/**
 * Lets a signed-in user change their own password.
 *
 * Reached voluntarily, or forced with ?first=1 after an owner has reset the
 * account: a temporary password somebody else chose is one they still know.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';

require_admin();

$me    = current_user();
$first = isset($_GET['first']);
$error = '';
$done  = false;

// The config bootstrap login has no database row to update.
$isBootstrap = $me['id'] === 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$isBootstrap) {
    csrf_check();

    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    $st = db()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
    $st->execute([$me['id']]);
    $hash = (string) $st->fetchColumn();

    if (!password_verify($current, $hash)) {
        $error = 'That is not your current password.';
    } elseif (strlen($new) < 12) {
        $error = 'The new password must be at least 12 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The two new passwords do not match.';
    } elseif ($new === $current) {
        $error = 'Choose a password different from the current one.';
    } else {
        db()->prepare('UPDATE admin_users SET password_hash = ?, must_change = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        audit('password_changed', $me['username']);
        $done = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Your password &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260904-6">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap wrap--narrow">
  <h1 class="pagetitle">Your password</h1>

  <?php if ($isBootstrap): ?>

    <p class="pagesub">
      You are signed in with the setup credential from the configuration file
      rather than a user account, so there is nothing here to change.
    </p>
    <p class="alert alert--warn">
      Go to <a href="users.php">Users</a> and create a real owner account. Once
      one exists, the configuration file's login stops working.
    </p>

  <?php elseif ($done): ?>

    <p class="alert alert--ok">Password changed.</p>
    <p><a class="btn btn--primary" href="index.php">Back to registrations</a></p>

  <?php else: ?>

    <?php if ($first): ?>
      <p class="alert alert--warn">
        Your password was set for you by an owner, who still knows it. Choose
        your own before you carry on.
      </p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <p class="alert alert--error"><?= h($error) ?></p>
    <?php endif; ?>

    <section class="panel">
      <form method="post" autocomplete="off" class="pwform">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <label>
          <span>Current password</span>
          <input name="current" type="password" required autocomplete="current-password">
        </label>
        <label>
          <span>New password</span>
          <input name="new" type="password" required minlength="12" autocomplete="new-password">
          <small>At least 12 characters.</small>
        </label>
        <label>
          <span>Repeat new password</span>
          <input name="confirm" type="password" required minlength="12" autocomplete="new-password">
        </label>

        <button type="submit" class="btn btn--primary">Change password</button>
      </form>
    </section>

  <?php endif; ?>
</main>

</body>
</html>
