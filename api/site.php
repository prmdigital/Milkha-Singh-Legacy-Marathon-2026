<?php
/**
 * GET /api/site.php?page=index
 *
 * Loaded by every public page in its <head>. Returns JavaScript that sets
 * window.SITE_CONFIG (race start, early bird end, fees) and applies the edits
 * saved in the admin panel's website editor.
 *
 * It reads uploads/site/content.json, which the admin panel rewrites on every
 * save, so a page view does not touch the database. Whatever happens, the
 * answer is valid JavaScript: at worst an empty config, and the page shows its
 * built-in content.
 */

declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');

$page = (string) ($_GET['page'] ?? 'index');
if (!preg_match('/^[a-z\-]{1,40}$/', $page)) {
    $page = 'index';
}

try {
    require_once __DIR__ . '/cms.php';

    $file = cms_cache_file();

    // No cache yet (nothing saved since this was deployed): build one if the
    // site is configured, otherwise carry on with defaults.
    if (!is_readable($file) && config_path() !== null) {
        try {
            if (ensure_cms_tables()) {
                cms_rebuild_cache();
            }
        } catch (Throwable $e) {
            error_log('[marathon-cms] first cache build: ' . $e->getMessage());
        }
    }

    if (is_readable($file)) {
        $etag = '"' . $page . '-' . filemtime($file) . '-' . filesize($file) . '"';
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
    }

    echo cms_client_script($page, cms_read_cache());
} catch (Throwable $e) {
    error_log('[marathon-cms] site.php: ' . $e->getMessage());
    echo "window.SITE_CONFIG = window.SITE_CONFIG || {};\n";
}
