<?php
/**
 * Event, fees & media: the parts of the website that are settings rather than
 * text. Race start (the countdown and the age check), the early bird end date,
 * entry fees, the hero background image and video, and the sponsor strip logos.
 *
 * Fees and dates here are what the site actually charges and checks, not just
 * what it shows: api/lib.php reads the same saved values.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/cms.php';

require_admin();
require_can('edit_content');

$ready  = ensure_cms_tables();
$me     = current_user();
$user   = $me['username'] !== '' ? $me['username'] : 'admin';
$errors = [];

const FEE_KEYS = ['half', 'mini', 'cause'];

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $section = (string) ($_POST['section'] ?? '');
    $saved   = null;

    // ---- Website open / closed ---------------------------------------------
    // A file switch the web server reads (see the root .htaccess), so it takes
    // effect on the very next page request. No page, setting or registration
    // is deleted either way.
    if ($section === 'visibility') {
        $on = !empty($_POST['online']);
        if (set_site_online($on)) {
            audit('site_settings_updated', $on ? 'site_online' : 'site_offline');
            header('Location: event-settings.php?done=' . ($on ? 'site_online' : 'site_offline'));
            exit;
        }
        $errors[] = 'The website switch could not be changed: the uploads/site folder is not writable.';
    }

    // ---- Postponed / on ------------------------------------------------------
    if ($section === 'status') {
        $postponed = !empty($_POST['postponed']);
        $saved = cms_save_settings(['event_status' => $postponed ? 'postponed' : 'scheduled'], $user);
        $done  = $postponed ? 'postponed' : 'scheduled';
    }

    // ---- Dates and fees ----------------------------------------------------
    if ($section === 'event') {
        $raceDate  = (string) ($_POST['race_date'] ?? '');
        $raceTime  = (string) ($_POST['race_time'] ?? '');
        $earlyDate = (string) ($_POST['early_date'] ?? '');
        $earlyTime = (string) ($_POST['early_time'] ?? '');

        $validDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $validTime = static fn(string $t): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);

        if (!$validDate($raceDate) || !$validTime($raceTime)) {
            $errors[] = 'Enter the race start date and time.';
        }
        if (!$validDate($earlyDate) || !$validTime($earlyTime)) {
            $errors[] = 'Enter the date and time the early bird price ends.';
        }
        if (!$errors && ($earlyDate . ' ' . $earlyTime) >= ($raceDate . ' ' . $raceTime)) {
            $errors[] = 'The early bird price has to end before race day.';
        }

        $prices = [];
        foreach (FEE_KEYS as $k) {
            $base  = (int) ($_POST['fee_' . $k . '_base'] ?? 0);
            $early = (int) ($_POST['fee_' . $k . '_early'] ?? 0);
            $label = CATEGORIES[$k]['label'];
            if ($base < 1 || $early < 1) {
                $errors[] = $label . ': both fees must be at least ₹1.';
            } elseif ($early > $base) {
                $errors[] = $label . ': the early bird fee cannot be more than the standard fee.';
            }
            $prices[$k] = ['base' => $base, 'early' => $early];
        }

        if (!$errors) {
            $saved = cms_save_settings([
                'race_start'  => $raceDate . ' ' . $raceTime,
                'early_until' => $earlyDate . ' ' . $earlyTime,
                'prices'      => $prices,
            ], $user);
            $done = 'event';
        }
    }

    if ($section === 'event_reset') {
        $saved = cms_save_settings(['race_start' => null, 'early_until' => null, 'prices' => null], $user);
        $done  = 'event_reset';
    }

    // ---- Hero image / video --------------------------------------------------
    foreach (['hero_image' => 'image', 'hero_video' => 'video'] as $setting => $kind) {
        if ($section === $setting) {
            $r = cms_store_upload($_FILES['file'] ?? null, $kind);
            if ($r['ok']) {
                $saved = cms_save_settings([$setting => $r['path']], $user);
                $done  = $setting;
            } else {
                $errors[] = $r['error'];
            }
        }
        if ($section === $setting . '_reset') {
            $saved = cms_save_settings([$setting => null], $user);
            $done  = $setting . '_reset';
        }
    }

    if ($saved === false) {
        $errors[] = 'The settings could not be saved. Please try again.';
    } elseif ($saved === true) {
        audit('site_settings_updated', $done);
        header('Location: event-settings.php?done=' . $done);
        exit;
    }
}

// ---- Current values ---------------------------------------------------------

$postponed = !registration_open();
$online    = site_online();
$raceStart = race_start();
$earlyEnd  = early_bird_until();
$fees      = category_fees();

$heroImage = cms_clean_src((string) site_setting('hero_image', ''));
$heroVideo = cms_clean_src((string) site_setting('hero_video', ''));

$limit = cms_upload_limit_bytes();

$messages = [
    'site_online'      => 'The website is live again. Ask Google to re-index it in Search Console (URL inspection > Request indexing).',
    'site_offline'     => 'The website is closed. Visitors see the postponement page, and Google is told to drop it from search results.',
    'postponed'        => 'The event is marked postponed. The date, countdown and registration form are hidden, and registration is closed.',
    'scheduled'        => 'The event is back on. The date, countdown and registration form are showing, and registration is open.',
    'event'            => 'Dates and fees saved. The website and the registration form use them now.',
    'event_reset'      => 'Dates and fees are back to the original values.',
    'hero_image'       => 'The new hero image is live.',
    'hero_image_reset' => 'The original hero image is back.',
    'hero_video'       => 'The new hero video is live.',
    'hero_video_reset' => 'The original hero video is back.',
];
$flash = $messages[(string) ($_GET['done'] ?? '')] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Event, fees &amp; media &middot; Marathon Admin</title>
<link rel="stylesheet" href="assets/admin.css?v=20260921-3">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main class="wrap wrap--narrow">
  <p class="crumb"><a href="website.php">&larr; Website</a></p>
  <h1 class="pagetitle">Event, fees &amp; media</h1>
  <p class="pagesub">Changes here go live on the website as soon as you save.</p>

  <?php if ($flash !== ''): ?>
    <p class="alert alert--ok"><?= h($flash) ?></p>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert--error">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <p class="alert alert--warn">
      The website editor's database tables are missing. Open <a href="website.php">Website</a> for the one-time setup.
    </p>
  <?php else: ?>

  <section class="panel<?= $online ? '' : ' panel--alert' ?>">
    <h2>Website</h2>
    <p class="muted">
      Currently: <b><?= $online ? 'Live. Visitors see the full website.' : 'Closed. Every page shows the postponement notice and is hidden from Google.' ?></b>
    </p>
    <form method="post" class="pwform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="visibility">
      <label class="checkline">
        <input type="checkbox" name="online" value="1" <?= $online ? 'checked' : '' ?>>
        <span>
          <b>Website is live</b>
          <small>
            Unticked: every page of the website shows only "The event has been postponed;
            The next date will be announced soon." (<a href="../offline.html" target="_blank" rel="noopener">see it</a>),
            and Google is told to remove the site from its results. This admin panel, the
            page editor, refunds and all registration data keep working and nothing is
            deleted. Tick it to bring the whole website back exactly as it was.
          </small>
        </span>
      </label>
      <button type="submit" class="btn btn--primary">Save website status</button>
    </form>
  </section>

  <section class="panel<?= $postponed ? ' panel--alert' : '' ?>">
    <h2>Event status</h2>
    <p class="muted">
      Currently: <b><?= $postponed ? 'Postponed. Registration is closed.' : 'On. Registration is open.' ?></b>
    </p>
    <form method="post" class="pwform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="status">
      <label class="checkline">
        <input type="checkbox" name="postponed" value="1" <?= $postponed ? 'checked' : '' ?>>
        <span>
          <b>Event postponed</b>
          <small>
            Ticked: the date, countdown and registration form are hidden, the site shows
            "The event has been postponed; The next date will be announced soon.", and no
            one can register. Untick it once the new date is set below, and change the date
            written on the page in the <a href="editor.php?page=index">page editor</a>.
          </small>
        </span>
      </label>
      <button type="submit" class="btn btn--primary">Save event status</button>
    </form>
  </section>

  <section class="panel">
    <h2>Dates and entry fees</h2>
    <form method="post" class="pwform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="event">

      <div class="formgrid">
        <label>
          <span>Race day</span>
          <input type="date" name="race_date" required value="<?= h(substr($raceStart, 0, 10)) ?>">
        </label>
        <label>
          <span>Countdown ends at (IST)</span>
          <input type="time" name="race_time" required value="<?= h(substr($raceStart, 11, 5)) ?>">
        </label>
      </div>
      <small>Used by the countdown, and to work out each runner's age on race day.</small>

      <div class="formgrid">
        <label>
          <span>Early bird price ends on</span>
          <input type="date" name="early_date" required value="<?= h(substr($earlyEnd, 0, 10)) ?>">
        </label>
        <label>
          <span>At (IST)</span>
          <input type="time" name="early_time" required value="<?= h(substr($earlyEnd, 11, 5)) ?>">
        </label>
      </div>
      <small>From the minute after this, everyone pays the standard fee.</small>

      <div class="tablewrap">
        <table class="table feetable">
          <thead><tr><th>Race</th><th>Standard fee (₹)</th><th>Early bird fee (₹)</th></tr></thead>
          <tbody>
          <?php foreach (FEE_KEYS as $k): ?>
            <tr>
              <td><?= h(CATEGORIES[$k]['label']) ?> <span class="sub"><?= h(CATEGORIES[$k]['distance']) ?></span></td>
              <td><input type="number" min="1" step="1" required name="fee_<?= $k ?>_base" value="<?= (int) ($fees[$k]['base_paise'] / 100) ?>"></td>
              <td><input type="number" min="1" step="1" required name="fee_<?= $k ?>_early" value="<?= (int) ($fees[$k]['early_paise'] / 100) ?>"></td>
            </tr>
          <?php endforeach; ?>
            <tr>
              <td><?= h(CATEGORIES['para']['label']) ?> <span class="sub"><?= h(CATEGORIES['para']['distance']) ?></span></td>
              <td colspan="2" class="muted">Always free</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="alert alert--warn">
        These are the fees runners are charged. The amounts and dates <b>written on the page</b>
        (the race cards, "₹300 discount", "Till 7 October 2026") are text: change those in the
        <a href="editor.php?page=index">page editor</a> so they match.
      </p>

      <button type="submit" class="btn btn--primary">Save dates and fees</button>
    </form>
    <form method="post" class="inline resetform"
          onsubmit="return confirm('Put the original dates and fees back?');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="section" value="event_reset">
      <button type="submit" class="btn btn--sm btn--ghost">Restore the original dates and fees</button>
    </form>
  </section>

  <section class="panel">
    <h2>Hero background</h2>
    <p class="muted">
      The picture behind the title at the top of the home page, and the video that plays over
      it. Largest upload this server accepts: <?= h($limit ? cms_human_bytes($limit) : 'unknown') ?>.
    </p>

    <div class="mediarow">
      <div class="mediarow__preview">
        <img src="../<?= h($heroImage ?? 'images/hero-bg.jpg') ?>" alt="Current hero image">
      </div>
      <div class="mediarow__body">
        <b>Image</b> <span class="sub"><?= $heroImage ? 'Custom: ' . h(basename($heroImage)) : 'Original' ?></span>
        <form method="post" enctype="multipart/form-data" class="pwform">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="section" value="hero_image">
          <label>
            <span>Replace with (JPG, PNG or WebP, wide landscape, at least 1920 × 1080)</span>
            <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required>
          </label>
          <button type="submit" class="btn btn--primary">Upload image</button>
        </form>
        <?php if ($heroImage): ?>
          <form method="post" class="inline resetform">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="section" value="hero_image_reset">
            <button type="submit" class="btn btn--sm btn--ghost">Restore the original image</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="mediarow">
      <div class="mediarow__preview">
        <video src="../<?= h($heroVideo ?? 'assets/video/hero-loop.mp4') ?>" muted playsinline preload="metadata"></video>
      </div>
      <div class="mediarow__body">
        <b>Video</b> <span class="sub"><?= $heroVideo ? 'Custom: ' . h(basename($heroVideo)) : 'Original' ?></span>
        <form method="post" enctype="multipart/form-data" class="pwform">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="section" value="hero_video">
          <label>
            <span>Replace with (MP4, silent, 10 to 30 seconds, ideally under 8 MB)</span>
            <input type="file" name="file" accept="video/mp4,video/webm" required>
          </label>
          <button type="submit" class="btn btn--primary">Upload video</button>
        </form>
        <?php if ($heroVideo): ?>
          <form method="post" class="inline resetform">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="section" value="hero_video_reset">
            <button type="submit" class="btn btn--sm btn--ghost">Restore the original video</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="panel">
    <h2>Sponsor &amp; partner logos</h2>
    <p class="muted">
      The logos in the scrolling strip and in the Sponsors &amp; Partners section are managed
      from one list in the page editor: open the home page and click either logo area, or use
      <b>Sponsor logos</b> in the editor bar.
    </p>
    <p><a class="btn btn--primary" href="editor.php?page=index#sponsors">Manage sponsor logos</a></p>
  </section>

  <?php endif; ?>
</main>

</body>
</html>
