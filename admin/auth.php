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
const ROLE_PERMISSIONS = [
    'owner' => [
        'view_registrations', 'export_csv', 'view_id_proof', 'mark_paid',
        'manage_users', 'manage_settings', 'view_audit',
    ],
    'manager' => [
        'view_registrations', 'export_csv', 'view_id_proof', 'mark_paid',
        'view_audit',
    ],
    'viewer' => [
        'view_registrations',
    ],
];

const ROLE_LABELS = [
    'owner'   => 'Owner',
    'manager' => 'Manager',
    'viewer'  => 'Viewer',
];

const ROLE_DESCRIPTIONS = [
    'owner'   => 'Everything, including adding and removing admin users.',
    'manager' => 'Registrations, ID proofs, CSV export and recording payments.',
    'viewer'  => 'Read only. Cannot export or open ID proofs.',
];

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
           . '<link rel="stylesheet" href="assets/admin.css?v=20260905-1">'
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
