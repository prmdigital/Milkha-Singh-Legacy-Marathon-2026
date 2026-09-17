<?php
/**
 * Website content editing: the store behind the admin panel's visual editor.
 *
 * How it fits together
 * --------------------
 * Every editable piece of a public page carries a data-e key in the HTML
 * (added once by tools/annotate.py). An edit is saved against page + key +
 * field ("html" for text, "src"/"alt" for an image, "href" for a link...).
 *
 * The pages themselves stay static files. Each loads api/site.php in its
 * <head>, which returns the saved edits for that page and applies them. That
 * script reads uploads/site/content.json, a cache rewritten on every save, so
 * a page view never touches the database; if anything goes wrong the page just
 * shows its built-in content.
 *
 * Nothing here trusts the editor: HTML is reduced to a short list of inline
 * tags, links to http(s)/mailto/tel/relative addresses, and images to files in
 * uploads/site/ or images/.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** The pages the editor can open, in the order they are listed. */
const CMS_PAGES = [
    'index'            => 'Home page',
    'sponsor'          => 'Become a Sponsor',
    'privacy-policy'   => 'Privacy Policy',
    'refund-policy'    => 'Refund Policy',
    'terms-conditions' => 'Terms & Conditions',
];

/** Inline tags an edit may contain, with the attributes each may keep. */
const CMS_TAGS = [
    'b' => [], 'strong' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
    'span' => [], 'br' => [], 'small' => [], 'sup' => [], 'sub' => [],
    'mark' => [], 'code' => [], 'abbr' => ['title'], 'time' => ['datetime'],
    'a' => ['href', 'target', 'rel'],
    // Icons already inside some blocks (race times, "every entry includes").
    'svg' => ['viewbox', 'width', 'height', 'fill', 'stroke'],
    'path' => ['d'], 'circle' => ['cx', 'cy', 'r'], 'g' => [],
    'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry'],
    'line' => ['x1', 'y1', 'x2', 'y2'], 'polyline' => ['points'], 'polygon' => ['points'],
];
const CMS_GLOBAL_ATTRS = ['class', 'aria-hidden', 'aria-label'];

/** Removed with everything inside them, rather than unwrapped. */
const CMS_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
                  'textarea', 'select', 'link', 'meta', 'noscript', 'template', 'video', 'audio', 'img'];

const CMS_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const CMS_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
const CMS_IMAGE_MAX   = 8 * 1024 * 1024;
const CMS_VIDEO_MAX   = 60 * 1024 * 1024;

// ---------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------

function cms_root(): string
{
    return dirname(__DIR__);
}

/** uploads/site: uploaded images and videos, and the content cache. */
function cms_dir(): string
{
    return cms_root() . '/uploads/site';
}

function cms_cache_file(): string
{
    return cms_dir() . '/content.json';
}

/**
 * Creates uploads/site with a lock-down .htaccess. The folder is web-served by
 * design (it holds page images), so nothing in it may ever run as code.
 */
function cms_ensure_dir(): bool
{
    $dir = cms_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, cms_htaccess());
    }
    return is_writable($dir);
}

function cms_htaccess(): string
{
    return "# Uploaded page images and the content cache. Nothing here may run.\n"
         . "Options -Indexes\n"
         . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|shtml|cgi|pl|py|sh|htaccess)$\">\n"
         . "  Require all denied\n"
         . "</FilesMatch>\n";
}

/** The page's HTML file, which is also where every default value lives. */
function cms_template(string $page): string
{
    if (!isset(CMS_PAGES[$page])) {
        return '';
    }
    $html = @file_get_contents(cms_root() . '/' . $page . '.html');
    return $html === false ? '' : $html;
}

// ---------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------

/**
 * Creates the three content tables when missing. Idempotent; checked once per
 * request. Returns false only when the database refuses to create them.
 */
function ensure_cms_tables(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $check = static function (): bool {
        try {
            $pdo = db_connect();
            $pdo->query('SELECT 1 FROM site_content LIMIT 1');
            $pdo->query('SELECT 1 FROM site_content_history LIMIT 1');
            $pdo->query('SELECT 1 FROM site_settings LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    };

    if ($check()) {
        return $ready = true;
    }

    foreach (CMS_DDL as $sql) {
        try {
            db_connect()->exec($sql);
        } catch (Throwable $e) {
            error_log('[marathon-cms] create table: ' . $e->getMessage());
        }
    }

    return $ready = $check();
}

/** Also printed on the Website page if the database refuses to run it. */
const CMS_DDL = [
        "CREATE TABLE IF NOT EXISTS site_content (
          page       VARCHAR(40)  NOT NULL,
          ckey       VARCHAR(80)  NOT NULL,
          field      VARCHAR(16)  NOT NULL,
          value      MEDIUMTEXT   NOT NULL,
          updated_by VARCHAR(40)  DEFAULT NULL,
          updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (page, ckey, field)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS site_content_history (
          id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
          page       VARCHAR(40)  NOT NULL,
          ckey       VARCHAR(80)  NOT NULL,
          field      VARCHAR(16)  NOT NULL,
          old_value  MEDIUMTEXT   DEFAULT NULL,
          new_value  MEDIUMTEXT   DEFAULT NULL,
          changed_by VARCHAR(40)  DEFAULT NULL,
          changed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_item (page, ckey),
          KEY idx_when (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS site_settings (
          skey       VARCHAR(60)  NOT NULL,
          value      MEDIUMTEXT   NOT NULL,
          updated_by VARCHAR(40)  DEFAULT NULL,
          updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (skey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

// ---------------------------------------------------------------------------
// Cleaning
// ---------------------------------------------------------------------------

function cms_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function cms_substr(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/** Plain text: no tags, no control characters, a sensible length. */
function cms_clean_text(string $s, int $max = 500): string
{
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return cms_substr($s, $max);
}

/**
 * A link address the site may use, or null. Allows web, email and phone links,
 * in-page anchors and addresses on this site; refuses javascript:, data: and
 * anything else that could run code when clicked.
 */
function cms_clean_url(string $u): ?string
{
    $u = trim(preg_replace('/[\x00-\x20\x7F]+/', ' ', $u) ?? '');
    $u = str_replace(' ', '%20', $u);
    if ($u === '' || strlen($u) > 2000) {
        return null;
    }
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $u, $m)) {
        return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true) ? $u : null;
    }
    // "//evil.example" has no scheme but leaves the site.
    return str_starts_with($u, '//') ? null : $u;
}

/** An image or video address: only files this site hosts. */
function cms_clean_src(string $s): ?string
{
    $s = trim($s);
    if (!preg_match('#^(uploads/site|images|assets/video)/[A-Za-z0-9._\-/]+(\?[A-Za-z0-9=._\-]*)?$#', $s)) {
        return null;
    }
    return str_contains($s, '..') ? null : $s;
}

/**
 * Reduces edited HTML to the inline tags in CMS_TAGS. Anything else is either
 * dropped with its contents (scripts, embeds, form controls) or unwrapped so
 * its text survives (a <div> or <p> the browser inserted while typing).
 */
function cms_sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8" ?><div id="cms-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = null;
    foreach ($doc->getElementsByTagName('div') as $div) {
        if ($div->getAttribute('id') === 'cms-root') {
            $root = $div;
            break;
        }
    }
    if ($root === null) {
        return htmlspecialchars(cms_clean_text($html, 5000), ENT_QUOTES, 'UTF-8');
    }

    cms_clean_children($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    // libxml lowercases attribute names; SVG needs this one in camel case.
    $out = str_replace(' viewbox="', ' viewBox="', $out);
    return trim($out);
}

function cms_clean_children(DOMNode $node): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) {
            continue;
        }
        if (!$child instanceof DOMElement) {      // comments, processing instructions
            $node->removeChild($child);
            continue;
        }

        $tag = strtolower($child->tagName);

        if (in_array($tag, CMS_DROP, true)) {
            $node->removeChild($child);
            continue;
        }

        cms_clean_children($child);

        if (!isset(CMS_TAGS[$tag])) {
            // A block the browser wrapped a new line in: keep the line break.
            if (in_array($tag, ['div', 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                && $child->previousSibling !== null) {
                $node->insertBefore($node->ownerDocument->createElement('br'), $child);
            }
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        $allowed = array_merge(CMS_GLOBAL_ATTRS, CMS_TAGS[$tag]);
        foreach (iterator_to_array($child->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (!in_array($name, $allowed, true) || str_starts_with($name, 'on')) {
                $child->removeAttribute($attr->nodeName);
            }
        }

        if ($tag === 'a') {
            $href = $child->getAttribute('href');
            $clean = $href === '' ? null : cms_clean_url($href);
            if ($clean === null) {
                $child->removeAttribute('href');
            } else {
                $child->setAttribute('href', $clean);
            }
            if ($child->getAttribute('target') !== '') {
                if ($child->getAttribute('target') !== '_blank') {
                    $child->removeAttribute('target');
                } else {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// What a key is
// ---------------------------------------------------------------------------

/**
 * Looks a key up in the page's own HTML and says what can be edited on it.
 * Refusing keys the page does not contain keeps junk out of the table.
 *
 * @return array{tag:string, kind:string, fields:string[]}|null
 */
function cms_key_info(string $page, string $key): ?array
{
    if (!preg_match('/^[A-Za-z0-9._\-]{1,80}$/', $key)) {
        return null;
    }
    $html = cms_template($page);
    $pos  = strpos($html, 'data-e="' . $key . '"');
    if ($pos === false) {
        return null;
    }
    $start = strrpos(substr($html, 0, $pos), '<');
    if ($start === false || !preg_match('/^<([a-zA-Z0-9]+)/', substr($html, $start, 20), $m)) {
        return null;
    }
    $tag = strtolower($m[1]);

    if ($key === 'meta.title') {
        return ['tag' => $tag, 'kind' => 'title', 'fields' => ['html']];
    }
    if ($tag === 'meta') {
        return ['tag' => $tag, 'kind' => 'meta', 'fields' => ['content']];
    }
    if ($tag === 'img') {
        return ['tag' => $tag, 'kind' => 'image', 'fields' => ['src', 'alt']];
    }
    if ($tag === 'a') {
        $open  = strpos($html, '>', $pos);
        $close = stripos($html, '</a>', (int) $open);
        $inner = ($open !== false && $close !== false) ? substr($html, $open + 1, $close - $open - 1) : '';
        if (trim(strip_tags($inner)) === '') {
            return ['tag' => $tag, 'kind' => 'link', 'fields' => ['href', 'label']];
        }
        return ['tag' => $tag, 'kind' => 'text-link', 'fields' => ['html', 'href']];
    }
    return ['tag' => $tag, 'kind' => 'text', 'fields' => ['html']];
}

/** Cleans one field for its kind. Null means the value is not acceptable. */
function cms_clean_field(string $kind, string $field, string $value): ?string
{
    switch ($field) {
        case 'html':
            if ($kind === 'title') {
                $t = cms_clean_text($value, 120);
                return $t === '' ? null : $t;
            }
            return cms_sanitize_html(cms_substr($value, 20000));
        case 'href':
            return cms_clean_url($value);
        case 'src':
            return cms_clean_src($value);
        case 'alt':
            return cms_clean_text($value, 300);
        case 'label':
            return cms_clean_text($value, 80);
        case 'content':
            $t = cms_clean_text($value, 300);
            return $t === '' ? null : $t;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Saving
// ---------------------------------------------------------------------------

/**
 * Saves fields for one key and rebuilds the cache.
 *
 * @param array<string,string> $fields
 * @return array{ok:bool, error?:string, fields?:array<string,string>}
 */
function cms_save(string $page, string $key, array $fields, string $user): array
{
    $info = cms_key_info($page, $key);
    if ($info === null) {
        return ['ok' => false, 'error' => 'That part of the page could not be found. Reload the editor and try again.'];
    }

    $clean = [];
    foreach ($fields as $field => $value) {
        if (!in_array($field, $info['fields'], true)) {
            continue;
        }
        $v = cms_clean_field($info['kind'], $field, (string) $value);
        if ($v === null) {
            $what = ['href' => 'link address', 'src' => 'image', 'content' => 'description', 'html' => 'text'][$field] ?? $field;
            return ['ok' => false, 'error' => 'That ' . $what . ' cannot be used. '
                . ($field === 'href' ? 'Use a web address (https://…), mailto:, tel: or #section.' : 'Please check it and try again.')];
        }
        $clean[$field] = $v;
    }
    if (!$clean) {
        return ['ok' => false, 'error' => 'Nothing to save.'];
    }

    $pdo = db_connect();
    $pdo->beginTransaction();
    try {
        $get = $pdo->prepare('SELECT value FROM site_content WHERE page = ? AND ckey = ? AND field = ?');
        $del = $pdo->prepare('DELETE FROM site_content WHERE page = ? AND ckey = ? AND field = ?');
        $ins = $pdo->prepare('INSERT INTO site_content (page, ckey, field, value, updated_by) VALUES (?, ?, ?, ?, ?)');
        $his = $pdo->prepare(
            'INSERT INTO site_content_history (page, ckey, field, old_value, new_value, changed_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($clean as $field => $value) {
            $get->execute([$page, $key, $field]);
            $old = $get->fetchColumn();
            $old = $old === false ? null : (string) $old;
            if ($old === $value) {
                continue;
            }
            // Delete + insert rather than an upsert, so the same SQL works on
            // MySQL and on SQLite when the site is run locally.
            $del->execute([$page, $key, $field]);
            $ins->execute([$page, $key, $field, $value, $user]);
            $his->execute([$page, $key, $field, $old, $value, $user]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[marathon-cms] save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The change could not be saved. Please try again.'];
    }

    cms_rebuild_cache();
    return ['ok' => true, 'fields' => $clean];
}

/** Removes every saved field for a key, so the page shows its original again. */
function cms_restore(string $page, string $key, string $user): array
{
    if (cms_key_info($page, $key) === null && $key !== '') {
        // A key that no longer exists in the page can still be cleared out.
        if (!preg_match('/^[A-Za-z0-9._\-]{1,80}$/', $key) || !isset(CMS_PAGES[$page])) {
            return ['ok' => false, 'error' => 'Unknown item.'];
        }
    }

    $pdo = db_connect();
    try {
        $rows = $pdo->prepare('SELECT field, value FROM site_content WHERE page = ? AND ckey = ?');
        $rows->execute([$page, $key]);
        $his = $pdo->prepare(
            'INSERT INTO site_content_history (page, ckey, field, old_value, new_value, changed_by) VALUES (?, ?, ?, ?, NULL, ?)'
        );
        foreach ($rows->fetchAll() as $r) {
            $his->execute([$page, $key, $r['field'], $r['value'], $user]);
        }
        $pdo->prepare('DELETE FROM site_content WHERE page = ? AND ckey = ?')->execute([$page, $key]);
    } catch (Throwable $e) {
        error_log('[marathon-cms] restore: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not restore the original. Please try again.'];
    }

    cms_rebuild_cache();
    return ['ok' => true];
}

/** Saves event settings (strings; arrays are stored as JSON) and rebuilds the cache. */
function cms_save_settings(array $values, string $user): bool
{
    $pdo = db_connect();
    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM site_settings WHERE skey = ?');
        $ins = $pdo->prepare('INSERT INTO site_settings (skey, value, updated_by) VALUES (?, ?, ?)');
        foreach ($values as $k => $v) {
            $del->execute([$k]);
            if ($v === null) {
                continue;                           // null clears a setting back to its default
            }
            $ins->execute([$k, is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v, $user]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[marathon-cms] settings: ' . $e->getMessage());
        return false;
    }
    cms_rebuild_cache();
    return true;
}

// ---------------------------------------------------------------------------
// Cache
// ---------------------------------------------------------------------------

/**
 * Writes uploads/site/content.json from the database. Written to a temporary
 * file and renamed into place, so a page view never reads a half-written file.
 */
function cms_rebuild_cache(): bool
{
    try {
        $pdo = db_connect();
        $pages = [];
        foreach ($pdo->query('SELECT page, ckey, field, value FROM site_content') as $r) {
            $pages[$r['page']][$r['ckey']][$r['field']] = (string) $r['value'];
        }
        $settings = [];
        foreach ($pdo->query('SELECT skey, value FROM site_settings') as $r) {
            $settings[$r['skey']] = (string) $r['value'];
        }
    } catch (Throwable $e) {
        error_log('[marathon-cms] cache read: ' . $e->getMessage());
        return false;
    }

    if (!cms_ensure_dir()) {
        error_log('[marathon-cms] uploads/site is not writable');
        return false;
    }

    $json = json_encode(
        ['generated' => date('c'), 'pages' => $pages, 'settings' => $settings],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $tmp = cms_cache_file() . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, cms_cache_file())) {
        @unlink($tmp);
        error_log('[marathon-cms] could not write the content cache');
        return false;
    }
    return true;
}

/** The cache as an array, or empty when there is none. */
function cms_read_cache(): array
{
    $file = cms_cache_file();
    if (!is_readable($file)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// ---------------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------------

function cms_upload_limit_bytes(): int
{
    $parse = static function (string $v): int {
        $v = trim($v);
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1024 * 1024 * 1024;
            case 'm': return $n * 1024 * 1024;
            case 'k': return $n * 1024;
        }
        return $n;
    };
    $limits = array_filter([$parse((string) ini_get('upload_max_filesize')), $parse((string) ini_get('post_max_size'))]);
    return $limits ? min($limits) : 0;
}

function cms_human_bytes(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
}

/**
 * Stores an uploaded image or video in uploads/site under a random name.
 *
 * @param 'image'|'video' $kind
 * @return array{ok:bool, path?:string, error?:string}
 */
function cms_store_upload(?array $f, string $kind): array
{
    $types = $kind === 'video' ? CMS_VIDEO_TYPES : CMS_IMAGE_TYPES;
    $max   = min($kind === 'video' ? CMS_VIDEO_MAX : CMS_IMAGE_MAX, cms_upload_limit_bytes() ?: PHP_INT_MAX);

    if (!$f || !isset($f['error']) || is_array($f['error'])) {
        return ['ok' => false, 'error' => 'No file was received.'];
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That file is larger than the server accepts (' . cms_human_bytes($max) . ').'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return ['ok' => false, 'error' => 'The upload did not complete. Please try again.'];
    }
    if ($f['size'] > $max) {
        return ['ok' => false, 'error' => 'That file is ' . cms_human_bytes((int) $f['size'])
            . '. The limit is ' . cms_human_bytes($max) . '.'];
    }

    // The type is read from the file's contents, never from its name.
    if (class_exists('finfo')) {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    } else {
        $mime = (string) (mime_content_type($f['tmp_name']) ?: '');
    }
    if (!isset($types[$mime])) {
        return ['ok' => false, 'error' => $kind === 'video'
            ? 'Please upload an MP4 or WebM video.'
            : 'Please upload a JPG, PNG, WebP or GIF image.'];
    }
    if ($kind === 'image' && @getimagesize($f['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'That file is not a readable image.'];
    }

    if (!cms_ensure_dir()) {
        return ['ok' => false, 'error' => 'The uploads folder could not be created. Check the folder permissions in Hostinger.'];
    }

    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $types[$mime];
    if (!move_uploaded_file($f['tmp_name'], cms_dir() . '/' . $name)) {
        return ['ok' => false, 'error' => 'The file could not be saved.'];
    }
    @chmod(cms_dir() . '/' . $name, 0644);

    return ['ok' => true, 'path' => 'uploads/site/' . $name];
}

// ---------------------------------------------------------------------------
// Page script
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Sponsor logos
// ---------------------------------------------------------------------------

const CMS_LOGO_STYLES = ['logo', 'mark', 'org'];
const CMS_LOGO_MAX_PLACEHOLDERS = 8;

/**
 * One list drives both the scrolling logo strip and the Sponsors & Partners
 * grid. Each logo:
 *   src      file on this site
 *   name     company name (also the image description)
 *   badge    label on the tile, e.g. "Title Sponsor" (may be empty)
 *   style    logo = wide wordmark, mark = square crest, org = full green tile
 *   slider   shown in the scrolling strip
 *   section  shown in the Sponsors & Partners grid
 *
 * @return array<int, array{src:string,name:string,badge:string,style:string,slider:bool,section:bool}>
 */
function cms_clean_logos($input): array
{
    if (!is_array($input)) {
        return [];
    }
    $out = [];
    foreach (array_slice(array_values($input), 0, 40) as $l) {
        if (!is_array($l)) {
            continue;
        }
        $src = cms_clean_src((string) ($l['src'] ?? ''));
        if ($src === null) {
            continue;
        }
        $style = (string) ($l['style'] ?? 'logo');
        $out[] = [
            'src'     => $src,
            'name'    => cms_clean_text((string) ($l['name'] ?? ''), 120),
            'badge'   => cms_clean_text((string) ($l['badge'] ?? ''), 40),
            'style'   => in_array($style, CMS_LOGO_STYLES, true) ? $style : 'logo',
            'slider'  => !empty($l['slider']),
            'section' => !empty($l['section']),
        ];
    }
    return $out;
}

/**
 * The logos as the page ships them: every logo tile in the Sponsors grid (with
 * its badge and shape), then any logo that is only in the strip, and the
 * number of empty "Sponsor Logo" tiles.
 *
 * @return array{logos:array, placeholders:int}
 */
function cms_default_logos(): array
{
    $html = cms_template('index');

    $strip = [];
    if (preg_match('#<ul class="marquee__set">(.*?)</ul>#s', $html, $m)) {
        preg_match_all('#<img[^>]*\bsrc="([^"]+)"[^>]*\balt="([^"]*)"#', $m[1], $imgs, PREG_SET_ORDER);
        foreach ($imgs as $i) {
            $strip[$i[1]] = html_entity_decode($i[2], ENT_QUOTES, 'UTF-8');
        }
    }

    $logos = [];
    $placeholders = 0;
    $start = strpos($html, 'data-e-list="sponsors"');
    $end   = $start === false ? false : strpos($html, 'sponsors__cta', $start);
    if ($start !== false && $end !== false) {
        $grid = substr($html, $start, $end - $start);
        preg_match_all('#<div class="sponsor-tile([^"]*)"[^>]*>(.*?)</div>#s', $grid, $tiles, PREG_SET_ORDER);
        foreach ($tiles as $t) {
            if (!preg_match('#<img[^>]*\bsrc="([^"]+)"[^>]*\balt="([^"]*)"#', $t[2], $img)) {
                $placeholders++;
                continue;
            }
            $badge = preg_match('#sponsor-tile__badge">(.*?)</span>#s', $t[2], $b)
                ? html_entity_decode(trim(strip_tags($b[1])), ENT_QUOTES, 'UTF-8') : '';
            $style = str_contains($t[1], '--org') ? 'org' : (str_contains($t[1], '--mark') ? 'mark' : 'logo');
            $logos[] = [
                'src'     => $img[1],
                'name'    => $strip[$img[1]] ?? html_entity_decode($img[2], ENT_QUOTES, 'UTF-8'),
                'badge'   => $badge,
                'style'   => $style,
                'slider'  => isset($strip[$img[1]]),
                'section' => true,
            ];
            unset($strip[$img[1]]);
        }
    }
    foreach ($strip as $src => $name) {
        $logos[] = ['src' => $src, 'name' => $name, 'badge' => '', 'style' => 'logo', 'slider' => true, 'section' => false];
    }

    return ['logos' => cms_clean_logos($logos), 'placeholders' => min($placeholders, CMS_LOGO_MAX_PLACEHOLDERS)];
}

/**
 * Only the three partner logos the page ships with (Organiser, Managed By,
 * Digital Partner) carry a label, and always their original one. Every other
 * logo is a sponsor with no label, so it takes a slot in the top row. Applied
 * on save and on read, whatever the browser sent.
 */
function cms_fix_badges(array $logos): array
{
    $fixed = [];
    foreach (cms_default_logos()['logos'] as $d) {
        if ($d['badge'] !== '') {
            $fixed[$d['src']] = $d['badge'];
        }
    }
    foreach ($logos as $i => $l) {
        $logos[$i]['badge'] = $fixed[$l['src']] ?? '';
    }
    return $logos;
}

/**
 * The logo list in effect: the saved one, or the page's own.
 *
 * @return array{logos:array, placeholders:int, custom:bool}
 */
function cms_logo_settings(array $settings): array
{
    $saved = json_decode((string) ($settings['sponsor_logos'] ?? ''), true);
    if (is_array($saved)) {
        return [
            'logos'        => cms_fix_badges(cms_clean_logos($saved)),
            'placeholders' => max(0, min(CMS_LOGO_MAX_PLACEHOLDERS, (int) ($settings['sponsor_placeholders'] ?? 0))),
            'custom'       => true,
        ];
    }
    return cms_default_logos() + ['custom' => false];
}

/**
 * The settings every page's scripts need, with defaults filled in, from the
 * cache rather than the database.
 */
function cms_public_config(array $settings): array
{
    $prices = json_decode((string) ($settings['prices'] ?? ''), true);
    $fees = [];
    foreach (category_fees(is_array($prices) ? $prices : []) as $key => $c) {
        $fees[$key] = ['base' => (int) $c['base_paise'], 'early' => (int) $c['early_paise']];
    }

    $start = race_start((string) ($settings['race_start'] ?? ''));
    $until = early_bird_until((string) ($settings['early_until'] ?? ''));

    return [
        'raceStart'  => str_replace(' ', 'T', $start) . ':00+05:30',
        'raceDay'    => substr($start, 0, 10),
        'earlyUntil' => str_replace(' ', 'T', $until) . '+05:30',
        'prices'     => $fees,
    ];
}

/**
 * The JavaScript api/site.php sends: SITE_CONFIG for the page's own scripts,
 * then the saved edits for this page, applied once the page has parsed.
 *
 * Edited elements are hidden from the first paint until their new content is
 * in, so a visitor never sees the old text flash before the new.
 */
function cms_client_script(string $page, array $cache): string
{
    $settings = is_array($cache['settings'] ?? null) ? $cache['settings'] : [];
    $edits    = $cache['pages'][$page] ?? [];
    if (!is_array($edits)) {
        $edits = [];
    }

    $media = [];
    $heroImage = cms_clean_src((string) ($settings['hero_image'] ?? ''));
    $heroVideo = cms_clean_src((string) ($settings['hero_video'] ?? ''));
    if ($page === 'index') {
        if ($heroImage !== null) {
            $media['heroImage'] = $heroImage;
        }
        if ($heroVideo !== null) {
            $media['heroVideo'] = $heroVideo;
        }
        $logoSet = cms_logo_settings($settings);
        if ($logoSet['custom']) {
            $media['logos'] = $logoSet['logos'];
            $media['placeholders'] = $logoSet['placeholders'];
        }
    }

    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    $config = json_encode(cms_public_config($settings), $flags);
    $data   = json_encode((object) $edits, $flags);
    $mediaJ = json_encode((object) $media, $flags);

    return <<<JS
/* Milkha Singh Legacy Marathon: content saved in the admin panel. */
window.SITE_CONFIG = {$config};
(function () {
  var D = {$data}, M = {$mediaJ};
  window.SITE_CONTENT = D;

  var hide = [], css = '';
  for (var k in D) {
    if (Object.prototype.hasOwnProperty.call(D, k) && D[k].html !== undefined && k !== 'meta.title') {
      hide.push('[data-e="' + k.replace(/"/g, '') + '"]');
    }
  }
  if (M.heroImage) {
    var art = document.createElement('style');
    art.textContent = '.hero__bg{background-image:url("' + M.heroImage + '")!important}';
    document.head.appendChild(art);
  }
  // A saved logo list replaces both logo areas: keep the old logos out of sight
  // until the new ones are in.
  if (M.logos) hide.push('[data-e-list]');
  if (hide.length) {
    var veil = document.createElement('style');
    veil.id = 'site-content-veil';
    veil.textContent = hide.join(',') + '{visibility:hidden!important}';
    document.head.appendChild(veil);
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Draws the scrolling strip and the Sponsors & Partners grid from one list.
     Also used by the website editor to show a saved list straight away. */
  function renderLogos(logos, placeholders) {
    var slider = [], section = [], i, j, html;
    for (i = 0; i < logos.length; i++) {
      if (logos[i].slider) slider.push(logos[i]);
      if (logos[i].section) section.push(logos[i]);
    }

    var strip = document.querySelector('.marquee');
    var track = document.querySelector('[data-e-list="marquee"]');
    if (track) {
      if (strip) strip.style.display = slider.length ? '' : 'none';
      html = '';
      for (i = 0; i < 8 && slider.length; i++) {
        html += '<ul class="marquee__set"' + (i ? ' aria-hidden="true"' : '') + '>';
        for (j = 0; j < slider.length; j++) {
          html += '<li><img src="' + esc(slider[j].src) + '" alt="' + (i ? '' : esc(slider[j].name)) + '"' +
                  (i ? ' aria-hidden="true"' : '') + ' loading="lazy" decoding="async" /></li>';
        }
        html += '</ul>';
      }
      track.innerHTML = html;
    }

    /* Two rows. Sponsors (no label) fill the top-row slots, which show
       "Sponsor Logo" until a sponsor takes them. Partners (with a label such as
       Organiser or Managed By) follow underneath. */
    var grid = document.querySelector('[data-e-list="sponsors"]');
    if (grid) {
      var sponsors = [], partners = [];
      for (i = 0; i < section.length; i++) (section[i].badge ? partners : sponsors).push(section[i]);

      var tile = function (l) {
        var cls = l.style === 'org' ? 'sponsor-tile sponsor-tile--org'
                : 'sponsor-tile sponsor-tile--logo' + (l.style === 'mark' ? ' sponsor-tile--mark' : '');
        return '<div class="' + cls + '">' +
               (l.badge ? '<span class="sponsor-tile__badge">' + esc(l.badge) + '</span>' : '') +
               '<img src="' + esc(l.src) + '" alt="' + esc(l.name) + '" loading="lazy" decoding="async" /></div>';
      };

      html = '';
      for (i = 0; i < sponsors.length; i++) html += tile(sponsors[i]);
      for (i = sponsors.length; i < (placeholders || 0); i++) {
        html += '<div class="sponsor-tile"><span>Sponsor<br />Logo</span></div>';
      }
      for (i = 0; i < partners.length; i++) html += tile(partners[i]);
      grid.innerHTML = html;
    }
  }
  window.SITE_RENDER_LOGOS = renderLogos;

  function apply() {
    try {
      var els = document.querySelectorAll('[data-e]');
      for (var i = 0; i < els.length; i++) {
        var el = els[i], f = D[el.getAttribute('data-e')];
        if (!f) continue;
        if (f.html !== undefined) {
          if (el.tagName === 'TITLE') document.title = f.html;
          else el.innerHTML = f.html;
        }
        if (f.href !== undefined) el.setAttribute('href', f.href);
        if (f.src !== undefined) { el.setAttribute('src', f.src); el.removeAttribute('srcset'); }
        if (f.alt !== undefined) el.setAttribute('alt', f.alt);
        if (f.content !== undefined) el.setAttribute('content', f.content);
        if (f.label !== undefined) el.setAttribute('aria-label', f.label);
      }

      if (M.logos) renderLogos(M.logos, M.placeholders);

      if (M.heroVideo) {
        var v = document.getElementById('heroVideo');
        if (v) v.setAttribute('data-src', M.heroVideo);
      }
    } finally {
      var veilEl = document.getElementById('site-content-veil');
      if (veilEl) veilEl.parentNode.removeChild(veilEl);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();
})();

JS;
}

/**
 * The editor page: the real page file, with a <base> so its images, styles and
 * scripts load from the site root, its forms made inert, and the editor added.
 */
function cms_editor_html(string $page, string $csrf, string $userName): string
{
    $html = cms_template($page);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('#<head>#i', "<head>\n<base href=\"../\" />", $html, 1) ?? $html;

    // The registration and sponsor scripts would submit real entries.
    $html = preg_replace('#<script[^>]+src="assets/js/(register|sponsor)\.js[^"]*"[^>]*>\s*</script>\s*#i', '', $html) ?? $html;

    $pages = [];
    foreach (CMS_PAGES as $key => $label) {
        $pages[] = ['key' => $key, 'label' => $label];
    }
    $boot = json_encode([
        'page'   => $page,
        'label'  => CMS_PAGES[$page],
        'pages'  => $pages,
        'csrf'   => $csrf,
        'user'   => $userName,
        'api'    => 'admin/content-api.php',
        'hub'    => 'admin/website.php',
        'events' => 'admin/event-settings.php',
        // Read from the same cache the public pages use, so the dialog opens on
        // exactly what visitors see.
        'logos'  => cms_logo_settings(is_array(cms_read_cache()['settings'] ?? null) ? cms_read_cache()['settings'] : []),
        'uploadLimit' => cms_upload_limit_bytes(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

    $v = '20260917-5';
    $inject = "\n<link rel=\"stylesheet\" href=\"admin/assets/editor.css?v={$v}\" />\n"
            . "<script>window.CMS_EDITOR = {$boot};</script>\n"
            . "<script src=\"admin/assets/editor.js?v={$v}\"></script>\n";

    $pos = strripos($html, '</body>');
    return $pos === false ? $html . $inject : substr($html, 0, $pos) . $inject . substr($html, $pos);
}
