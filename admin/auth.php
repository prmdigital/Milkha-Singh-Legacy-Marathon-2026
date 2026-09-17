<?php
/**
 * Admin session, login throttling, CSRF and audit logging.
 *
 * Every admin page starts by requiring this file. Nothing here is reachable
 * without a valid session except the login form itself.
 */

declare(strict_types=1);

// Tells lib.php to render errors as text, not JSON.
define('MARATHON_ADMIN', true);

require_once __DIR__ . '/../api/lib.php';

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

function admin_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('MSLM_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,          // dies with the browser
        'path'     => '/admin/',  // never sent to the public site or the API
        'secure'   => $https,
        'httponly' => true,       // JavaScript cannot read it
        'samesite' => 'Strict',   // blocks cross-site request forgery outright
    ]);
    session_start();

    // Idle timeout: an unattended laptop should not stay logged in all day.
    $idle = 60 * 60 * 2;
    if (isset($_SESSION['seen']) && time() - (int) $_SESSION['seen'] > $idle) {
        admin_destroy();
        session_start();
    }
    $_SESSION['seen'] = time();
}

function admin_destroy(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], true);
    }
    session_destroy();
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_ok']);
}

/** Guard for every page except the login form. */
function require_admin(): void
{
    admin_boot();
    if (!admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
    no_store();
}

/** Registration data must never sit in a shared or browser cache. */
function no_store(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------------------
// Roles
// ---------------------------------------------------------------------------

/**
 * What each role may do.
 *
 * Kept as one map rather than scattered role checks, so "who can do X" has a
 * single answer and a new role cannot silently inherit a permission nobody
 * meant to grant.
 *
 * viewer deliberately has neither export nor ID proofs: those are the two ways
 * personal data leaves the building in bulk.
 */
/* Managers and Editors work the registration desk. Sponsors, the Activity log,
   payment Settings and managing users belong to the Admin (the setup/owner
   account) and Administrators only, at the client's request. Each of those
   pages calls require_can() itself, so a typed URL is refused as well as the
   nav link being hidden. */
const BASE_PERMISSIONS = [
    'view_registrations', 'export_csv', 'view_id_proof', 'mark_paid',
];

const ADMIN_PERMISSIONS = [
    ...BASE_PERMISSIONS,
    'view_sponsors', 'manage_sponsors', 'manage_settings', 'view_audit', 'manage_users',
    'edit_content',   // the website editor: page text, images, fees and dates
];

const ROLE_PERMISSIONS = [
    'owner'         => ADMIN_PERMISSIONS,
    'administrator' => ADMIN_PERMISSIONS,
    'manager'       => BASE_PERMISSIONS,
    'editor'        => BASE_PERMISSIONS,
    // Legacy role from before Manager/Editor; no longer offered.
    'viewer'        => BASE_PERMISSIONS,
];

const ROLE_LABELS = [
    'owner'         => 'Admin',
    'administrator' => 'Administrator',
    'manager'       => 'Manager',
    'editor'        => 'Editor',
    'viewer'        => 'Viewer',
];

/** The roles offered when adding or editing a user, in picker order. */
const ASSIGNABLE_ROLES = ['administrator', 'manager', 'editor'];

const ROLE_DESCRIPTIONS = [
    'administrator' => 'Everything: registrations, sponsors, activity log, editing the website, payment settings, and adding users.',
    'manager'       => 'Registrations only: view, export, ID proofs and recording payments.',
    'editor'        => 'Registrations only: view, export, ID proofs and recording payments.',
];

/** Admin and Administrator are the only roles that may manage users. */
function role_manages_users(string $role): bool
{
    return in_array('manage_users', ROLE_PERMISSIONS[$role] ?? [], true);
}

function current_user(): array
{
    return [
        'id'       => (int) ($_SESSION['admin_id'] ?? 0),
        'username' => (string) ($_SESSION['admin_user'] ?? ''),
        'name'     => (string) ($_SESSION['admin_name'] ?? ''),
        'role'     => (string) ($_SESSION['admin_role'] ?? 'viewer'),
    ];
}

function current_role(): string
{
    return (string) ($_SESSION['admin_role'] ?? 'viewer');
}

function can(string $permission): bool
{
    return in_array($permission, ROLE_PERMISSIONS[current_role()] ?? [], true);
}

/**
 * Hard stop for a page the current role may not open.
 *
 * Every page calls this for itself. Hiding a link in the nav is presentation,
 * not access control: the URL is still typeable.
 */
function require_can(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        no_store();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Not allowed</title>'
           . '<link rel="stylesheet" href="assets/admin.css?v=20260917-4">'
           . '<main class="wrap"><p class="empty">'
           . 'Your account does not have access to that. '
           . '<a href="index.php">Back to registrations</a>.'
           . '</p></main>';
        exit;
    }
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

/** True once at least one admin user exists, which a fresh install has not. */
function has_db_users(): bool
{
    try {
        return (int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;   // table not created yet
    }
}

/**
 * Makes sure the admin_users table exists, creating it if it does not.
 *
 * The table arrived after the first release, so a site set up before then has
 * no such table — and the Users page, the one place staff accounts are made,
 * crashed with a 500 on its first query. Only an owner reaches this (the
 * Users page checks manage_users first), and every statement is idempotent:
 * CREATE ... IF NOT EXISTS, and the ALTERs fail harmlessly when the column is
 * already there.
 *
 * @return bool true when the table is usable
 */
function ensure_admin_users_table(): bool
{
    try {
        db()->query('SELECT 1 FROM admin_users LIMIT 1');
        ensure_role_values();
        return true;
    } catch (Throwable $e) {
        // Missing: fall through and create it.
    }

    $ddl = [
        "CREATE TABLE IF NOT EXISTS admin_users (
          id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username      VARCHAR(40)  NOT NULL,
          full_name     VARCHAR(120) NOT NULL,
          email         VARCHAR(190) DEFAULT NULL,
          password_hash VARCHAR(255) NOT NULL,
          role          ENUM('owner','administrator','manager','editor','viewer') NOT NULL DEFAULT 'editor',
          is_active     TINYINT(1)   NOT NULL DEFAULT 1,
          must_change   TINYINT(1)   NOT NULL DEFAULT 0,
          created_by    INT UNSIGNED DEFAULT NULL,
          created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_login_at DATETIME     DEFAULT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_username (username),
          KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE admin_audit ADD COLUMN actor VARCHAR(40) DEFAULT NULL AFTER action",
        "ALTER TABLE admin_login_attempts ADD COLUMN username VARCHAR(40) DEFAULT NULL AFTER ip_address",
    ];

    foreach ($ddl as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
            error_log('[marathon-admin] ensure admin_users: ' . $e->getMessage());
        }
    }

    try {
        db()->query('SELECT 1 FROM admin_users LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;   // no CREATE permission: the page shows the SQL instead
    }
}

/**
 * Widens the role column on a table created before Administrator and Editor
 * existed, so saving one of those roles is not rejected by MySQL. Checks the
 * column first, so the ALTER runs once, not on every page load.
 */
function ensure_role_values(): void
{
    try {
        $col  = db()->query("SHOW COLUMNS FROM admin_users LIKE 'role'")->fetch();
        $type = (string) ($col['Type'] ?? '');
        if ($type !== '' && (!str_contains($type, "'administrator'") || !str_contains($type, "'editor'"))) {
            db()->exec(
                "ALTER TABLE admin_users MODIFY COLUMN role
                   ENUM('owner','administrator','manager','editor','viewer') NOT NULL DEFAULT 'editor'"
            );
        }
    } catch (Throwable $e) {
        error_log('[marathon-admin] widen role column: ' . $e->getMessage());
    }
}

/**
 * Checks a username and password.
 *
 * Falls back to the single credential in marathon-config.php while no admin
 * user exists in the database. That is what stops an existing install locking
 * itself out the moment this lands, and it stops applying the instant a real
 * owner account exists.
 *
 * @return array|null the user row, or null when the credentials are wrong
 */
function authenticate(string $username, string $password): ?array
{
    if (has_db_users()) {
        $st = db()->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $u = $st->fetch();

        // The comparison runs against a dummy hash when the username is
        // unknown, so a wrong username and a wrong password take the same time
        // and cannot be told apart by timing them.
        $hash = $u ? (string) $u['password_hash'] : '$2y$10$usesomesillystringfoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $ok   = password_verify($password, $hash);

        if (!$u || !$ok || (int) $u['is_active'] !== 1) {
            return null;
        }
        return $u;
    }

    $cfgUser = (string) cfg('ADMIN_USER', '');
    $cfgHash = (string) cfg('ADMIN_PASSWORD_HASH', '');

    if ($cfgUser === '' || $cfgHash === ''
        || !hash_equals($cfgUser, $username)
        || !password_verify($password, $cfgHash)) {
        return null;
    }

    return [
        'id' => 0, 'username' => $cfgUser, 'full_name' => 'Owner',
        'role' => 'owner', 'is_active' => 1, 'must_change' => 0,
    ];
}

function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? ucfirst($role);
}

// ---------------------------------------------------------------------------
// Login throttling
// ---------------------------------------------------------------------------

const LOGIN_MAX_FAILS  = 8;
const LOGIN_WINDOW_MIN = 15;

function login_blocked(string $ip): bool
{
    // The cutoff is worked out in PHP rather than with MySQL's INTERVAL syntax,
    // so the same query runs against SQLite when the site is run locally.
    $cutoff = (new DateTimeImmutable('-' . LOGIN_WINDOW_MIN . ' minutes'))->format('Y-m-d H:i:s');

    $st = db()->prepare(
        'SELECT COUNT(*) FROM admin_login_attempts
          WHERE ip_address = ? AND succeeded = 0 AND attempted_at > ?'
    );
    $st->execute([$ip, $cutoff]);
    return (int) $st->fetchColumn() >= LOGIN_MAX_FAILS;
}

function login_record(string $ip, bool $ok, string $username = ''): void
{
    try {
        $st = db()->prepare(
            'INSERT INTO admin_login_attempts (ip_address, username, succeeded) VALUES (?, ?, ?)'
        );
        $st->execute([$ip, $username !== '' ? mb_substr($username, 0, 40) : null, $ok ? 1 : 0]);
    } catch (Throwable $e) {
        // An install without the username column still throttles.
        $st = db()->prepare('INSERT INTO admin_login_attempts (ip_address, succeeded) VALUES (?, ?)');
        $st->execute([$ip, $ok ? 1 : 0]);
    }

    if ($ok) {
        // A success clears the slate so one fat-fingered morning does not lock
        // you out for the rest of the window.
        $c = db()->prepare('DELETE FROM admin_login_attempts WHERE ip_address = ? AND succeeded = 0');
        $c->execute([$ip]);
    }
}

// ---------------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------------

function audit(string $action, ?string $subject = null): void
{
    try {
        $st = db()->prepare(
            'INSERT INTO admin_audit (action, actor, subject, ip_address) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$action, ($_SESSION['admin_user'] ?? null), $subject, client_ip()]);
    } catch (Throwable $e) {
        error_log('[marathon-admin] audit failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// View helpers
// ---------------------------------------------------------------------------

/** Escape for HTML. Everything below is runner-supplied and untrusted. */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $paise): string
{
    return '₹' . number_format($paise / 100, 0);
}

function when(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    return date('j M Y, g:i A', strtotime($dt));
}

/**
 * 'awaiting' and 'pending' both mean unpaid but call for opposite actions, so
 * they must never share a label. Awaiting = ring them. Pending = they walked
 * away from the gateway and the webhook may still settle it.
 */
function status_label(string $status): string
{
    $map = [
        'awaiting' => 'Awaiting payment',
        'paid'     => 'Paid',
        'free'     => 'Free entry',
        'pending'  => 'Abandoned',
        'failed'   => 'Failed',
    ];
    return $map[$status] ?? ucfirst($status);
}

function cat_label(string $key): string
{
    return CATEGORIES[$key]['label'] ?? $key;
}
