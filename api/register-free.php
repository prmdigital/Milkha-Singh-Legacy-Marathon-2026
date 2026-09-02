<?php
/**
 * POST /api/register-free.php
 *
 * The 1 KM Disabled Category is free, so it skips Razorpay entirely and is
 * stored straight away with status 'free'.
 *
 * Request : same shape as create-order.php, with category = "para"
 * Response: { ok, registrationId }
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

send_cors();
require_post();

$in = json_body();
[$runner, $errors] = validate_runner($in);

// The uploaded ID is checked alongside the text fields so every problem comes
// back in one response rather than one per submit.
[$idFile, $idFileError] = store_id_proof();
if ($idFileError !== null) {
    $errors['idProofFile'] = $idFileError;
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please check the form.', 'fields' => $errors]);
    exit;
}

// Guard the endpoint: only genuinely free categories may use it, or a paid
// entry could be booked for nothing by posting here instead.
$price = price_for($runner['category']);
if ($price['payable'] > 0) {
    fail(400, 'That category requires payment.');
}

$registrationId = new_registration_id();

try {
    $stmt = db()->prepare(
        'INSERT INTO registrations
            (registration_id, category, full_name, email, mobile, age, gender, city,
             tshirt_size, id_proof_type, id_proof_file, emergency_name, emergency_phone,
             amount_paise, early_bird, status, ip_address, paid_at)
         VALUES
            (:rid, :cat, :name, :email, :mobile, :age, :gender, :city,
             :tshirt, :idtype, :idfile, :ename, :ephone,
             0, 0, "free", :ip, NOW())'
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
        ':ip'     => client_ip(),
    ]);
} catch (Throwable $e) {
    fail(500, 'Could not save your registration. Please try again.', 'INSERT free: ' . $e->getMessage());
}

// Confirmation email, best effort — the entry is already saved.
try {
    $stmt = db()->prepare('SELECT * FROM registrations WHERE registration_id = :rid LIMIT 1');
    $stmt->execute([':rid' => $registrationId]);
    if ($row = $stmt->fetch()) {
        send_confirmation($row);
    }
} catch (Throwable $e) {
    error_log('[marathon-api] free confirmation failed: ' . $e->getMessage());
}

ok(['registrationId' => $registrationId]);
