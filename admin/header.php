<?php
/** Shared top bar. Included by every signed-in page. */
declare(strict_types=1);

$current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<header class="topbar">
  <div class="topbar__in">
    <a class="topbar__brand" href="index.php">
      <span class="topbar__mark">MS</span>
      <span class="topbar__name">
        <strong>Legacy Marathon 2026</strong>
        <span>Registration admin</span>
      </span>
    </a>

    <nav class="topbar__nav">
      <a href="index.php" <?= $current === 'index.php' ? 'aria-current="page"' : '' ?>>Registrations</a>
      <a href="audit.php" <?= $current === 'audit.php' ? 'aria-current="page"' : '' ?>>Activity</a>
    </nav>

    <div class="topbar__user">
      <span><?= h($_SESSION['admin_user'] ?? 'admin') ?></span>
      <a class="btn btn--sm btn--ghost" href="logout.php">Sign out</a>
    </div>
  </div>
</header>
