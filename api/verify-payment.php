<?php
/**
 * POST /api/verify-payment.php
 *
 * Called by the browser once Razorpay reports a successful payment. Verifies
 * the signature, flips the registration to 'paid' and emails the confirmation.
 *
 * Request : { razorpay_order_id, razorpay_payment_id, razorpay_signature }
 * Response: { ok, registrationId }
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

send_cors();
require_post();

$in = json_body();

$orderId   = trim((string) ($in['razorpay_order_id'] ?? ''));
$paymentId = trim((string) ($in['razorpay_payment_id'] ?? ''));
$signature = trim((string) ($in['razorpay_signature'] ?? ''));

if ($orderId === '' || $paymentId === '' || $signature === '') {
    fail(400, 'Incomplete payment details.');
}

// ---- Verify the signature BEFORE trusting anything ------------------------
// Razorpay signs "<order_id>|<payment_id>" with the key secret. Without this
// check anyone could POST an order id and mark themselves paid.
$expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) cfg('RAZORPAY_KEY_SECRET'));

if (!hash_equals($expected, $signature)) {
    fail(400, 'We could not verify that payment.', 'Bad signature for order ' . $orderId);
}

// ---- Find the pending registration ---------------------------------------
try {
    $stmt = db()->prepare('SELECT * FROM registrations WHERE razorpay_order_id = :oid LIMIT 1');
    $stmt->execute([':oid' => $orderId]);
    $reg = $stmt->fetch();
} catch (Throwable $e) {
    fail(500, 'Could not confirm your registration.', 'SELECT: ' . $e->getMessage());
}

if (!$reg) {
    fail(404, 'We could not find that registration.', 'No row for order ' . $orderId);
}

// Already done — the webhook usually wins the race. Report success rather than
// an error, and do not send a second email.
if ($reg['status'] === 'paid') {
    ok(['registrationId' => $reg['registration_id'], 'alreadyConfirmed' => true]);
}

try {
    db()->prepare(
        'UPDATE registrations
            SET status = "paid", razorpay_payment_id = :pid, paid_at = NOW()
          WHERE razorpay_order_id = :oid AND status <> "paid"'
    )->execute([':pid' => $paymentId, ':oid' => $orderId]);
} catch (Throwable $e) {
    fail(500, 'Could not confirm your registration.', 'UPDATE paid: ' . $e->getMessage());
}

// ---- Confirmation email (best effort) ------------------------------------
// The money is taken and the row is saved. A mail failure must not turn this
// into an error for someone who has already paid.
try {
    send_confirmation($reg, $paymentId);
} catch (Throwable $e) {
    error_log('[marathon-api] confirmation email threw: ' . $e->getMessage());
}

ok(['registrationId' => $reg['registration_id']]);

