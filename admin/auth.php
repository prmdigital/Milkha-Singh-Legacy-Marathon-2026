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

function login_record(string $ip, bool $ok): void
{
    $st = db()->prepare('INSERT INTO admin_login_attempts (ip_address, succeeded) VALUES (?, ?)');
    $st->execute([$ip, $ok ? 1 : 0]);

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
        $st = db()->prepare('INSERT INTO admin_audit (action, subject, ip_address) VALUES (?, ?, ?)');
        $st->execute([$action, $subject, client_ip()]);
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
