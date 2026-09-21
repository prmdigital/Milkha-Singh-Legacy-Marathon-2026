<?php
/**
 * Admin users: list, create, edit, reset password, activate and deactivate.
 *
 * Owners only. Accounts are deactivated rather than deleted, because an audit
 * entry pointing at a user who no longer exists tells you nothing at the moment
 * you most need it to.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';

require_admin();
require_can('manage_users');

// Creates the table on a site set up before it existed; that missing table is
// what made this page return a 500.
$tableReady = ensure_admin_users_table();

$me      = current_user();
$flash   = '';
$errors  = [];
$editing = null;

/**
 * Active accounts that can manage users (Admin or Administrator). Deactivating
 * or demoting the last of them would leave nobody able to add users.
 */
function active_owner_count(?int $excludingId = null): int
{
    $sql = "SELECT COUNT(*) FROM admin_users WHERE role IN ('owner','administrator') AND is_active = 1";
    $params = [];
    if ($excludingId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludingId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function valid_username(string $u): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,40}$/i', $u);
}

/** The role of an existing account, or '' when there is no such account. */
function role_of(int $id): string
{
    $st = db()->prepare('SELECT role FROM admin_users WHERE id = ?');
    $st->execute([$id]);
    return (string) ($st->fetchColumn() ?: '');
}

/** An Admin account can only be changed by an Admin, not by an Administrator. */
function may_modify(int $id): bool
{
    return role_of($id) !== 'owner' || current_role() === 'owner';
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

if ($tableReady && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    // ---- Create -----------------------------------------------------------
    if ($action === 'create') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $role     = (string) ($_POST['role'] ?? 'editor');
        $password = (string) ($_POST['password'] ?? '');

        if (!valid_username($username)) {
            $errors[] = 'Username must be 3 to 40 characters: letters, numbers, dot, dash or underscore.';
        }
        if ($fullName === '') {
            $errors[] = 'Enter the person\'s name.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address does not look right.';
        }
        if (!in_array($role, ASSIGNABLE_ROLES, true)) {
            $errors[] = 'Choose a role: Administrator, Manager or Editor.';
        }
        // The setup login stops working the moment any account exists, so a
        // first account that cannot manage users would lock everyone out of
        // this page.
        elseif (!has_db_users() && !role_manages_users($role)) {
            $errors[] = 'The first account must be an Administrator, because the setup login stops working once it exists. Add your own Administrator account first.';
        }
        if (strlen($password) < 12) {
            $errors[] = 'The password must be at least 12 characters.';
        }

        if (!$errors) {
            try {
                db()->prepare(
                    'INSERT INTO admin_users
                        (username, full_name, email, password_hash, role, is_active, must_change, created_by)
                     VALUES (?, ?, ?, ?, ?, 1, 1, ?)'
                )->execute([
                    $username, $fullName, $email !== '' ? $email : null,
                    password_hash($password, PASSWORD_DEFAULT), $role,
                    $me['id'] > 0 ? $me['id'] : null,
                ]);
                audit('user_created', $username);
                $flash = 'Added ' . $username . '. They will be asked to set their own password when they first sign in.';
            } catch (Throwable $e) {
                $errors[] = str_contains($e->getMessage(), 'uniq_username') || str_contains($e->getMessage(), 'UNIQUE')
                    ? 'That username is already taken.'
                    : 'Could not add that user.';
                if (!str_contains($e->getMessage(), 'uniq')) {
                    error_log('[marathon-admin] create user: ' . $e->getMessage());
                }
            }
        }
    }

    // ---- Change role ------------------------------------------------------
    if ($action === 'set_role' && $id > 0) {
        $role = (string) ($_POST['role'] ?? '');
        if (!in_array($role, ASSIGNABLE_ROLES, true)) {
            $errors[] = 'Unknown role.';
        } elseif (!may_modify($id)) {
            $errors[] = 'Only the Admin account can change another Admin.';
        } elseif ($id === $me['id'] && !role_manages_users($role)) {
            $errors[] = 'You cannot take away your own access to manage users.';
        } elseif (!role_manages_users($role) && active_owner_count($id) === 0) {
            $errors[] = 'That is the last Administrator. Make someone else an Administrator first.';
        } else {
            db()->prepare('UPDATE admin_users SET role = ? WHERE id = ?')->execute([$role, $id]);
            audit('user_role_changed', 'id ' . $id . ' -> ' . $role);
            $flash = 'Role updated.';
        }
    }

    // ---- Activate / deactivate -------------------------------------------
    if ($action === 'set_active' && $id > 0) {
        $active = (int) ($_POST['active'] ?? 0) === 1;
        if ($id === $me['id'] && !$active) {
            $errors[] = 'You cannot deactivate your own account.';
        } elseif (!may_modify($id)) {
            $errors[] = 'Only the Admin account can change another Admin.';
        } elseif (!$active && role_manages_users(role_of($id)) && active_owner_count($id) === 0) {
            $errors[] = 'That is the last active Administrator. Make someone else an Administrator first.';
        } else {
            db()->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?')
                ->execute([$active ? 1 : 0, $id]);
            audit($active ? 'user_activated' : 'user_deactivated', 'id ' . $id);
            $flash = $active ? 'Account reactivated.' : 'Account deactivated. They can no longer sign in.';
        }
    }

    // ---- Reset password ---------------------------------------------------
    if ($action === 'reset_password' && $id > 0) {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 12) {
            $errors[] = 'The password must be at least 12 characters.';
        } elseif (!may_modify($id)) {
            $errors[] = 'Only the Admin account can change another Admin.';
        } else {
            db()->prepare('UPDATE admin_users SET password_hash = ?, must_change = 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            audit('user_password_reset', 'id ' . $id);
            $flash = 'Password reset. They will be asked to choose their own when they next sign in.';
        }
    }

    if (!$errors) {
        // Post-redirect-get, so a refresh does not replay the action.
        header('Location: users.php' . ($flash !== '' ? '?done=' . urlencode($flash) : ''));
        exit;
    }
}

if (isset($_GET['done'])) {
    $flash = (string) $_GET['done'];
}

$users = $tableReady
    ? db()->query('SELECT * FROM admin_users ORDER BY is_active DESC, role ASC, username ASC')->fetchAll()
    : [];

$bootstrapOnly = !has_db_users();
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Users &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260921-3">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">
  <h1 class="pagetitle">Admin users</h1>
  <p class="pagesub">
    Who can sign in, and what each of them is allowed to do.
  </p>

  <?php if ($flash !== ''): ?>
    <p class="alert alert--ok"><?= h($flash) ?></p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert--error">
      <?php foreach ($errors as $e): ?>
        <div><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$tableReady): ?>

    <div class="panel">
      <h2>One step left</h2>
      <p>
        The <code>admin_users</code> table does not exist yet, and the database would not
        let this page create it. Open phpMyAdmin, select the marathon database, go to the
        <strong>SQL</strong> tab, run this, then reload this page.
      </p>
      <pre class="sqlblock"><code>CREATE TABLE IF NOT EXISTS admin_users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</code></pre>
    </div>

  <?php else: ?>

  <?php if ($bootstrapOnly): ?>
    <p class="alert alert--warn">
      You are signed in with the setup login from the configuration file.
      Add <b>your own Administrator account</b> first. As soon as any account exists,
      that setup login stops working, so Manager and Editor unlock after the first
      Administrator is added.
    </p>
  <?php endif; ?>

  <section class="panel panel--wide">
    <h2>Add a user</h2>
    <form method="post" class="userform" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <div class="userform__grid">
        <label>
          <span>Username</span>
          <input name="username" required maxlength="40" autocapitalize="none" placeholder="e.g. priya">
        </label>
        <label>
          <span>Full name</span>
          <input name="full_name" required maxlength="120" placeholder="e.g. Priya Sharma">
        </label>
        <label>
          <span>Email <em>(optional)</em></span>
          <input name="email" type="email" maxlength="190">
        </label>
        <label>
          <span>Role</span>
          <select name="role" required>
            <?php foreach (ASSIGNABLE_ROLES as $key): ?>
              <?php $locked = $bootstrapOnly && !role_manages_users($key); ?>
              <option value="<?= h($key) ?>"
                      <?= $key === ($bootstrapOnly ? 'administrator' : 'editor') ? 'selected' : '' ?>
                      <?= $locked ? 'disabled' : '' ?>>
                <?= h(ROLE_LABELS[$key]) ?><?= $locked ? ' (add an Administrator first)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Temporary password</span>
          <input name="password" type="password" required minlength="12">
        </label>
        <div class="userform__submit">
          <button type="submit" class="btn btn--primary">Add user</button>
        </div>
      </div>

      <ul class="rolekey">
        <?php foreach (ROLE_DESCRIPTIONS as $key => $desc): ?>
          <li><b><?= h(ROLE_LABELS[$key]) ?></b> <?= h($desc) ?></li>
        <?php endforeach; ?>
      </ul>
    </form>
  </section>

  <?php if (!$users): ?>
    <p class="empty">No admin users yet. Add the first one above.</p>
  <?php else: ?>

  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>User</th><th>Role</th><th>Status</th><th>Last signed in</th><th>Added</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr<?= (int) $u['is_active'] === 0 ? ' class="is-off"' : '' ?>>
          <td>
            <b><?= h($u['full_name']) ?></b>
            <span class="sub"><?= h($u['username']) ?><?= $u['email'] ? ' &middot; ' . h($u['email']) : '' ?></span>
            <?php if ((int) $u['must_change'] === 1): ?>
              <span class="sub">must set a new password</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="set_role">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <?php if (!in_array($u['role'], ASSIGNABLE_ROLES, true)): ?>
                <b><?= h(role_label((string) $u['role'])) ?></b>
              <?php else: ?>
              <select name="role" onchange="this.form.submit()" <?= (int) $u['id'] === $me['id'] ? 'disabled' : '' ?>>
                <?php foreach (ASSIGNABLE_ROLES as $key): ?>
                  <option value="<?= h($key) ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= h(ROLE_LABELS[$key]) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </form>
          </td>
          <td>
            <span class="pill pill--<?= (int) $u['is_active'] === 1 ? 'paid' : 'pending' ?>">
              <?= (int) $u['is_active'] === 1 ? 'Active' : 'Deactivated' ?>
            </span>
          </td>
          <td class="nowrap"><?= h(when($u['last_login_at'])) ?></td>
          <td class="nowrap"><?= h(when($u['created_at'])) ?></td>
          <td class="nowrap">
            <details class="rowmenu">
              <summary class="btn btn--sm">Manage</summary>
              <div class="rowmenu__body">
                <form method="post" autocomplete="off">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <label class="rowmenu__label">New password</label>
                  <input name="password" type="password" minlength="12" required placeholder="12+ characters">
                  <button type="submit" class="btn btn--sm">Reset password</button>
                </form>

                <?php if ((int) $u['id'] !== $me['id']): ?>
                  <form method="post" class="rowmenu__toggle">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="set_active">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input type="hidden" name="active" value="<?= (int) $u['is_active'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="btn btn--sm">
                      <?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>

  <?php endif; /* $tableReady */ ?>
</main>

</body>
</html>
