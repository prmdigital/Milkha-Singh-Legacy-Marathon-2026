<?php
/**
 * LOCAL TESTING ONLY. Not deployed: build.sh never copies tools/.
 *
 * Stands in for admin/content-api.php where there is no database. Uses the
 * real key lookup and cleaning from api/cms.php, and keeps saved edits
 * directly in uploads/site/content.json, so the editor and the public pages
 * behave exactly as they would against the real endpoint.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/cms.php';

header('Content-Type: application/json; charset=utf-8');

function out(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_POST['csrf'] ?? '') !== 'local-test') {
    out(400, ['ok' => false, 'error' => 'Your session expired. Reload the editor and try again.']);
}

$cache = cms_read_cache() ?: ['pages' => [], 'settings' => []];
$write = static function (array $cache): void {
    cms_ensure_dir();
    file_put_contents(cms_cache_file(), json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$action = (string) ($_POST['action'] ?? '');
$page   = (string) ($_POST['page'] ?? '');
$key    = (string) ($_POST['key'] ?? '');

if ($action === 'upload') {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        out(422, ['ok' => false, 'error' => 'The upload did not complete.']);
    }
    // No fileinfo extension locally: trust the extension for testing only.
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        out(422, ['ok' => false, 'error' => 'Please upload a JPG, PNG, WebP or GIF image.']);
    }
    cms_ensure_dir();
    $name = 'test-' . bin2hex(random_bytes(4)) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], cms_dir() . '/' . $name);
    out(200, ['ok' => true, 'path' => 'uploads/site/' . $name]);
}

if ($action === 'save') {
    $info = cms_key_info($page, $key);
    if ($info === null) {
        out(422, ['ok' => false, 'error' => 'That part of the page could not be found.']);
    }
    $fields = json_decode((string) ($_POST['fields'] ?? ''), true) ?: [];
    $clean = [];
    foreach ($fields as $field => $value) {
        if (!in_array($field, $info['fields'], true)) {
            continue;
        }
        $v = cms_clean_field($info['kind'], $field, (string) $value);
        if ($v === null) {
            out(422, ['ok' => false, 'error' => 'That ' . $field . ' cannot be used.']);
        }
        $clean[$field] = $v;
    }
    foreach ($clean as $field => $v) {
        $cache['pages'][$page][$key][$field] = $v;
    }
    $write($cache);
    out(200, ['ok' => true, 'fields' => $clean]);
}

if ($action === 'save_logos') {
    $logos = cms_fix_badges(cms_clean_logos(json_decode((string) ($_POST['logos'] ?? ''), true)));
    foreach ($logos as $l) {
        if ($l['name'] === '') {
            out(422, ['ok' => false, 'error' => 'Give every logo a company name.']);
        }
    }
    $ph = max(0, min(CMS_LOGO_MAX_PLACEHOLDERS, (int) ($_POST['placeholders'] ?? 0)));
    $cache['settings']['sponsor_logos'] = json_encode($logos, JSON_UNESCAPED_UNICODE);
    $cache['settings']['sponsor_placeholders'] = (string) $ph;
    $write($cache);
    out(200, ['ok' => true, 'logos' => $logos, 'placeholders' => $ph]);
}

if ($action === 'reset_logos') {
    unset($cache['settings']['sponsor_logos'], $cache['settings']['sponsor_placeholders']);
    $write($cache);
    out(200, ['ok' => true]);
}

if ($action === 'restore') {
    unset($cache['pages'][$page][$key]);
    $write($cache);
    out(200, ['ok' => true]);
}

out(400, ['ok' => false, 'error' => 'Unknown action.']);
