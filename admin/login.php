<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

admin_boot();
no_store();

if (admin_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$ip    = client_ip();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $user = (string) ($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');

    if (!has_db_users() && (string) cfg('ADMIN_PASSWORD_HASH', '') === '') {
        $error = 'No admin account exists yet. Run setup.php to create one.';
    } elseif (login_blocked($ip)) {
        $error = 'Too many failed attempts. Try again in ' . LOGIN_WINDOW_MIN . ' minutes.';
    } else {
        $account = authenticate($user, $pass);

        if ($account !== null) {
            login_record($ip, true, $user);
            session_regenerate_id(true);   // stops session fixation

            $_SESSION['admin_ok']   = true;
            $_SESSION['admin_id']   = (int) ($account['id'] ?? 0);
            $_SESSION['admin_user'] = (string) $account['username'];
            $_SESSION['admin_name'] = (string) ($account['full_name'] ?? $account['username']);
            $_SESSION['admin_role'] = (string) ($account['role'] ?? 'owner');

            if ((int) ($account['id'] ?? 0) > 0) {
                try {
                    db()->prepare('UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')
                        ->execute([(int) $account['id']]);
                } catch (Throwable $e) {
                    error_log('[marathon-admin] last_login_at: ' . $e->getMessage());
                }
            }

            audit('login', $user);

            // An owner-set temporary password must be replaced before the
            // account is any use, otherwise whoever set it still knows it.
            if ((int) ($account['must_change'] ?? 0) === 1) {
                header('Location: password.php?first=1');
                exit;
            }

            header('Location: index.php');
            exit;
        }

        login_record($ip, false, $user);
        $error = 'Wrong username or password.';
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260905-6">
</head>
<body class="login-body">

<main class="login">
  <div class="login__brand">
    <img class="login__logo" src="../images/logo-lockup.jpg"
         alt="The Flying Sikh Milkha Singh Legacy Marathon 2026" width="600" height="200" />
    <span>Registration admin</span>
  </div>

  <?php if ($error !== ''): ?>
    <p class="alert alert--error"><?= h($error) ?></p>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

    <label for="username">Username</label>
    <input type="text" id="username" name="username" required autofocus
           autocapitalize="none" autocomplete="username">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required
           autocomplete="current-password">

    <button type="submit" class="btn btn--primary">Sign in</button>
  </form>

  <p class="login__note">Authorised staff only. Attempts are logged.</p>
</main>

</body>
</html>
