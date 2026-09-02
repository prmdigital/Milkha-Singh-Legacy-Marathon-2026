<?php
/**
 * POST /api/webhook.php  —  called by Razorpay, not by a browser.
 *
 * Closes the hole that verify-payment.php cannot: if the runner's phone dies,
 * their battery goes, or they close the tab the instant after paying, the
 * browser never comes back to confirm and the row would sit at 'pending'
 * forever even though the money was taken.
 *
 * Razorpay retries this endpoint until it gets a 2xx, so it must be safe to
 * run the same event twice.
 *
 * Set it up: Razorpay dashboard > Settings > Webhooks
 *   URL     https://milkhasinghlegacymarathon.com/api/webhook.php
 *   Events  payment.captured, payment.failed
 *   Secret  paste into RAZORPAY_WEBHOOK_SECRET in marathon-config.php
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false]));
}

// The signature covers the RAW body, so it must be read before anything
// touches or re-encodes it.
$raw    = file_get_contents('php://input') ?: '';
$sent   = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
$secret = (string) cfg('RAZORPAY_WEBHOOK_SECRET', '');

if ($secret === '') {
    // Better to reject loudly than to accept unsigned calls: this endpoint can
    // mark registrations paid.
    error_log('[marathon-webhook] RAZORPAY_WEBHOOK_SECRET is not set — rejecting.');
    http_response_code(503);
    exit(json_encode(['ok' => false]));
}

if ($sent === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sent)) {
    error_log('[marathon-webhook] bad signature from ' . client_ip());
    http_response_code(400);
    exit(json_encode(['ok' => false]));
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    exit(json_encode(['ok' => false]));
}

$type    = (string) ($event['event'] ?? '');
$payment = $event['payload']['payment']['entity'] ?? null;

if (!is_array($payment)) {
    // Nothing to act on, but it was a valid signed call — acknowledge it so
    // Razorpay stops retrying.
    exit(json_encode(['ok' => true, 'ignored' => $type]));
}

$orderId   = (string) ($payment['order_id'] ?? '');
$paymentId = (string) ($payment['id'] ?? '');

if ($orderId === '' || $paymentId === '') {
    exit(json_encode(['ok' => true, 'ignored' => 'no order id']));
}

try {
    $st = db()->prepare('SELECT * FROM registrations WHERE razorpay_order_id = ? LIMIT 1');
    $st->execute([$orderId]);
    $reg = $st->fetch();
} catch (Throwable $e) {
    error_log('[marathon-webhook] SELECT failed: ' . $e->getMessage());
    http_response_code(500);                 // let Razorpay retry
    exit(json_encode(['ok' => false]));
}

if (!$reg) {
    // A payment we have no record of. Acknowledge so it stops retrying, but
    // log it loudly — this needs a human.
    error_log('[marathon-webhook] no registration for order ' . $orderId);
    exit(json_encode(['ok' => true, 'unknown_order' => true]));
}

if ($type === 'payment.captured') {

    if ($reg['status'] === 'paid') {
        // The browser already confirmed it. Nothing to do, and above all no
        // second confirmation email.
        exit(json_encode(['ok' => true, 'already' => true]));
    }

    try {
        db()->prepare(
            'UPDATE registrations
                SET status = "paid", razorpay_payment_id = :pid, paid_at = CURRENT_TIMESTAMP
              WHERE razorpay_order_id = :oid AND status <> "paid"'
        )->execute([':pid' => $paymentId, ':oid' => $orderId]);
    } catch (Throwable $e) {
        error_log('[marathon-webhook] UPDATE failed: ' . $e->getMessage());
        http_response_code(500);
        exit(json_encode(['ok' => false]));
    }

    // Only mail if the browser flow did not already do it.
    if ((int) $reg['receipt_emailed'] !== 1) {
        try {
            send_confirmation($reg, $paymentId);
        } catch (Throwable $e) {
            error_log('[marathon-webhook] email threw: ' . $e->getMessage());
        }
    }

    exit(json_encode(['ok' => true]));
}

if ($type === 'payment.failed') {
    // Never overwrite a paid row: a failed retry can arrive after a successful
    // one, and that must not un-register someone who has paid.
    try {
        db()->prepare(
            'UPDATE registrations
                SET status = "failed", razorpay_payment_id = :pid
              WHERE razorpay_order_id = :oid AND status = "pending"'
        )->execute([':pid' => $paymentId, ':oid' => $orderId]);
    } catch (Throwable $e) {
        error_log('[marathon-webhook] UPDATE failed-status: ' . $e->getMessage());
        http_response_code(500);
        exit(json_encode(['ok' => false]));
    }

    exit(json_encode(['ok' => true]));
}

exit(json_encode(['ok' => true, 'ignored' => $type]));
