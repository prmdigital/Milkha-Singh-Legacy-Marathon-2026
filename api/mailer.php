<?php
/**
 * Minimal SMTP client.
 *
 * Hostinger's shared plans often have no SSH or Composer, so PHPMailer cannot
 * be installed the usual way. PHP's mail() would work but delivers poorly, so
 * this speaks authenticated SMTP directly using the credentials already in the
 * config. No dependencies.
 *
 * Supports implicit TLS (port 465) and STARTTLS (port 587).
 */

declare(strict_types=1);

class SmtpMailer
{
    private $sock;
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $lastError = '';

    public function __construct(string $host, int $port, string $user, string $pass)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** @return bool true when the server accepted the message for delivery */
    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        try {
            if (!$this->connect()) {
                return false;
            }

            $this->cmd('MAIL FROM:<' . $fromEmail . '>', 250);
            $this->cmd('RCPT TO:<' . $toEmail . '>', 250);
            $this->cmd('DATA', 354);

            $this->write($this->buildMessage(
                $fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody
            ));
            $this->cmd('.', 250);
            $this->cmd('QUIT', 221);

            fclose($this->sock);
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            if (is_resource($this->sock)) {
                @fclose($this->sock);
            }
            return false;
        }
    }

    private function connect(): bool
    {
        $implicitTls = ($this->port === 465);
        $target = ($implicitTls ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;

        $this->sock = @stream_socket_client($target, $errNo, $errStr, 20);
        if (!$this->sock) {
            $this->lastError = 'connect failed: ' . $errStr;
            return false;
        }
        stream_set_timeout($this->sock, 20);

        $this->expect(220);
        $helo = $this->heloName();
        $this->cmd('EHLO ' . $helo, 250);

        if (!$implicitTls) {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $this->cmd('EHLO ' . $helo, 250);   // must re-announce after upgrading
        }

        $this->cmd('AUTH LOGIN', 334);
        $this->cmd(base64_encode($this->user), 334);
        $this->cmd(base64_encode($this->pass), 235);

        return true;
    }

    private function heloName(): string
    {
        $h = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return preg_match('/^[A-Za-z0-9.\-]+$/', $h) ? $h : 'localhost';
    }

    private function buildMessage(
        string $fromEmail, string $fromName,
        string $toEmail, string $toName,
        string $subject, string $html, string $text
    ): string {
        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags(
                preg_replace('#<(br|/p|/div|/tr)[^>]*>#i', "\n", $html)
            ), ENT_QUOTES, 'UTF-8'));
        }

        $boundary = 'b' . bin2hex(random_bytes(12));
        $enc = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';

        $h = [
            'Date: ' . date('r'),
            'From: ' . $enc($fromName) . ' <' . $fromEmail . '>',
            'To: ' . ($toName !== '' ? $enc($toName) . ' ' : '') . '<' . $toEmail . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->host . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text)) . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . "--$boundary--\r\n";

        // A leading '.' on any line would end the DATA block early.
        $msg = implode("\r\n", $h) . "\r\n\r\n" . $body;
        return preg_replace('/^\./m', '..', $msg);
    }

    private function cmd(string $line, int $expect): void
    {
        $this->write($line . "\r\n");
        $this->expect($expect);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->sock, $data) === false) {
            throw new RuntimeException('write failed');
        }
    }

    private function expect(int $code): string
    {
        $reply = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $reply .= $line;
            // Multi-line replies look like "250-...", the last is "250 ...".
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ((int) substr($reply, 0, 3) !== $code) {
            throw new RuntimeException('expected ' . $code . ', got: ' . trim($reply));
        }
        return $reply;
    }
}

/**
 * Sends a message using the configured SMTP account.
 * Never throws: a failed email must not fail a paid registration.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html): bool
{
    $m = new SmtpMailer(
        (string) cfg('SMTP_HOST'),
        (int) cfg('SMTP_PORT', 465),
        (string) cfg('SMTP_USER'),
        (string) cfg('SMTP_PASS')
    );

    $sent = $m->send(
        (string) cfg('SMTP_FROM', cfg('SMTP_USER')),
        (string) cfg('SMTP_FROM_NAME', 'Milkha Singh Legacy Marathon'),
        $toEmail, $toName, $subject, $html
    );

    if (!$sent) {
        error_log('[marathon-api] mail to ' . $toEmail . ' failed: ' . $m->lastError());
    }
    return $sent;
}


/** Fills the HTML template and sends it. */
function send_confirmation(array $reg, string $paymentId = ''): void
{
    $tpl = __DIR__ . '/email-confirmation.html';
    if (!is_readable($tpl)) {
        error_log('[marathon-api] email template missing');
        return;
    }

    $cat   = CATEGORIES[$reg['category']] ?? ['label' => $reg['category'], 'distance' => ''];
    $times = RACE_TIMES[$reg['category']] ?? '';

    $html = strtr(file_get_contents($tpl), [
        '{{NAME}}'            => htmlspecialchars($reg['full_name'], ENT_QUOTES, 'UTF-8'),
        '{{REGISTRATION_ID}}' => htmlspecialchars($reg['registration_id'], ENT_QUOTES, 'UTF-8'),
        '{{CATEGORY}}'        => htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8'),
        '{{DISTANCE}}'        => htmlspecialchars($cat['distance'], ENT_QUOTES, 'UTF-8'),
        '{{TIMES}}'           => htmlspecialchars($times, ENT_QUOTES, 'UTF-8'),
        '{{AMOUNT}}'          => ((int) $reg['amount_paise']) > 0
            ? '&#8377;' . rupees((int) $reg['amount_paise'])
            : 'Free entry',
        '{{PAYMENT_ID}}'      => $paymentId !== ''
            ? htmlspecialchars($paymentId, ENT_QUOTES, 'UTF-8')
            : 'No payment required',
        '{{ID_TYPE}}'         => htmlspecialchars($reg['id_proof_type'], ENT_QUOTES, 'UTF-8'),
    ]);

    $sent = send_mail(
        $reg['email'],
        $reg['full_name'],
        'Registration confirmed - ' . $reg['registration_id'] . ' - Milkha Singh Legacy Marathon 2026',
        $html
    );

    if ($sent) {
        try {
            db()->prepare('UPDATE registrations SET receipt_emailed = 1 WHERE id = :id')
                ->execute([':id' => $reg['id']]);
        } catch (Throwable $e) {
            error_log('[marathon-api] could not flag receipt_emailed: ' . $e->getMessage());
        }
    }

    // Let the organisers know a new entry landed.
    $admin = (string) cfg('ADMIN_EMAIL', '');
    if ($admin !== '') {
        send_mail($admin, 'Organiser',
            'New entry: ' . $cat['label'] . ' - ' . $reg['full_name'],
            '<p style="font-family:Arial,sans-serif">'
            . '<strong>' . htmlspecialchars($reg['full_name'], ENT_QUOTES, 'UTF-8') . '</strong> '
            . 'registered for ' . htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8') . '.<br>'
            . 'Registration: ' . htmlspecialchars($reg['registration_id'], ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Email: ' . htmlspecialchars($reg['email'], ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Mobile: ' . htmlspecialchars($reg['mobile'], ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Paid: &#8377;' . rupees((int) $reg['amount_paise'])
            . '</p>');
    }
}
