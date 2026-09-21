<?php
/**
 * Refunds & notice: refunds paid entry fees through Razorpay and emails every
 * runner that the event is postponed.
 *
 * Bulk runs are driven one entry at a time from the browser (see the script at
 * the foot), so a few hundred refunds never hit the host's request time limit,
 * and progress is visible and can be stopped.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/cms.php';
require_once __DIR__ . '/../api/refunds.php';

require_admin();
require_can('manage_refunds');

$ready = ensure_refund_columns();
$me    = current_user();
$user  = $me['username'] !== '' ? $me['username'] : 'admin';

/** JSON reply for the page script. */
function reply(array $out): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    // The one-at-a-time calls from the page script.
    if ($action === 'refund') {
        $res = refund_registration($id);
        if ($res['ok'] && $res['status'] !== 'skip' && $res['message'] !== 'Already refunded.') {
            audit('refund_issued', reg_ref($id));
        }
        reply($res);
    }
    if ($action === 'sync') {
        reply(refund_sync($id));
    }
    if ($action === 'notify') {
        $res = notice_send($id);
        if ($res['ok']) {
            audit('notice_sent', reg_ref($id));
        }
        reply($res);
    }

    // Ordinary form posts: post-redirect-get so a refresh cannot repeat them.
    $done = '';
    if ($action === 'manual') {
        if (refund_mark_manual($id)) {
            audit('refund_manual', reg_ref($id));
            $done = 'manual';
        }
    }
    if ($action === 'save_notice' || $action === 'test_notice') {
        $subject = trim(mb_substr((string) ($_POST['subject'] ?? ''), 0, 200));
        $message = trim(mb_substr(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? '')), 0, 5000));
        ensure_cms_tables();
        cms_save_settings([
            'notice_subject' => $subject !== '' ? $subject : null,
            'notice_message' => $message !== '' ? $message : null,
        ], $user);
        audit('notice_saved');
        $done = 'saved';
    }
    if ($action === 'test_notice') {
        $sample = [
            'full_name' => 'Test Runner', 'registration_id' => 'MSLM-TEST', 'category' => 'half',
            'status' => 'paid', 'amount_paise' => 120000, 'refund_status' => 'processed',
            'refund_id' => 'rfnd_TEST123',
        ];
        [$subject, $html] = notice_build($sample, $subject !== '' ? $subject : NOTICE_DEFAULT_SUBJECT,
            $message !== '' ? $message : NOTICE_DEFAULT_MESSAGE);
        $to   = runner_desk_email();
        $done = send_mail($to, 'Registration desk', '[TEST] ' . $subject, $html) ? 'tested' : 'testfail';
    }

    header('Location: refunds.php' . ($done !== '' ? '?done=' . $done : ''));
    exit;
}

// ---- Refund report download ------------------------------------------------
if ($ready && ($_GET['export'] ?? '') === 'csv') {
    require_can('export_csv');
    $st = db()->query(
        "SELECT * FROM registrations
          WHERE status IN ('paid','free','awaiting')
          ORDER BY FIELD(status,'paid','awaiting','free'), created_at"
    );
    audit('export_csv', 'refunds');

    no_store();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="marathon-refunds-' . date('Y-m-d-Hi') . '.csv"');

    $out = fopen('php://output', 'w');
    $csv = static function (array $row) use ($out): void {
        fputcsv($out, $row, ',', '"', '');   // see export.php for why $escape is ''
    };
    fwrite($out, "\xEF\xBB\xBF");          // so Excel reads Indian names correctly

    $csv([
        'Registration ID', 'Full name', 'Email', 'Mobile', 'Category',
        'Entry status', 'Fee paid (INR)', 'Paid through', 'Razorpay payment ID',
        'Refund status', 'Refund amount (INR)', 'Refund ID', 'Refunded at', 'Refund problem',
        'Postponement email sent',
    ]);
    while ($r = $st->fetch()) {
        $paid = $r['status'] === 'paid';
        $csv([
            $r['registration_id'],
            $r['full_name'],
            $r['email'],
            "'" . $r['mobile'],
            cat_label((string) $r['category']),
            status_label((string) $r['status']),
            $paid ? csv_rupees($r['amount_paise']) : '0.00',
            !$paid ? '' : ((string) $r['razorpay_payment_id'] !== '' ? 'Razorpay' : 'Outside Razorpay (cash/UPI)'),
            (string) ($r['razorpay_payment_id'] ?? ''),
            refund_csv_status($r),
            csv_rupees($r['refund_paise'] ?? null),
            (string) ($r['refund_id'] ?? ''),
            (string) ($r['refunded_at'] ?? ''),
            ($r['refund_status'] ?? '') === 'failed' ? (string) ($r['refund_error'] ?? '') : '',
            (string) ($r['notice_sent_at'] ?? 'Not sent'),
        ]);
    }
    fclose($out);
    exit;
}

function reg_ref(int $id): string
{
    $st = db()->prepare('SELECT registration_id FROM registrations WHERE id = ?');
    $st->execute([$id]);
    return (string) $st->fetchColumn();
}

$rows = [];
if ($ready) {
    $rows = db()->query(
        "SELECT * FROM registrations
          WHERE status IN ('paid','free','awaiting')
          ORDER BY FIELD(status,'paid','awaiting','free'), created_at"
    )->fetchAll();
}

// ---- Totals for the header and the bulk buttons ----------------------------
$toRefund = $manualDue = $refunded = $onWay = $failed = [];
$toNotify = [];
$paidTotal = $refundedPaise = 0;
foreach ($rows as $r) {
    $rs = (string) ($r['refund_status'] ?? '');
    if ($r['status'] === 'paid') {
        $paidTotal += (int) $r['amount_paise'];
        if (in_array($rs, ['processed', 'manual'], true)) {
            $refunded[] = (int) $r['id'];
            $refundedPaise += (int) ($r['refund_paise'] ?? $r['amount_paise']);
        } elseif ($rs === 'pending') {
            $onWay[] = (int) $r['id'];
            $refundedPaise += (int) ($r['refund_paise'] ?? $r['amount_paise']);
        } elseif ((string) $r['razorpay_payment_id'] === '') {
            $manualDue[] = (int) $r['id'];
        } else {
            if ($rs === 'failed') {
                $failed[] = (int) $r['id'];
            }
            $toRefund[] = (int) $r['id'];
        }
    }
    if (empty($r['notice_sent_at'])) {
        $toNotify[] = (int) $r['id'];
    }
}
$toRefundPaise = $manualPaise = 0;
foreach ($rows as $r) {
    if (in_array((int) $r['id'], $toRefund, true)) {
        $toRefundPaise += (int) $r['amount_paise'];
    }
    if (in_array((int) $r['id'], $manualDue, true)) {
        $manualPaise += (int) $r['amount_paise'];
    }
}
$paidCount = count(array_filter($rows, static fn($r) => $r['status'] === 'paid'));

$messages = [
    'manual'   => 'Recorded as refunded by hand.',
    'saved'    => 'Email saved.',
    'tested'   => 'A test email went to ' . runner_desk_email() . '. Check that inbox.',
    'testfail' => 'The test email could not be sent. Check the mailbox login under Settings.',
];
$flash     = $messages[(string) ($_GET['done'] ?? '')] ?? '';
$flashBad  = ($_GET['done'] ?? '') === 'testfail';
$subjectNow = (string) site_setting('notice_subject', '');
$messageNow = (string) site_setting('notice_message', '');
[$prevSubject, $prevHtml] = notice_build([
    'full_name' => 'Aman Singh', 'registration_id' => 'MSLM-26-0142', 'category' => 'mini',
    'status' => 'paid', 'amount_paise' => 80000, 'refund_status' => 'processed', 'refund_id' => 'rfnd_Q1a2B3c4D5',
]);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Refunds &amp; notice &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260921-4">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">
  <h1 class="pagetitle">Refunds &amp; postponement notice</h1>
  <p class="muted pagelede">Give every paid runner their money back, then let everyone know the event is postponed. Do the refunds first, so the email can tell each runner their refund is done.</p>

  <?php if ($flash !== ''): ?>
    <p class="alert <?= $flashBad ? 'alert--error' : 'alert--ok' ?>"><?= h($flash) ?></p>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <p class="alert alert--error">The registrations table could not be updated for refunds. Check the database connection under Settings.</p>
  <?php else: ?>

  <section class="stats">
    <div class="stat">
      <span class="stat__n"><?= money($paidTotal) ?></span>
      <span class="stat__l">Collected from <?= $paidCount ?> paid <?= $paidCount === 1 ? 'runner' : 'runners' ?></span>
    </div>
    <div class="stat stat--ok">
      <span class="stat__n"><?= money($refundedPaise) ?></span>
      <span class="stat__l">Refunded (<?= count($refunded) + count($onWay) ?>)</span>
    </div>
    <div class="stat stat--due">
      <span class="stat__n"><?= money($toRefundPaise + $manualPaise) ?></span>
      <span class="stat__l">Still to refund (<?= count($toRefund) + count($manualDue) ?>)</span>
    </div>
    <div class="stat stat--muted">
      <span class="stat__n"><?= count($rows) - count($toNotify) ?> / <?= count($rows) ?></span>
      <span class="stat__l">Runners emailed</span>
    </div>
  </section>

  <div class="steps">

    <section class="panel step">
      <p class="step__n">Step 1</p>
      <h2>Refund entry fees</h2>
      <p>Each paid runner gets their full fee back to the card, UPI or bank account they paid with. Razorpay takes the money from your Razorpay balance, so make sure there is at least <b><?= money($toRefundPaise) ?></b> there first. Banks take 5 to 7 working days to show a refund.</p>

      <?php if ($toRefund): ?>
        <button type="button" class="btn btn--primary btn--block" data-bulk="refund"
                data-ids="<?= h(implode(',', $toRefund)) ?>"
                data-confirm="Refund <?= count($toRefund) ?> <?= count($toRefund) === 1 ? 'runner' : 'runners' ?>, <?= h(money($toRefundPaise)) ?> in total, through Razorpay? This cannot be undone.">
          Refund <?= count($toRefund) ?> <?= count($toRefund) === 1 ? 'runner' : 'runners' ?> &middot; <?= money($toRefundPaise) ?>
        </button>
      <?php else: ?>
        <p class="alert alert--ok">No online payments left to refund.</p>
      <?php endif; ?>

      <?php if ($onWay): ?>
        <button type="button" class="btn btn--block" data-bulk="sync" data-ids="<?= h(implode(',', $onWay)) ?>">
          Check <?= count($onWay) ?> <?= count($onWay) === 1 ? 'refund' : 'refunds' ?> still on the way
        </button>
      <?php endif; ?>

      <?php if ($manualDue): ?>
        <p class="alert alert--warn"><b><?= count($manualDue) ?> paid outside Razorpay</b> (cash or UPI to the team). Razorpay cannot refund those. Pay them back yourself, then press <b>Mark refunded</b> on their row below.</p>
      <?php endif; ?>
      <?php if ($failed): ?>
        <p class="alert alert--error"><b><?= count($failed) ?> <?= count($failed) === 1 ? 'refund' : 'refunds' ?> failed.</b> The reason is on the row below. Fix it (usually a low Razorpay balance) and refund again.</p>
      <?php endif; ?>
    </section>

    <section class="panel step">
      <p class="step__n">Step 2</p>
      <h2>Email the postponement notice</h2>
      <form method="post" class="pwform">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_notice">
        <label>
          <span>Subject</span>
          <input type="text" name="subject" maxlength="200" placeholder="<?= h(NOTICE_DEFAULT_SUBJECT) ?>" value="<?= h($subjectNow) ?>">
        </label>
        <label>
          <span>Message</span>
          <textarea name="message" rows="11" maxlength="5000"><?= h($messageNow !== '' ? $messageNow : NOTICE_DEFAULT_MESSAGE) ?></textarea>
        </label>
        <small>These fill in for each runner: <code>{name}</code> <code>{registration_id}</code> <code>{category}</code> <code>{refund}</code>. <code>{refund}</code> becomes the right sentence for that runner: refunded, refund coming, free entry, or nothing paid yet. Leave a blank line between paragraphs.</small>
        <div class="btnrow">
          <button type="submit" class="btn">Save email</button>
          <button type="submit" class="btn btn--ghost" name="action" value="test_notice">Send a test to <?= h(runner_desk_email()) ?></button>
        </div>
      </form>

      <details class="preview">
        <summary>Preview: a paid runner after their refund</summary>
        <p class="preview__subject"><b>Subject:</b> <?= h($prevSubject) ?></p>
        <iframe class="preview__frame" title="Email preview" sandbox srcdoc="<?= h($prevHtml) ?>"></iframe>
      </details>

      <?php if ($toNotify): ?>
        <button type="button" class="btn btn--primary btn--block" data-bulk="notify"
                data-ids="<?= h(implode(',', $toNotify)) ?>"
                data-confirm="Email <?= count($toNotify) ?> <?= count($toNotify) === 1 ? 'runner' : 'runners' ?> now? Save any changes to the message first.<?= $toRefund ? ' ' . count($toRefund) . ' paid runners are not refunded yet; their email will say the refund is coming.' : '' ?>">
          Email <?= count($toNotify) ?> <?= count($toNotify) === 1 ? 'runner' : 'runners' ?> who have not had it
        </button>
      <?php else: ?>
        <p class="alert alert--ok">Every runner has been emailed.</p>
      <?php endif; ?>
    </section>

  </div>

  <div class="runbar" id="runbar" hidden role="status" aria-live="polite">
    <div class="runbar__track"><div class="runbar__fill" id="runfill"></div></div>
    <p class="runbar__text" id="runtext"></p>
    <button type="button" class="btn btn--sm" id="runstop">Stop</button>
  </div>

  <div class="listhead">
    <h2 class="listtitle">Runners <span class="muted">(<?= count($rows) ?>)</span></h2>
    <?php if ($rows && can('export_csv')): ?>
      <a class="btn btn--primary" href="refunds.php?export=csv">Download refund report (CSV)</a>
    <?php endif; ?>
  </div>
  <?php if (!$rows): ?>
    <p class="empty">No registered runners.</p>
  <?php else: ?>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>Runner</th>
          <th>Category</th>
          <th class="num">Fee</th>
          <th>Refund</th>
          <th>Notice email</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $id  = (int) $r['id'];
          $rs  = (string) ($r['refund_status'] ?? '');
          $pid = (string) ($r['razorpay_payment_id'] ?? '');
      ?>
        <tr id="row-<?= $id ?>">
          <td>
            <a href="view.php?id=<?= $id ?>"><b><?= h($r['full_name']) ?></b></a>
            <span class="sub"><?= h($r['registration_id']) ?> &middot; <?= h($r['email']) ?></span>
          </td>
          <td><?= h(cat_label((string) $r['category'])) ?></td>
          <td class="num nowrap">
            <?= $r['status'] === 'paid' ? money((int) $r['amount_paise']) : '<span class="muted">' . h(status_label((string) $r['status'])) . '</span>' ?>
          </td>
          <td>
            <?php if ($r['status'] !== 'paid'): ?>
              <span class="muted">&mdash;</span>
            <?php else: ?>
              <span class="pill pill--r-<?= h($rs !== '' ? $rs : 'none') ?>"><?= h(refund_label($rs)) ?></span>
              <?php if ($rs === 'failed' && !empty($r['refund_error'])): ?>
                <span class="sub sub--bad"><?= h($r['refund_error']) ?></span>
              <?php elseif (!empty($r['refund_id'])): ?>
                <span class="sub"><?= h($r['refund_id']) ?></span>
              <?php elseif ($pid === '' && $rs === ''): ?>
                <span class="sub">Paid outside Razorpay</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['notice_sent_at'])): ?>
              <span class="pill pill--paid">Sent</span>
              <span class="sub"><?= h(when($r['notice_sent_at'])) ?></span>
            <?php else: ?>
              <span class="pill pill--pending">Not sent</span>
            <?php endif; ?>
          </td>
          <td class="nowrap"><div class="rowacts">
            <?php if ($r['status'] === 'paid' && $pid !== '' && in_array($rs, ['', 'failed', 'requesting'], true)): ?>
              <button type="button" class="btn btn--sm btn--primary" data-one="refund" data-id="<?= $id ?>"
                      data-confirm="Refund <?= h(money((int) $r['amount_paise'])) ?> to <?= h($r['full_name']) ?>?">Refund</button>
            <?php elseif ($r['status'] === 'paid' && $pid === '' && in_array($rs, ['', 'failed'], true)): ?>
              <form method="post" class="inline" onsubmit="return confirm('Have you paid <?= h(money((int) $r['amount_paise'])) ?> back to <?= h($r['full_name']) ?> yourself?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="manual">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn--sm">Mark refunded</button>
              </form>
            <?php elseif ($rs === 'pending'): ?>
              <button type="button" class="btn btn--sm" data-one="sync" data-id="<?= $id ?>">Check</button>
            <?php endif; ?>
            <button type="button" class="btn btn--sm btn--ghost" data-one="notify" data-id="<?= $id ?>"
                    data-confirm="Email the postponement notice to <?= h($r['email']) ?>?"><?= empty($r['notice_sent_at']) ? 'Email' : 'Email again' ?></button>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</main>

<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>;
  var bar = document.getElementById('runbar');
  var fill = document.getElementById('runfill');
  var text = document.getElementById('runtext');
  var stopBtn = document.getElementById('runstop');
  var stopped = false;
  var verbs = { refund: 'Refunding', notify: 'Emailing', sync: 'Checking' };

  function call(action, id) {
    var body = new URLSearchParams({ csrf: csrf, action: action, id: String(id) });
    return fetch('refunds.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, message: 'The server did not answer.' }; });
  }

  function lock(on) {
    document.querySelectorAll('[data-bulk],[data-one]').forEach(function (b) { b.disabled = on; });
  }

  async function run(action, ids) {
    stopped = false;
    lock(true);
    bar.hidden = false;
    stopBtn.hidden = false;
    var done = 0, bad = [];
    for (var i = 0; i < ids.length; i++) {
      if (stopped) break;
      text.textContent = verbs[action] + ' ' + (i + 1) + ' of ' + ids.length + '…';
      var res = await call(action, ids[i]);
      if (res.ok) { done++; } else { bad.push(res.message || 'Failed'); }
      fill.style.width = Math.round(((i + 1) / ids.length) * 100) + '%';
      // A mailbox or balance problem will fail every row the same way: stop
      // after three in a row instead of burning through the whole list.
      if (bad.length >= 3 && done === 0) { stopped = true; }
    }
    stopBtn.hidden = true;
    text.textContent = (stopped ? 'Stopped. ' : 'Finished. ') + done + ' done'
      + (bad.length ? ', ' + bad.length + ' failed: ' + bad[bad.length - 1].replace(/\.$/, '') : '') + '. Reloading…';
    setTimeout(function () { location.reload(); }, bad.length ? 4000 : 1200);
  }

  stopBtn.addEventListener('click', function () { stopped = true; stopBtn.disabled = true; });

  document.querySelectorAll('[data-bulk]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
      var ids = b.dataset.ids.split(',').filter(Boolean);
      if (ids.length) run(b.dataset.bulk, ids);
    });
  });
  document.querySelectorAll('[data-one]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
      run(b.dataset.one, [b.dataset.id]);
    });
  });
})();
</script>
</body>
</html>
