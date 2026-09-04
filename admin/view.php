<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

/* ---- Mark an offline payment as collected -------------------------------
   Post-redirect-get: without the redirect a browser refresh would replay the
   action, and the audit log would fill with duplicates of the same collection. */
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'mark_paid') {
        require_can('mark_paid');

        // Only an entry that is genuinely awaiting collection may be flipped, so
        // this can never overwrite a real gateway payment or a free entry.
        $upd = db()->prepare(
            'UPDATE registrations
                SET status = "paid", paid_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status = "awaiting"'
        );
        $upd->execute([$id]);

        if ($upd->rowCount() > 0) {
            $ref = db()->prepare('SELECT registration_id FROM registrations WHERE id = ?');
            $ref->execute([$id]);
            audit('mark_paid', (string) $ref->fetchColumn());
            $flash = 'paid';
        } else {
            $flash = 'nochange';
        }
    }

    header('Location: view.php?id=' . $id . ($flash !== '' ? '&done=' . $flash : ''));
    exit;
}

$st = db()->prepare('SELECT * FROM registrations WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();

if (!$r) {
    http_response_code(404);
    $notFound = true;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= isset($notFound) ? 'Not found' : h($r['full_name']) ?> &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260904-6">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">

<?php if (isset($notFound)): ?>

  <p class="empty">That registration does not exist. <a href="index.php">Back to the list</a>.</p>

<?php else: ?>

  <p class="crumb"><a href="index.php">&larr; All registrations</a></p>

  <header class="rec__head">
    <div>
      <h1><?= h($r['full_name']) ?></h1>
      <p class="rec__meta">
        <code><?= h($r['registration_id']) ?></code>
        &middot; registered <?= h(when($r['created_at'])) ?>
      </p>
    </div>
    <span class="pill pill--lg pill--<?= h($r['status']) ?>"><?= h(status_label((string) $r['status'])) ?></span>
  </header>

  <div class="rec">

    <section class="panel">
      <h2>Runner</h2>
      <dl class="dl">
        <dt>Full name</dt><dd><?= h($r['full_name']) ?></dd>
        <dt>Email</dt><dd><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></dd>
        <dt>Mobile</dt><dd><a href="tel:+91<?= h($r['mobile']) ?>"><?= h($r['mobile']) ?></a></dd>
        <dt>Date of birth</dt>
        <dd><?= $r['dob'] ? h(date('j M Y', strtotime((string) $r['dob']))) : '<span class="muted">Not recorded</span>' ?></dd>
        <dt>Age on race day</dt><dd><?= h($r['age']) ?></dd>
        <dt>Gender</dt><dd><?= h($r['gender']) ?></dd>
        <dt>City</dt><dd><?= h($r['city']) ?></dd>
        <dt>T-shirt size</dt><dd><?= h($r['tshirt_size']) ?></dd>
      </dl>
    </section>

    <section class="panel">
      <h2>Race</h2>
      <dl class="dl">
        <dt>Category</dt><dd><?= h(cat_label((string) $r['category'])) ?></dd>
        <dt>Distance</dt><dd><?= h(CATEGORIES[$r['category']]['distance'] ?? '-') ?></dd>
        <dt>Assembly / flag-off</dt><dd><?= h(RACE_TIMES[$r['category']] ?? '—') ?></dd>
      </dl>

      <h2>Emergency contact</h2>
      <dl class="dl">
        <dt>Phone</dt>
        <dd><?= $r['emergency_phone'] ? h($r['emergency_phone']) : '<span class="muted">Not given</span>' ?></dd>
      </dl>
    </section>

    <section class="panel">
      <h2>Payment</h2>
      <dl class="dl">
        <dt>Amount</dt>
        <dd>
          <strong><?= money((int) $r['amount_paise']) ?></strong>
          <?php if ((int) $r['early_bird'] === 1): ?>
            <span class="muted">(early bird, <?= EARLY_BIRD_PERCENT ?>% off)</span>
          <?php endif; ?>
        </dd>
        <dt>Status</dt><dd><span class="pill pill--<?= h($r['status']) ?>"><?= h(status_label((string) $r['status'])) ?></span></dd>
        <dt>Paid at</dt><dd><?= h(when($r['paid_at'])) ?></dd>
        <dt>Razorpay order</dt>
        <dd><?= $r['razorpay_order_id'] ? '<code>' . h($r['razorpay_order_id']) . '</code>' : '<span class="muted">&mdash;</span>' ?></dd>
        <dt>Razorpay payment</dt>
        <dd><?= $r['razorpay_payment_id'] ? '<code>' . h($r['razorpay_payment_id']) . '</code>' : '<span class="muted">&mdash;</span>' ?></dd>
        <dt>Receipt emailed</dt>
        <dd><?= ((int) $r['receipt_emailed'] === 1) ? 'Yes' : 'No' ?></dd>
      </dl>

      <?php if (($_GET['done'] ?? '') === 'paid'): ?>
        <p class="alert alert--ok">Marked as paid.</p>
      <?php endif; ?>

      <?php if ($r['status'] === 'awaiting'): ?>
        <p class="alert alert--warn">
          <b><?= money((int) $r['amount_paise']) ?> still to collect.</b>
          This runner registered while online payment was switched off. Call
          <?= h($r['mobile']) ?> to take the fee, then record it here.
        </p>
        <?php if (can('mark_paid')): ?>
        <form method="post" class="markpaid"
              onsubmit="return confirm('Record <?= h(money((int) $r['amount_paise'])) ?> as received from <?= h($r['full_name']) ?>?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="mark_paid">
          <button type="submit" class="btn btn--primary">
            Mark <?= money((int) $r['amount_paise']) ?> as received
          </button>
        </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($r['status'] === 'pending'): ?>
        <p class="alert alert--warn">
          This runner reached the payment page but no confirmed payment came back.
          Check the Razorpay dashboard before treating them as registered.
        </p>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>ID proof</h2>
      <dl class="dl">
        <dt>Type</dt><dd><?= h($r['id_proof_type']) ?></dd>
      </dl>

      <?php if ($r['id_proof_file'] && !can('view_id_proof')): ?>
        <p class="muted">A document is on file. Your role cannot open ID proofs.</p>
      <?php elseif ($r['id_proof_file']): ?>
        <p class="idnote">
          The document is stored outside the website folder and is only served
          through this panel. Opening it is recorded under Activity.
        </p>
        <p>
          <a class="btn" target="_blank" rel="noopener"
             href="id-proof.php?id=<?= (int) $r['id'] ?>">Open ID proof</a>
          <a class="btn btn--ghost"
             href="id-proof.php?id=<?= (int) $r['id'] ?>&amp;download=1">Download</a>
        </p>
      <?php else: ?>
        <p class="muted">No file was uploaded with this registration.</p>
      <?php endif; ?>
    </section>

    <section class="panel panel--wide">
      <h2>Technical</h2>
      <dl class="dl dl--inline">
        <dt>Record</dt><dd>#<?= (int) $r['id'] ?></dd>
        <dt>IP address</dt><dd><?= h($r['ip_address'] ?: '—') ?></dd>
        <dt>Created</dt><dd><?= h(when($r['created_at'])) ?></dd>
      </dl>
    </section>

  </div>

<?php endif; ?>

</main>

</body>
</html>
