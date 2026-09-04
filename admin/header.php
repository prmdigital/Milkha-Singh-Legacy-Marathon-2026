<?php
/** Shared top bar. Included by every signed-in page. */
declare(strict_types=1);

$current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$me      = current_user();
?>
<header class="topbar">
  <div class="topbar__in">
    <a class="topbar__brand" href="index.php">
      <img class="topbar__logo" src="../images/logo-lockup-white.png"
           alt="The Flying Sikh Milkha Singh Legacy Marathon 2026" width="600" height="200" />
    </a>

    <nav class="topbar__nav">
      <a href="index.php" <?= $current === 'index.php' ? 'aria-current="page"' : '' ?>>Registrations</a>

      <?php if (can('view_audit')): ?>
        <a href="audit.php" <?= $current === 'audit.php' ? 'aria-current="page"' : '' ?>>Activity</a>
      <?php endif; ?>

      <?php if (can('manage_users')): ?>
        <a href="users.php" <?= $current === 'users.php' ? 'aria-current="page"' : '' ?>>Users</a>
      <?php endif; ?>

      <?php if (can('manage_settings')): ?>
        <a href="settings.php" <?= $current === 'settings.php' ? 'aria-current="page"' : '' ?>>Settings</a>
      <?php endif; ?>
    </nav>

    <div class="topbar__user">
      <span class="topbar__who">
        <b><?= h($me['name'] !== '' ? $me['name'] : $me['username']) ?></b>
        <span><?= h(role_label($me['role'])) ?></span>
      </span>
      <a class="btn btn--sm btn--ghost" href="password.php">Password</a>
      <a class="btn btn--sm btn--ghost" href="logout.php">Sign out</a>
    </div>
  </div>
</header>
