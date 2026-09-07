<?php
/**
 * POST /api/sponsor.php
 *
 * A sponsorship enquiry from the "Become a Sponsor" form. Nothing is charged
 * and nothing is confirmed here — the row is a lead for the team to call back,
 * which is why it lands with status 'new' rather than anything more committal.
 *
 * Request : { company, contactName, designation, email, mobile, website,
 *             city, tier, budget, message }
 * Response: { ok, reference }
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

send_cors();
require_post();

$in = json_body();
[$sponsor, $errors] = validate_sponsor($in);

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please check the form.', 'fields' => $errors]);
    exit;
}

$reference = new_sponsor_ref();

try {
    $stmt = db()->prepare(
        'INSERT INTO sponsor_enquiries
            (reference, company, contact_name, designation, email, mobile,
             website, city, tier, budget, message, status, ip_address)
         VALUES
            (:ref, :company, :name, :desig, :email, :mobile,
             :website, :city, :tier, :budget, :message, "new", :ip)'
    );
    $stmt->execute([
        ':ref'     => $reference,
        ':company' => $sponsor['company'],
        ':name'    => $sponsor['contact_name'],
        ':desig'   => $sponsor['designation'],
        ':email'   => $sponsor['email'],
        ':mobile'  => $sponsor['mobile'],
        ':website' => $sponsor['website'],
        ':city'    => $sponsor['city'],
        ':tier'    => $sponsor['tier'],
        ':budget'  => $sponsor['budget'],
        ':message' => $sponsor['message'],
        ':ip'      => client_ip(),
    ]);
} catch (Throwable $e) {
    fail(500, 'Could not save your enquiry. Please try again.', 'sponsor insert: ' . $e->getMessage());
}

/* The enquiry is saved by this point, so the browser gets its answer first and
   the two emails go out afterwards. Neither of them is something the sender
   should be made to sit and wait for. */
respond_then(['reference' => $reference], static function () use ($sponsor, $reference) {
    $tier   = SPONSOR_TIERS[$sponsor['tier']] ?? $sponsor['tier'];
    $budget = SPONSOR_BUDGETS[$sponsor['budget']] ?? '';

    // 1. Acknowledgement to the enquirer.
    $ack = '<p>Dear ' . htmlspecialchars($sponsor['contact_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
         . '<p>Thank you for your interest in sponsoring The Flying Sikh Milkha Singh '
         . 'Legacy Marathon 2026. We have your enquiry and a member of our team will be '
         . 'in touch shortly.</p>'
         . '<p>Your reference is <strong>' . $reference . '</strong>.</p>'
         . '<p>Warm regards,<br />Forever Legend Foundation</p>';

    send_mail($sponsor['email'], $sponsor['contact_name'],
              'Your sponsorship enquiry — ' . $reference, $ack);

    // 2. Notification to whoever handles sponsorship. Silently skipped when no
    //    address is configured, rather than failing the enquiry.
    $to = (string) cfg('SPONSOR_EMAIL', cfg('ADMIN_EMAIL', ''));
    if ($to === '') {
        return;
    }

    $rows = [
        'Reference'   => $reference,
        'Company'     => $sponsor['company'],
        'Contact'     => $sponsor['contact_name'],
        'Designation' => $sponsor['designation'],
        'Email'       => $sponsor['email'],
        'Mobile'      => $sponsor['mobile'],
        'Website'     => $sponsor['website'],
        'City'        => $sponsor['city'],
        'Interest'    => $tier,
        'Budget'      => $budget,
        'Message'     => $sponsor['message'],
    ];

    $html = '<h2>New sponsorship enquiry</h2><table cellpadding="6">';
    foreach ($rows as $label => $value) {
        if ((string) $value === '') {
            continue;
        }
        $html .= '<tr><td><strong>' . $label . '</strong></td><td>'
               . nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
    }
    $html .= '</table>';

    send_mail($to, 'Sponsorship desk',
              'Sponsorship enquiry: ' . $sponsor['company'] . ' (' . $reference . ')', $html);
});
