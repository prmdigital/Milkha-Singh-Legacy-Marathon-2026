<?php
/**
 * POST /api/register.php
 *
 * Registration WITHOUT online payment. The runner submits their details, the
 * entry is stored as 'awaiting', and the team contacts them to collect the fee.
 *
 * The amount is still worked out and stored, so the admin panel shows exactly
 * what is owed rather than leaving someone to look it up per runner.
 *
 * Request : same shape as create-order.php (multipart, carries the ID proof)
 * Response: { ok, registrationId, amountDue, category, mode: "offline" }
 *
 * When PAYMENTS_ENABLED is switched back on this endpoint stops accepting
 * entries and tells the browser to use the Razorpay flow instead, so the two
 * can never both be live and disagree about whether someone has paid.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

send_cors();
require_post();

// Checked BEFORE the upload is stored, so a rejected request does not leave an
// orphaned ID scan on disk.
if (payments_enabled()) {
    http_response_code(409);
    echo json_encode([
        'ok'             => false,
        'error'          => 'Online payment is active for this event.',
        'usePaymentFlow' => true,
    ]);
    exit;
}

$in = json_body();
[$runner, $errors] = validate_runner($in);

[$idFile, $idFileError] = store_id_proof();
if ($idFileError !== null) {
    $errors['idProofFile'] = $idFileError;
}

if ($errors) {
    // Nothing was written, but the upload may already have landed. Remove it so
    // failed attempts do not pile up identity documents nobody can act on.
    discard_id_proof($idFile);

    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please check the form.', 'fields' => $errors]);
    exit;
}

$price = price_for($runner['category']);

// A genuinely free category is confirmed outright; there is nothing to collect.
$status = $price['payable'] > 0 ? 'awaiting' : 'free';

$registrationId = new_registration_id();

try {
    $stmt = db()->prepare(
        'INSERT INTO registrations
            (registration_id, category, full_name, email, mobile, age, gender, city,
             tshirt_size, id_proof_type, id_proof_file, emergency_name, emergency_phone,
             amount_paise, early_bird, status, ip_address)
         VALUES
            (:rid, :cat, :name, :email, :mobile, :age, :gender, :city,
             :tshirt, :idtype, :idfile, :ename, :ephone,
             :amount, :early, :status, :ip)'
    );
    $stmt->execute([
        ':rid'    => $registrationId,
        ':cat'    => $runner['category'],
        ':name'   => $runner['full_name'],
        ':email'  => $runner['email'],
        ':mobile' => $runner['mobile'],
        ':age'    => $runner['age'],
        ':gender' => $runner['gender'],
        ':city'   => $runner['city'],
        ':tshirt' => $runner['tshirt_size'],
        ':idtype' => $runner['id_proof_type'],
        ':idfile' => $idFile,
        ':ename'  => $runner['emergency_name'] ?: null,
        ':ephone' => $runner['emergency_phone'] ?: null,
        ':amount' => $price['payable'],
        ':early'  => $price['early'] ? 1 : 0,
        ':status' => $status,
        ':ip'     => client_ip(),
    ]);
} catch (Throwable $e) {
    discard_id_proof($idFile);
    fail(500, 'Could not save your registration. Please try again.', 'INSERT register: ' . $e->getMessage());
}

// The runner is told straight away; the email goes out after the response, so a
// slow mail server never becomes a slow registration form.
respond_then([
    'mode'           => 'offline',
    'registrationId' => $registrationId,
    'amountDue'      => $price['payable'],
    'category'       => $price['label'],
], static function () use ($registrationId): void {
    $stmt = db()->prepare('SELECT * FROM registrations WHERE registration_id = ?');
    $stmt->execute([$registrationId]);
    $reg = $stmt->fetch();
    if ($reg) {
        send_confirmation($reg);
    }
});
