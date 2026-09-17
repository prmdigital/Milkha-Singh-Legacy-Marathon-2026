<?php
/**
 * The visual website editor: the real page, with click-to-edit on top.
 *
 * Served from /admin/ so the admin session cookie (path /admin/) comes with
 * every save. The page's own images, styles and scripts load from the site
 * root through a <base> tag; see cms_editor_html().
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/cms.php';

require_admin();
require_can('edit_content');

$page = (string) ($_GET['page'] ?? 'index');
if (!isset(CMS_PAGES[$page])) {
    header('Location: website.php');
    exit;
}

$me   = current_user();
$html = cms_editor_html($page, csrf_token(), $me['name'] !== '' ? $me['name'] : $me['username']);

if ($html === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That page file is missing from the server.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
