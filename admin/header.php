<?php
/** Shared top bar. Included by every signed-in page. */
declare(strict_types=1);

$current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<header class="topbar">
  <div class="topbar__in">
    <a class="topbar__brand" href="index.php">
      <img class="topbar__logo" src="../images/logo-lockup-white.png"
           alt="The Flying Sikh Milkha Singh Legacy Marathon 2026" width="600" height="200" />
      <span class="topbar__name"><span>Registration admin</span></span>
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
