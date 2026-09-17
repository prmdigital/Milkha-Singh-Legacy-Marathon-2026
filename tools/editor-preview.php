<?php
/**
 * LOCAL TESTING ONLY. Not deployed: build.sh never copies tools/.
 *
 * Serves the website editor without the admin login, and saves through
 * tools/fake-content-api.php, which writes the same content cache the real
 * endpoint does but needs no database. Run under `php -S` from the repo root:
 *
 *     http://127.0.0.1:8093/tools/editor-preview.php?page=index
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/cms.php';

$page = (string) ($_GET['page'] ?? 'index');
if (!isset(CMS_PAGES[$page])) {
    $page = 'index';
}

$html = cms_editor_html($page, 'local-test', 'Local tester');
$html = str_replace(
    ['admin\/content-api.php', 'admin\/editor.php', 'admin/editor.php'],
    ['tools\/fake-content-api.php', 'tools\/editor-preview.php', 'tools/editor-preview.php'],
    $html
);
// The page picker builds its own URL.
$html = str_replace("'admin/editor.php?page='", "'tools/editor-preview.php?page='", $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
