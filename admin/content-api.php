<?php
/**
 * POST /admin/content-api.php
 *
 * The website editor's save endpoint. Admins and Administrators only.
 *
 *   action=save     page, key, fields (JSON object of field => value)
 *   action=restore  page, key         (removes the edit; the original returns)
 *   action=upload   kind (image|video), file
 *
 * Always answers JSON: { ok: true, ... } or { ok: false, error: "..." }.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/cms.php';

admin_boot();
no_store();
header('Content-Type: application/json; charset=utf-8');

function reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_logged_in()) {
    reply(401, ['ok' => false, 'error' => 'You have been signed out. Sign in to the admin panel in another tab, then try again.']);
}
if (!can('edit_content')) {
    reply(403, ['ok' => false, 'error' => 'Your account cannot edit the website.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$sent = (string) ($_POST['csrf'] ?? '');
if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
    reply(400, ['ok' => false, 'error' => 'Your session expired. Reload the editor and try again.']);
}

$me   = current_user();
$user = $me['username'] !== '' ? $me['username'] : 'admin';

$action = (string) ($_POST['action'] ?? '');
$page   = (string) ($_POST['page'] ?? '');
$key    = (string) ($_POST['key'] ?? '');

if ($action === 'upload') {
    $kind = (string) ($_POST['kind'] ?? '') === 'video' ? 'video' : 'image';
    $r = cms_store_upload($_FILES['file'] ?? null, $kind);
    if ($r['ok']) {
        audit('media_uploaded', cms_substr($r['path'], 64));
    }
    reply($r['ok'] ? 200 : 422, $r);
}

if (!ensure_cms_tables()) {
    reply(500, ['ok' => false, 'error' => 'The website content tables could not be created. Open Admin › Website for the SQL to run.']);
}

if (!isset(CMS_PAGES[$page])) {
    reply(400, ['ok' => false, 'error' => 'Unknown page.']);
}

if ($action === 'save') {
    $fields = json_decode((string) ($_POST['fields'] ?? ''), true);
    if (!is_array($fields)) {
        reply(400, ['ok' => false, 'error' => 'Nothing to save.']);
    }
    $r = cms_save($page, $key, $fields, $user);
    if ($r['ok']) {
        audit('content_updated', cms_substr($page . ' ' . $key, 64));
    }
    reply($r['ok'] ? 200 : 422, $r);
}

if ($action === 'restore') {
    $r = cms_restore($page, $key, $user);
    if ($r['ok']) {
        audit('content_restored', cms_substr($page . ' ' . $key, 64));
    }
    reply($r['ok'] ? 200 : 422, $r);
}

reply(400, ['ok' => false, 'error' => 'Unknown action.']);
