<?php
/**
 * Sponsorship enquiries from the public "Become a Sponsor" form.
 *
 * A working list rather than a dashboard: filter, read, and move a lead along
 * as somebody actually calls them. Owners and managers only — an enquiry names
 * a person at a named company and often a budget.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/lib.php';

require_admin();
require_can('view_sponsors');

$pdo   = db();
$flash = '';

const SPONSOR_STATUSES = [
    'new'       => 'New',
    'contacted' => 'Contacted',
    'confirmed' => 'Confirmed',
    'declined'  => 'Declined',
];

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    require_can('manage_sponsors');

    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $notes  = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000);

    if ($id > 0 && isset(SPONSOR_STATUSES[$status])) {
        $st = $pdo->prepare('UPDATE sponsor_enquiries SET status = ?, notes = ? WHERE id = ?');
        $st->execute([$status, $notes, $id]);

        $ref = $pdo->prepare('SELECT reference FROM sponsor_enquiries WHERE id = ?');
        $ref->execute([$id]);
        audit('sponsor_update', (string) $ref->fetchColumn());

        $flash = 'Enquiry updated.';
    }
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

$where  = [];
$params = [];

$fStatus = (string) ($_GET['status'] ?? '');
if (isset(SPONSOR_STATUSES[$fStatus])) {
    $where[]  = 'status = ?';
    $params[] = $fStatus;
}

$fTier = (string) ($_GET['tier'] ?? '');
if (isset(SPONSOR_TIERS[$fTier])) {
    $where[]  = 'tier = ?';
    $params[] = $fTier;
}

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = '(company LIKE ? OR contact_name LIKE ? OR email LIKE ? OR mobile LIKE ? OR reference LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql = 'SELECT * FROM sponsor_enquiries'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY created_at DESC, id DESC LIMIT 500';

$missingTable = false;
$rows         = [];
$counts       = array_fill_keys(array_keys(SPONSOR_STATUSES), 0);

try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM sponsor_enquiries GROUP BY status') as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
} catch (Throwable $e) {
    // The table arrived after the first release, so an install that has not run
    // schema-sponsors.sql yet is told what to do instead of shown a 500.
    $missingTable = true;
}

$filtered = ($fStatus !== '' || $fTier !== '' || $q !== '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sponsors &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260907-2">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">

  <?php if ($flash !== ''): ?>
    <p class="alert alert--ok"><?= h($flash) ?></p>
  <?php endif; ?>

  <?php if ($missingTable): ?>

    <div class="panel">
      <h2>One step left</h2>
      <p>
        The <code>sponsor_enquiries</code> table does not exist yet. Enquiries sent before
        it does are not stored, so do this before pointing anyone at the sponsorship form.
      </p>
      <p>
        Open phpMyAdmin, select the marathon database, go to the <strong>SQL</strong> tab
        and run this. It is printed here rather than shipped as a file because the build
        strips <code>.sql</code> from the upload.
      </p>
      <pre class="sqlblock"><code>CREATE TABLE IF NOT EXISTS sponsor_enquiries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference    VARCHAR(32)  NOT NULL,
  company      VARCHAR(160) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  designation  VARCHAR(120) DEFAULT NULL,
  email        VARCHAR(190) NOT NULL,
  mobile       VARCHAR(20)  NOT NULL,
  website      VARCHAR(190) DEFAULT NULL,
  city         VARCHAR(90)  DEFAULT NULL,
  tier         VARCHAR(40)  NOT NULL,
  budget       VARCHAR(40)  DEFAULT NULL,
  message      TEXT         DEFAULT NULL,
  status       ENUM('new','contacted','confirmed','declined') NOT NULL DEFAULT 'new',
  notes        TEXT         DEFAULT NULL,
  ip_address   VARCHAR(45)  DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_reference (reference),
  KEY idx_status (status),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</code></pre>
    </div>

  <?php else: ?>

    <section class="stats">
      <?php foreach (SPONSOR_STATUSES as $key => $label): ?>
        <div class="stat<?= $key === 'new' ? '' : ' stat--muted' ?>">
          <span class="stat__n"><?= (int) $counts[$key] ?></span>
          <span class="stat__l"><?= h($label) ?></span>
        </div>
      <?php endforeach; ?>
    </section>

    <form class="filters" method="get" action="sponsors.php">
      <div class="filters__row">
        <label class="filters__search">
          <span class="sr-only">Search</span>
          <input name="q" type="search" value="<?= h($q) ?>"
                 placeholder="Company, name, email, mobile or reference">
        </label>
        <label>
          Status
          <select name="status">
            <option value="">Any</option>
            <?php foreach (SPONSOR_STATUSES as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $fStatus === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Interest
          <select name="tier">
            <option value="">Any</option>
            <?php foreach (SPONSOR_TIERS as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $fTier === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn--primary" type="submit">Filter</button>
        <?php if ($filtered): ?>
          <a class="btn btn--ghost" href="sponsors.php">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (!$rows): ?>

      <div class="panel">
        <h2><?= $filtered ? 'Nothing matches those filters' : 'No enquiries yet' ?></h2>
        <p>
          <?php if ($filtered): ?>
            <a href="sponsors.php">Clear the filters</a> to see every enquiry.
          <?php else: ?>
            Enquiries sent through the <em>Become a Sponsor</em> form will appear here.
          <?php endif; ?>
        </p>
      </div>

    <?php else: ?>

      <div class="listhead">
        <p><?= count($rows) ?> enquir<?= count($rows) === 1 ? 'y' : 'ies' ?></p>
      </div>

      <?php foreach ($rows as $r): ?>
        <article class="panel sponsor">
          <header class="sponsor__head">
            <div>
              <h2><?= h($r['company']) ?></h2>
              <p class="sub">
                <?= h($r['reference']) ?>
                &middot; <?= h(SPONSOR_TIERS[$r['tier']] ?? $r['tier']) ?>
                <?php if (($r['budget'] ?? '') !== ''): ?>
                  &middot; <?= h(SPONSOR_BUDGETS[$r['budget']] ?? $r['budget']) ?>
                <?php endif; ?>
                &middot; <?= h(date('j M Y, g:i A', strtotime((string) $r['created_at']))) ?>
              </p>
            </div>
            <span class="pill pill--<?= h($r['status']) ?>"><?= h(SPONSOR_STATUSES[$r['status']] ?? $r['status']) ?></span>
          </header>

          <dl class="sponsor__facts">
            <div>
              <dt>Contact</dt>
              <dd>
                <?= h($r['contact_name']) ?>
                <?php if (($r['designation'] ?? '') !== ''): ?>
                  <span class="sub"><?= h($r['designation']) ?></span>
                <?php endif; ?>
              </dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></dd>
            </div>
            <div>
              <dt>Mobile</dt>
              <dd><a href="tel:+91<?= h($r['mobile']) ?>"><?= h($r['mobile']) ?></a></dd>
            </div>
            <?php if (($r['website'] ?? '') !== ''): ?>
              <div>
                <dt>Website</dt>
                <dd><a href="<?= h($r['website']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['website']) ?></a></dd>
              </div>
            <?php endif; ?>
            <?php if (($r['city'] ?? '') !== ''): ?>
              <div>
                <dt>City</dt>
                <dd><?= h($r['city']) ?></dd>
              </div>
            <?php endif; ?>
          </dl>

          <?php if (($r['message'] ?? '') !== ''): ?>
            <blockquote class="sponsor__message"><?= nl2br(h($r['message'])) ?></blockquote>
          <?php endif; ?>

          <?php if (can('manage_sponsors')): ?>
            <form class="sponsor__update filters" method="post" action="sponsors.php">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <div class="filters__row">
                <label>
                  Status
                  <select name="status">
                    <?php foreach (SPONSOR_STATUSES as $key => $label): ?>
                      <option value="<?= h($key) ?>" <?= $r['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="filters__search">
                  Notes
                  <input name="notes" type="text" maxlength="2000"
                         value="<?= h((string) ($r['notes'] ?? '')) ?>"
                         placeholder="Who called, what was agreed">
                </label>
                <button class="btn btn--primary" type="submit">Save</button>
              </div>
            </form>
          <?php elseif (($r['notes'] ?? '') !== ''): ?>
            <p class="sub"><?= h((string) $r['notes']) ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>

    <?php endif; ?>

  <?php endif; ?>

</main>

</body>
</html>
