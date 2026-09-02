<?php
/**
 * CSV of the current view, for the timing partner and kit desk.
 *
 * Uses the same filters as the list, so the file always matches what you were
 * looking at when you clicked Download.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/query.php';

require_admin();

[$where, $params] = reg_filters($_GET);

$sql = 'SELECT * FROM registrations' . $where . reg_sort($_GET);
$st  = db()->prepare($sql);
$st->execute($params);

audit('export_csv', $where === '' ? 'all' : 'filtered');

$file = 'marathon-registrations-' . date('Y-m-d-Hi') . '.csv';

no_store();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');

$out = fopen('php://output', 'w');

// PHP 8.4 deprecates fputcsv() without an explicit $escape, and the notice it
// prints lands in the middle of the download and corrupts the file. Passing ''
// also turns off PHP's non-standard backslash escaping, which is what
// Excel and Google Sheets actually expect (RFC 4180).
$csv = static function ($row) use ($out): void {
    fputcsv($out, $row, ',', '"', '');
};

// UTF-8 BOM so Excel opens Indian names correctly instead of mangling them.
fwrite($out, "\xEF\xBB\xBF");

$csv([
    'Registration ID',
    'Registered on',
    'Full name',
    'Email',
    'Mobile',
    'Age',
    'Gender',
    'City',
    'Category',
    'Distance',
    'Assembly / flag-off',
    'T-shirt size',
    'ID proof type',
    'ID proof uploaded',
    'Emergency name',
    'Emergency phone',
    'Amount (INR)',
    'Early bird',
    'Status',
    'Paid at',
    'Razorpay order ID',
    'Razorpay payment ID',
]);

while ($r = $st->fetch()) {
    $cat = (string) $r['category'];

    $csv([
        $r['registration_id'],
        $r['created_at'],
        $r['full_name'],
        $r['email'],
        // Leading apostrophe stops Excel dropping the leading digit or turning
        // a long number into 9.19E+11.
        "'" . $r['mobile'],
        $r['age'],
        $r['gender'],
        $r['city'],
        cat_label($cat),
        CATEGORIES[$cat]['distance'] ?? '',
        RACE_TIMES[$cat] ?? '',
        $r['tshirt_size'],
        $r['id_proof_type'],
        $r['id_proof_file'] ? 'Yes' : 'No',
        $r['emergency_name'],
        $r['emergency_phone'] ? "'" . $r['emergency_phone'] : '',
        number_format((int) $r['amount_paise'] / 100, 2, '.', ''),
        ((int) $r['early_bird'] === 1) ? 'Yes' : 'No',
        ucfirst((string) $r['status']),
        $r['paid_at'],
        $r['razorpay_order_id'],
        $r['razorpay_payment_id'],
    ]);
}

fclose($out);
