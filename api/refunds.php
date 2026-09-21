<?php
/**
 * Refunds and the postponement notice.
 *
 * Used by admin/refunds.php (where staff start refunds and send the email) and
 * by webhook.php (where Razorpay reports that a refund has reached the bank).
 *
 * Money safety:
 *   - A refund is only ever started for a row that is 'paid' and carries a
 *     Razorpay payment id. Entries paid by cash/UPI to the team have no payment
 *     id, so the team refunds those by hand and records it here.
 *   - Before asking Razorpay for a refund we ask it for the refunds already on
 *     that payment. A retry after a timeout therefore records the refund that
 *     went through instead of starting a second one.
 *   - A full refund is requested without an amount, so Razorpay itself refuses
 *     anything beyond what was captured. Two clicks cannot pay out twice.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

/** Who may receive the notice: everyone holding a place, paid or not. */
const NOTICE_STATUSES = ['paid', 'free', 'awaiting'];

const NOTICE_DEFAULT_SUBJECT = 'Milkha Singh Legacy Marathon 2026 has been postponed';

const NOTICE_DEFAULT_MESSAGE = "Dear {name},\n\n"
    . "The event has been postponed; the next date will be announced soon.\n\n"
    . "{refund}\n\n"
    . "Your registration {registration_id} ({category}) is now closed. When the new date is announced we will let you know, and you will be welcome to register again.\n\n"
    . "We are sorry for the inconvenience, and thank you for running with us for a drug-free India.\n\n"
    . "Forever Legend Foundation\n"
    . "+91 73409 92413 | info@milkhasinghlegacymarathon.com";

/**
 * Adds the refund and notice columns to the registrations table. Checked once
 * per request; the ALTER only ever runs on the first visit after deploying.
 */
function ensure_refund_columns(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $have = [];
        foreach (db_connect()->query('SHOW COLUMNS FROM registrations') as $c) {
            $have[(string) $c['Field']] = true;
        }
        $add = [
            'refund_status'  => "ADD COLUMN refund_status VARCHAR(16) DEFAULT NULL",
            'refund_id'      => "ADD COLUMN refund_id VARCHAR(64) DEFAULT NULL",
            'refund_paise'   => "ADD COLUMN refund_paise INT UNSIGNED DEFAULT NULL",
            'refund_error'   => "ADD COLUMN refund_error VARCHAR(255) DEFAULT NULL",
            'refunded_at'    => "ADD COLUMN refunded_at DATETIME DEFAULT NULL",
            'notice_sent_at' => "ADD COLUMN notice_sent_at DATETIME DEFAULT NULL",
        ];
        $missing = array_diff_key($add, $have);
        if ($missing) {
            db_connect()->exec('ALTER TABLE registrations ' . implode(', ', $missing));
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[marathon-refunds] refund columns unavailable: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/** Human label for a refund state. */
function refund_label(?string $s): string
{
    return [
        'processed'  => 'Refunded',
        'pending'    => 'Refund on its way',
        'manual'     => 'Refunded by hand',
        'requesting' => 'Refund started',
        'failed'     => 'Refund failed',
    ][(string) $s] ?? 'Not refunded';
}

// ---------------------------------------------------------------------------
// Razorpay
// ---------------------------------------------------------------------------

/**
 * Calls the Razorpay API with the account's keys.
 * @return array{0:int,1:array,2:string} [http code, decoded body, transport error]
 */
function razorpay_call(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init('https://api.razorpay.com/v1/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => cfg('RAZORPAY_KEY_ID') . ':' . cfg('RAZORPAY_KEY_SECRET'),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    return [$code, is_array($data) ? $data : [], $err];
}

function razorpay_error_text(int $code, array $body, string $err): string
{
    if ($err !== '') {
        return 'Could not reach Razorpay: ' . $err;
    }
    if ($code === 401) {
        return 'Razorpay rejected the API keys. Check them under Settings.';
    }
    return (string) ($body['error']['description'] ?? ('Razorpay answered HTTP ' . $code));
}

function refund_save(int $id, array $set): void
{
    $cols = [];
    $vals = [];
    foreach ($set as $k => $v) {
        $cols[] = $k . ' = ?';
        $vals[] = $v;
    }
    $vals[] = $id;
    db()->prepare('UPDATE registrations SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($vals);
}

/** Records a Razorpay refund entity against the row. */
function refund_record(int $id, array $refund): array
{
    $status = (string) ($refund['status'] ?? 'pending');
    $status = in_array($status, ['processed', 'pending', 'failed'], true) ? $status : 'pending';
    refund_save($id, [
        'refund_status' => $status,
        'refund_id'     => (string) ($refund['id'] ?? ''),
        'refund_paise'  => (int) ($refund['amount'] ?? 0),
        'refund_error'  => $status === 'failed' ? 'Razorpay reported the refund failed.' : null,
        'refunded_at'   => $status === 'failed' ? null : date('Y-m-d H:i:s'),
    ]);
    return ['ok' => $status !== 'failed', 'status' => $status, 'message' => refund_label($status)];
}

/**
 * Refunds one registration in full through Razorpay.
 * @return array{ok:bool,status:string,message:string}
 */
function refund_registration(int $id): array
{
    $st = db()->prepare('SELECT * FROM registrations WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();

    if (!$r || $r['status'] !== 'paid') {
        return ['ok' => false, 'status' => 'skip', 'message' => 'Only paid entries can be refunded.'];
    }
    if (in_array($r['refund_status'], ['processed', 'pending', 'manual'], true)) {
        return ['ok' => true, 'status' => (string) $r['refund_status'], 'message' => 'Already refunded.'];
    }
    $pid = (string) ($r['razorpay_payment_id'] ?? '');
    if ($pid === '') {
        return ['ok' => false, 'status' => 'manual_needed',
            'message' => 'Paid outside Razorpay: refund it by hand, then mark it refunded.'];
    }

    // Claim the row so a second click shows "started" rather than racing. A
    // row left at 'requesting' by an attempt that timed out is retried as it
    // is: the lookup below finds any refund that attempt did make.
    if ($r['refund_status'] !== 'requesting') {
        $claim = db()->prepare(
            'UPDATE registrations SET refund_status = "requesting", refund_error = NULL
              WHERE id = ? AND status = "paid" AND (refund_status IS NULL OR refund_status = "failed")'
        );
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) {
            return ['ok' => false, 'status' => 'skip', 'message' => 'Already being refunded.'];
        }
    }

    // A refund may already exist: an earlier attempt that timed out, or one
    // made in the Razorpay dashboard. Record it rather than refunding again.
    [$code, $body, $err] = razorpay_call('GET', 'payments/' . rawurlencode($pid) . '/refunds');
    if ($err === '' && $code === 200) {
        foreach ((array) ($body['items'] ?? []) as $existing) {
            if (($existing['status'] ?? '') !== 'failed') {
                return refund_record($id, $existing);
            }
        }
    }

    [$code, $body, $err] = razorpay_call('POST', 'payments/' . rawurlencode($pid) . '/refund', [
        'speed'   => 'normal',
        'receipt' => (string) $r['registration_id'],
        'notes'   => [
            'registration_id' => (string) $r['registration_id'],
            'reason'          => 'Event postponed',
        ],
    ]);

    if ($err === '' && $code === 200 && !empty($body['id'])) {
        return refund_record($id, $body);
    }

    $msg = razorpay_error_text($code, $body, $err);
    refund_save($id, ['refund_status' => 'failed', 'refund_error' => mb_substr($msg, 0, 255)]);
    return ['ok' => false, 'status' => 'failed', 'message' => $msg];
}

/** Asks Razorpay whether a refund that was on its way has now landed. */
function refund_sync(int $id): array
{
    $st = db()->prepare('SELECT refund_id, refund_status FROM registrations WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || (string) $r['refund_id'] === '' || $r['refund_status'] !== 'pending') {
        return ['ok' => true, 'status' => (string) ($r['refund_status'] ?? ''), 'message' => 'Nothing to check.'];
    }
    [$code, $body, $err] = razorpay_call('GET', 'refunds/' . rawurlencode((string) $r['refund_id']));
    if ($err !== '' || $code !== 200) {
        return ['ok' => false, 'status' => 'pending', 'message' => razorpay_error_text($code, $body, $err)];
    }
    return refund_record($id, $body);
}

/** For entries paid in cash/UPI to the team: they refund it, then record it. */
function refund_mark_manual(int $id): bool
{
    $st = db()->prepare(
        'UPDATE registrations
            SET refund_status = "manual", refund_paise = amount_paise,
                refund_error = NULL, refunded_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status = "paid"
            AND (refund_status IS NULL OR refund_status = "failed")'
    );
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// The notice email
// ---------------------------------------------------------------------------

function notice_subject(): string
{
    $v = trim((string) site_setting('notice_subject', ''));
    return $v !== '' ? $v : NOTICE_DEFAULT_SUBJECT;
}

function notice_message(): string
{
    $v = trim((string) site_setting('notice_message', ''));
    return $v !== '' ? $v : NOTICE_DEFAULT_MESSAGE;
}

/** The sentence about money, which depends on where this runner stands. */
function notice_refund_line(array $r): string
{
    $paise  = (int) $r['amount_paise'];
    $amount = '₹' . number_format($paise / 100, $paise % 100 ? 2 : 0);
    $status = (string) $r['status'];
    $refund = (string) ($r['refund_status'] ?? '');

    if ($status === 'free') {
        return 'Your entry was free, so there is nothing to refund.';
    }
    if ($status === 'awaiting') {
        return 'You had not paid an entry fee yet, so nothing is due from you.';
    }
    if (in_array($refund, ['processed', 'pending'], true)) {
        return 'We have refunded your entry fee of ' . $amount
            . ' to the card, UPI or bank account you paid with'
            . ((string) $r['refund_id'] !== '' ? ' (refund reference ' . $r['refund_id'] . ')' : '')
            . '. Banks usually take 5 to 7 working days to show it in your account.';
    }
    if ($refund === 'manual') {
        return 'We have refunded your entry fee of ' . $amount . '.';
    }
    return 'Your entry fee of ' . $amount . ' will be refunded to your original payment method in the coming days.';
}

/**
 * Builds the email for one runner.
 * @return array{0:string,1:string} [subject, html]
 */
function notice_build(array $r, ?string $subject = null, ?string $message = null): array
{
    $cat  = CATEGORIES[$r['category']]['label'] ?? (string) $r['category'];
    $vars = [
        '{name}'            => (string) $r['full_name'],
        '{registration_id}' => (string) $r['registration_id'],
        '{category}'        => $cat,
        '{refund}'          => notice_refund_line($r),
    ];

    $subject = strtr($subject ?? notice_subject(), $vars);
    $text    = strtr($message ?? notice_message(), $vars);

    // Plain text in, safe HTML out: every paragraph is escaped, so nothing
    // typed into the message box can inject markup into runners' inboxes.
    $paras = preg_split('/\R{2,}/', trim($text)) ?: [];
    $body  = '';
    foreach ($paras as $p) {
        $p = nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8'), false);
        $body .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#57616F;">' . $p . '</p>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>'
        . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;padding:0;background:#F4F5F7;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #E7EAF0;border-radius:8px;font-family:Arial,Helvetica,sans-serif;">'
        . '<tr><td style="height:5px;background:#FF9933;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:26px 28px 6px;">'
        . '<div style="font-size:11px;font-weight:bold;letter-spacing:2px;color:#B42318;text-transform:uppercase;">Event postponed</div>'
        . '<h1 style="margin:8px 0 0;font-size:22px;line-height:1.25;color:#12295B;">Milkha Singh Legacy Marathon 2026</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px 12px;">' . $body . '</td></tr>'
        . '<tr><td style="height:5px;background:#138808;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [$subject, $html];
}

/** Sends the notice to one runner and stamps the row. */
function notice_send(int $id): array
{
    $st = db()->prepare('SELECT * FROM registrations WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || !in_array($r['status'], NOTICE_STATUSES, true)) {
        return ['ok' => false, 'message' => 'This entry does not get the notice.'];
    }
    if (!mail_configured()) {
        return ['ok' => false, 'message' => 'No mailbox is set up. Add the email login under Settings first.'];
    }

    [$subject, $html] = notice_build($r);
    if (!send_mail((string) $r['email'], (string) $r['full_name'], $subject, $html, runner_desk_email())) {
        return ['ok' => false, 'message' => 'The mail server refused the message to ' . $r['email'] . '.'];
    }
    db()->prepare('UPDATE registrations SET notice_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
    return ['ok' => true, 'message' => 'Sent to ' . $r['email'] . '.'];
}
