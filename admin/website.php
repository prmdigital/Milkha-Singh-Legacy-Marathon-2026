<?php
/**
 * Website: the starting point for editing the public site.
 *
 * Lists the pages with a link into the visual editor, everything currently
 * changed from the original (with a way to put the original back), and the
 * most recent edits.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/cms.php';

require_admin();
require_can('edit_content');

$ready = ensure_cms_tables();
$me    = current_user();
$user  = $me['username'] !== '' ? $me['username'] : 'admin';

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $page = (string) ($_POST['page'] ?? '');
    $key  = (string) ($_POST['key'] ?? '');
    if (($_POST['action'] ?? '') === 'restore' && isset(CMS_PAGES[$page])) {
        $r = cms_restore($page, $key, $user);
        if ($r['ok']) {
            audit('content_restored', cms_substr($page . ' ' . $key, 64));
        }
        header('Location: website.php?done=' . ($r['ok'] ? 'restored' : 'failed'));
        exit;
    }
}

/** A short plain-text preview of a saved value. */
function preview(string $field, string $value): string
{
    if ($field === 'src') {
        return 'Image: ' . basename($value);
    }
    if ($field === 'href') {
        return 'Link: ' . $value;
    }
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8')) ?? '');
    return cms_strlen($t) > 90 ? cms_substr($t, 90) . '…' : $t;
}

$edited  = [];
$counts  = array_fill_keys(array_keys(CMS_PAGES), 0);
$history = [];

if ($ready) {
    $rows = db()->query('SELECT page, ckey, field, value, updated_by, updated_at FROM site_content ORDER BY updated_at DESC')->fetchAll();
    foreach ($rows as $r) {
        $id = $r['page'] . '|' . $r['ckey'];
        if (!isset($edited[$id])) {
            $edited[$id] = [
                'page' => $r['page'], 'key' => $r['ckey'], 'previews' => [],
                'by' => $r['updated_by'], 'at' => $r['updated_at'],
            ];
            if (isset($counts[$r['page']])) {
                $counts[$r['page']]++;
            }
        }
        $edited[$id]['previews'][] = preview((string) $r['field'], (string) $r['value']);
    }

    $history = db()->query(
        'SELECT page, ckey, field, old_value, new_value, changed_by, changed_at
           FROM site_content_history ORDER BY id DESC LIMIT 25'
    )->fetchAll();
}

$done = (string) ($_GET['done'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Website &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260917-4">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap">
  <h1 class="pagetitle">Website</h1>
  <p class="pagesub">
    Change the text, images and links on the public website. Changes go live as soon as
    you save them. There is nothing to upload.
  </p>

  <?php if ($done === 'restored'): ?>
    <p class="alert alert--ok">The original has been put back on the website.</p>
  <?php elseif ($done === 'failed'): ?>
    <p class="alert alert--error">That could not be restored. Please try again.</p>
  <?php endif; ?>

  <?php if (!$ready): ?>

    <div class="panel">
      <h2>One step left</h2>
      <p>
        The website editor needs three database tables, and the database would not let this
        page create them. Open phpMyAdmin, select the marathon database, go to the
        <strong>SQL</strong> tab, run this, then reload this page.
      </p>
      <pre class="sqlblock"><code><?= h(implode(";\n\n", CMS_DDL) . ';') ?></code></pre>
    </div>

  <?php else: ?>

    <section class="sitecards">
      <?php foreach (CMS_PAGES as $key => $label): ?>
        <article class="panel sitecard">
          <h2><?= h($label) ?></h2>
          <p class="sub">
            <?= $counts[$key] === 0 ? 'Showing the original content' : $counts[$key] . ' item' . ($counts[$key] === 1 ? '' : 's') . ' changed' ?>
          </p>
          <p class="sitecard__actions">
            <a class="btn btn--primary" href="editor.php?page=<?= h($key) ?>">Edit this page</a>
            <a class="btn btn--ghost" href="../<?= h($key) ?>.html" target="_blank" rel="noopener">View</a>
          </p>
        </article>
      <?php endforeach; ?>

      <article class="panel sitecard sitecard--settings">
        <h2>Event, fees &amp; media</h2>
        <p class="sub">Race start time, early bird end date, entry fees, hero image and video. Sponsor logos are managed in the home page editor.</p>
        <p class="sitecard__actions">
          <a class="btn btn--primary" href="event-settings.php">Open</a>
        </p>
      </article>
    </section>

    <section class="panel panel--wide">
      <h2>Changed from the original</h2>
      <?php if (!$edited): ?>
        <p class="muted">Nothing has been changed yet. Open a page above and click any text or image.</p>
      <?php else: ?>
        <div class="tablewrap">
          <table class="table">
            <thead>
              <tr><th>Page</th><th>Now shows</th><th>Changed by</th><th>When</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($edited as $e): ?>
              <tr>
                <td class="nowrap"><?= h(CMS_PAGES[$e['page']] ?? $e['page']) ?></td>
                <td>
                  <?php foreach ($e['previews'] as $p): ?>
                    <div><?= h($p) ?></div>
                  <?php endforeach; ?>
                  <span class="sub"><?= h($e['key']) ?></span>
                </td>
                <td class="nowrap"><?= h((string) $e['by']) ?></td>
                <td class="nowrap"><?= h(when((string) $e['at'])) ?></td>
                <td class="nowrap">
                  <form method="post" class="inline"
                        onsubmit="return confirm('Put the original back on the website?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="page" value="<?= h($e['page']) ?>">
                    <input type="hidden" name="key" value="<?= h($e['key']) ?>">
                    <button type="submit" class="btn btn--sm">Restore original</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel panel--wide">
      <h2>Recent changes</h2>
      <?php if (!$history): ?>
        <p class="muted">No changes yet.</p>
      <?php else: ?>
        <div class="tablewrap">
          <table class="table">
            <thead>
              <tr><th>When</th><th>Who</th><th>Page</th><th>Change</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $hrow): ?>
              <tr>
                <td class="nowrap"><?= h(when((string) $hrow['changed_at'])) ?></td>
                <td class="nowrap"><?= h((string) $hrow['changed_by']) ?></td>
                <td class="nowrap"><?= h(CMS_PAGES[$hrow['page']] ?? $hrow['page']) ?></td>
                <td>
                  <?php if ($hrow['new_value'] === null): ?>
                    Restored the original <span class="sub"><?= h($hrow['ckey']) ?></span>
                  <?php else: ?>
                    <?= h(preview((string) $hrow['field'], (string) $hrow['new_value'])) ?>
                    <?php if ($hrow['old_value'] !== null): ?>
                      <span class="sub">was: <?= h(preview((string) $hrow['field'], (string) $hrow['old_value'])) ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  <?php endif; ?>
</main>

</body>
</html>
