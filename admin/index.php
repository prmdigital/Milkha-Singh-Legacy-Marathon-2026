<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/query.php';

require_admin();

$pdo = db();

// ---- Headline numbers ------------------------------------------------------
// Only confirmed money is counted: a 'pending' row is someone who reached
// Razorpay, not someone who paid.
$stats = $pdo->query(
    "SELECT
        COUNT(*)                                                AS total,
        SUM(status IN ('paid','free','awaiting'))               AS confirmed,
        SUM(status = 'awaiting')                                AS awaiting,
        SUM(status = 'pending')                                 AS pending,
        SUM(status = 'failed')                                  AS failed,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_paise ELSE 0 END), 0) AS collected,
        COALESCE(SUM(CASE WHEN status = 'awaiting' THEN amount_paise ELSE 0 END), 0) AS outstanding
     FROM registrations"
)->fetch();

$byCat = $pdo->query(
    "SELECT category, COUNT(*) AS n
       FROM registrations
      WHERE status IN ('paid','free','awaiting')
      GROUP BY category"
)->fetchAll();

$catCounts = [];
foreach ($byCat as $r) {
    $catCounts[$r['category']] = (int) $r['n'];
}

// ---- Filtered list ---------------------------------------------------------
[$where, $params] = reg_filters($_GET);

$countSt = $pdo->prepare('SELECT COUNT(*) FROM registrations' . $where);
$countSt->execute($params);
$found = (int) $countSt->fetchColumn();

$perPage = 50;
$pages   = max(1, (int) ceil($found / $perPage));
$page    = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$offset  = ($page - 1) * $perPage;

// LIMIT/OFFSET are integers we computed, never raw input.
$sql = 'SELECT * FROM registrations' . $where . reg_sort($_GET)
     . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

$listSt = $pdo->prepare($sql);
$listSt->execute($params);
$rows = $listSt->fetchAll();

$flip = (strtolower((string) ($_GET['dir'] ?? 'desc')) === 'desc') ? 'asc' : 'desc';
$filtered = has_filters();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Registrations &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260903-1">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">

  <section class="stats">
    <div class="stat">
      <span class="stat__n"><?= (int) $stats['confirmed'] ?></span>
      <span class="stat__l">Registered runners</span>
    </div>
    <div class="stat">
      <span class="stat__n"><?= money((int) $stats['collected']) ?></span>
      <span class="stat__l">Collected</span>
    </div>
    <div class="stat stat--due">
      <span class="stat__n"><?= money((int) $stats['outstanding']) ?></span>
      <span class="stat__l">To collect</span>
    </div>
    <div class="stat stat--due">
      <span class="stat__n"><?= (int) $stats['awaiting'] ?></span>
      <span class="stat__l">Awaiting payment</span>
    </div>
    <div class="stat stat--muted">
      <span class="stat__n"><?= (int) $stats['total'] ?></span>
      <span class="stat__l">Total records</span>
    </div>
  </section>

  <section class="catbar">
    <?php foreach (CATEGORIES as $key => $c): ?>
      <a class="catbar__item" href="<?= h(link_with(['category' => $key, 'page' => null])) ?>">
        <b><?= (int) ($catCounts[$key] ?? 0) ?></b>
        <span><?= h($c['label']) ?> &middot; <?= h($c['distance']) ?></span>
      </a>
    <?php endforeach; ?>
  </section>

  <form class="filters" method="get" action="index.php">
    <div class="filters__row">
      <label class="filters__search">
        <span class="sr-only">Search registrations</span>
        <input type="search" name="q" placeholder="Name, email, mobile or registration ID"
               value="<?= h($_GET['q'] ?? '') ?>">
      </label>

      <label>
        <span class="sr-only">Category</span>
        <select name="category">
          <option value="">All categories</option>
          <?php foreach (CATEGORIES as $key => $c): ?>
            <option value="<?= h($key) ?>" <?= ($_GET['category'] ?? '') === $key ? 'selected' : '' ?>>
              <?= h($c['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        <span class="sr-only">Payment status</span>
        <select name="status">
          <option value="">Any status</option>
          <?php
          $statuses = [
              'awaiting' => 'Awaiting payment',
              'paid'     => 'Paid',
              'free'     => 'Free entry',
              'pending'  => 'Abandoned checkout',
              'failed'   => 'Failed',
          ];
          foreach ($statuses as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="filters__date">
        <span>From</span>
        <input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>">
      </label>
      <label class="filters__date">
        <span>To</span>
        <input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>">
      </label>

      <button type="submit" class="btn">Apply</button>
      <?php if ($filtered): ?>
        <a class="btn btn--ghost" href="index.php">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="listhead">
    <p>
      <strong><?= $found ?></strong> <?= $found === 1 ? 'registration' : 'registrations' ?><?= $filtered ? ' matching your filters' : '' ?>
    </p>
    <?php if ($found > 0): ?>
      <a class="btn btn--primary" href="<?= h(link_with(['page' => null], 'export.php')) ?>">Download CSV</a>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>

    <p class="empty">
      <?= $filtered
          ? 'No registrations match those filters.'
          : 'No registrations yet. They will appear here the moment the first runner signs up.' ?>
    </p>

  <?php else: ?>

    <div class="tablewrap">
      <table class="table">
        <thead>
          <tr>
            <th><a href="<?= h(link_with(['sort' => 'created_at', 'dir' => $flip])) ?>">Date</a></th>
            <th><a href="<?= h(link_with(['sort' => 'name', 'dir' => $flip])) ?>">Runner</a></th>
            <th>Contact</th>
            <th><a href="<?= h(link_with(['sort' => 'category', 'dir' => $flip])) ?>">Category</a></th>
            <th>Kit</th>
            <th class="num"><a href="<?= h(link_with(['sort' => 'amount', 'dir' => $flip])) ?>">Amount</a></th>
            <th><a href="<?= h(link_with(['sort' => 'status', 'dir' => $flip])) ?>">Status</a></th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= h(when($r['created_at'])) ?></td>
            <td>
              <b><?= h($r['full_name']) ?></b>
              <span class="sub"><?= h($r['registration_id']) ?></span>
            </td>
            <td>
              <a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a>
              <span class="sub"><?= h($r['mobile']) ?></span>
            </td>
            <td>
              <?= h(cat_label($r['category'])) ?>
              <span class="sub"><?= h($r['age']) ?> yrs &middot; <?= h($r['gender']) ?> &middot; <?= h($r['city']) ?></span>
            </td>
            <td class="nowrap"><?= h($r['tshirt_size']) ?></td>
            <td class="num">
              <?= money((int) $r['amount_paise']) ?>
              <?php if ((int) $r['early_bird'] === 1): ?><span class="sub">early bird</span><?php endif; ?>
            </td>
            <td><span class="pill pill--<?= h($r['status']) ?>"><?= h(status_label((string) $r['status'])) ?></span></td>
            <td class="nowrap"><a class="btn btn--sm" href="view.php?id=<?= (int) $r['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pagination">
        <?php if ($page > 1): ?>
          <a class="btn btn--ghost" href="<?= h(link_with(['page' => $page - 1])) ?>">Previous</a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>

        <span>Page <?= $page ?> of <?= $pages ?></span>

        <?php if ($page < $pages): ?>
          <a class="btn btn--ghost" href="<?= h(link_with(['page' => $page + 1])) ?>">Next</a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
      </nav>
    <?php endif; ?>

  <?php endif; ?>

</main>

</body>
</html>
