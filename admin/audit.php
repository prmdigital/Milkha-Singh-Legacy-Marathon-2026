<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

require_admin();

$rows = db()->query(
    'SELECT * FROM admin_audit ORDER BY created_at DESC, id DESC LIMIT 200'
)->fetchAll();

$labels = [
    'login'         => 'Signed in',
    'view_id_proof' => 'Opened an ID proof',
    'export_csv'    => 'Downloaded a CSV',
    'mark_paid'     => 'Recorded a payment as received',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Activity &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260903-5">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">
  <h1 class="pagetitle">Activity</h1>
  <p class="pagesub">
    The last 200 admin actions. ID proofs are identity documents, so every time
    one is opened it is recorded here.
  </p>

  <?php if (!$rows): ?>
    <p class="empty">Nothing recorded yet.</p>
  <?php else: ?>
    <div class="tablewrap">
      <table class="table">
        <thead>
          <tr><th>When</th><th>Action</th><th>Record</th><th>IP address</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $a): ?>
            <tr>
              <td class="nowrap"><?= h(when($a['created_at'])) ?></td>
              <td><?= h($labels[$a['action']] ?? $a['action']) ?></td>
              <td><?= $a['subject'] ? '<code>' . h($a['subject']) . '</code>' : '<span class="muted">&mdash;</span>' ?></td>
              <td class="nowrap"><?= h($a['ip_address'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

</body>
</html>
