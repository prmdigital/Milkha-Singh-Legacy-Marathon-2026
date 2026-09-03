<?php
/**
 * POST /api/create-order.php
 *
 * Validates the runner, works out the price ITSELF, writes a 'pending' row,
 * then asks Razorpay for an order.
 *
 * Request : { fullName, email, mobile, age, gender, city, category,
 *             tshirtSize, idProofType, emergencyPhone?, declaration }
 * Response: { ok, keyId, orderId, amount, currency, registrationId, category }
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';

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

// The free category never touches Razorpay.
if ($runner['category'] === 'para') {
    fail(400, 'The 1 KM category is free — use the free registration endpoint.');
}

// Price comes from the server's own table, never from the request body.
$price = price_for($runner['category']);
if ($price['payable'] <= 0) {
    fail(400, 'That category does not require payment.');
}

// Razorpay rejects anything under 100 paise. Catching it here turns a confusing
// gateway error into a clear one, and saves a round trip. It cannot happen with
// today's prices, but a future category priced at a rupee would trip it.
if ($price['payable'] < 100) {
    fail(400, 'That entry fee is too small to collect online.',
        'Amount below Razorpay minimum: ' . $price['payable'] . ' paise');
}

$registrationId = new_registration_id();

// Written before checkout opens, so a payment can always be traced to a person
// even if the browser is closed mid-transaction.
try {
    $stmt = db()->prepare(
        'INSERT INTO registrations
            (registration_id, category, full_name, email, mobile, age, gender, city,
             tshirt_size, id_proof_type, id_proof_file, emergency_name, emergency_phone,
             amount_paise, early_bird, status, ip_address)
         VALUES
            (:rid, :cat, :name, :email, :mobile, :age, :gender, :city,
             :tshirt, :idtype, :idfile, :ename, :ephone,
             :amount, :early, "pending", :ip)'
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
        ':ip'     => client_ip(),
    ]);
} catch (Throwable $e) {
    fail(500, 'Could not start your registration. Please try again.', 'INSERT: ' . $e->getMessage());
}

// ---- Ask Razorpay for an order -------------------------------------------

$order = [
    'amount'          => $price['payable'],
    'currency'        => 'INR',
    'receipt'         => $registrationId,
    'payment_capture' => 1,
    'notes'           => [
        'event'           => 'Milkha Singh Legacy Marathon 2026',
        'registration_id' => $registrationId,
        'category'        => $price['label'],
        'early_bird'      => $price['early'] ? 'yes' : 'no',
    ],
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($order),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => cfg('RAZORPAY_KEY_ID') . ':' . cfg('RAZORPAY_KEY_SECRET'),
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    fail(502, 'Could not reach the payment gateway. Please try again.', 'cURL: ' . $curlErr);
}

$body = json_decode((string) $response, true);

// Wrong or missing keys are OUR fault, not the runner's, and no amount of
// retrying will fix them. Separating this from a general gateway failure means
// the error log says "check the keys" instead of "try again".
if ($httpCode === 401) {
    fail(500, 'Online payment is not set up correctly. Please contact us.',
        'Razorpay rejected the API keys (401). Check RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.');
}

if ($httpCode !== 200 || empty($body['id'])) {
    $detail = $body['error']['description'] ?? ('HTTP ' . $httpCode);
    fail(502, 'The payment gateway rejected the request. Please try again.', 'Razorpay: ' . $detail);
}

// Link the order back to the pending row.
try {
    db()->prepare('UPDATE registrations SET razorpay_order_id = :oid WHERE registration_id = :rid')
        ->execute([':oid' => $body['id'], ':rid' => $registrationId]);
} catch (Throwable $e) {
    fail(500, 'Could not start your registration. Please try again.', 'UPDATE order id: ' . $e->getMessage());
}

ok([
    // Publishable key — safe in the browser. The secret never leaves the server.
    'keyId'          => cfg('RAZORPAY_KEY_ID'),
    'orderId'        => $body['id'],
    'amount'         => (int) $body['amount'],
    'currency'       => $body['currency'],
    'registrationId' => $registrationId,
    'category'       => $price['label'],
]);
