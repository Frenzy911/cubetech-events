<?php
/**
 * Email.
 *  - Every email is saved in the email_outbox table (Staff > Email outbox) so you can
 *    test without any mail server.
 *  - If MAIL_HOST is set in .env, it is ALSO sent through that SMTP server. Use a sandbox
 *    such as Mailtrap for testing. Credentials live in .env only, never in the code.
 */

function mail_configured(): bool
{
    return env('MAIL_HOST') !== null;
}

/** $kind: registered | waitlisted | promoted. Never throws: email problems must not break registration. */
function send_ticket_email(int $regId, string $kind): void
{
    try {
        $r = get_registration($regId);
        if (!$r) {
            return;
        }
        $ticketUrl = abs_url(ticket_path($r['ticket_code']));
        $qrUrl = abs_url('qr.php?code=' . rawurlencode($r['ticket_code']) . '&sig=' . ticket_sig($r['ticket_code']));

        switch ($kind) {
            case 'waitlisted':
                $pos = waitlist_position($r);
                $subject = "You're on the waitlist: " . $r['event_title'];
                $heading = "You're #{$pos} on the waitlist";
                $intro = "This event is full, so we added you to the waitlist. This is not a ticket yet. "
                       . "If a spot opens and the organizer confirms you, we'll email you your QR ticket.";
                $showQr = false;
                break;
            case 'promoted':
                $subject = "Good news, you're in: " . $r['event_title'];
                $heading = "A spot opened up. You're confirmed!";
                $intro = 'You have been moved from the waitlist to a confirmed ticket. Show the QR code below at the entrance.';
                $showQr = true;
                break;
            default:
                $subject = 'Your ticket: ' . $r['event_title'];
                $heading = "You're registered!";
                $intro = 'Thanks for registering. Show the QR code below (or your ticket page) at the entrance.';
                $showQr = true;
        }

        $html = email_template($r, $heading, $intro, $showQr, $ticketUrl, $qrUrl);
        $text = $heading . "\n\n" . $intro . "\n\n"
              . 'Event: ' . $r['event_title'] . "\n"
              . 'Date: ' . fmt_date($r['starts_at']) . "\n"
              . 'Time: ' . event_time_range($r) . " (Cebu time)\n"
              . 'Venue: ' . $r['venue'] . "\n"
              . 'Name: ' . $r['full_name'] . "\n"
              . 'Ticket code: ' . $r['ticket_code'] . "\n\n"
              . 'Your ticket page: ' . $ticketUrl . "\n";

        mail_send($r['email'], $subject, $html, $text, $regId);
    } catch (Throwable $e) {
        error_log('[CubeTech Events mail] ' . $e->getMessage());
    }
}

/** Records the email in the outbox and sends it via SMTP when configured. Returns sent|failed|logged. */
function mail_send(string $to, string $subject, string $html, string $text, ?int $regId = null): string
{
    $status = 'logged';
    $error = null;
    if (mail_configured()) {
        try {
            smtp_send($to, $subject, $html, $text);
            $status = 'sent';
        } catch (Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 500);
            error_log('[CubeTech Events SMTP] ' . $e->getMessage());
        }
    }
    db_exec(
        'INSERT INTO email_outbox (registration_id, to_email, subject, body_html, status, error) VALUES (?, ?, ?, ?, ?, ?)',
        [$regId, $to, $subject, $html, $status, $error]
    );
    return $status;
}

function email_template(array $r, string $heading, string $intro, bool $showQr, string $ticketUrl, string $qrUrl): string
{
    $rows = [
        'Event'       => $r['event_title'],
        'Date'        => fmt_date($r['starts_at']),
        'Time'        => event_time_range($r) . ' (Cebu time)',
        'Venue'       => $r['venue'],
        'Name'        => $r['full_name'],
        'Ticket code' => $r['ticket_code'],
    ];
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $style = $label === 'Ticket code' ? 'font-family:Consolas,Menlo,monospace;font-weight:bold;letter-spacing:1px' : '';
        $rowsHtml .= '<tr><td style="padding:6px 12px 6px 0;color:#64748b;font-size:14px;vertical-align:top;white-space:nowrap">' . h($label)
                   . '</td><td style="padding:6px 0;font-size:15px;' . $style . '">' . h($value) . '</td></tr>';
    }
    $qr = $showQr
        ? '<p style="text-align:center;margin:20px 0"><img src="' . h($qrUrl) . '" width="220" height="220" alt="QR code for ticket ' . h($r['ticket_code']) . '" style="border:1px solid #e2e8f0;border-radius:8px"></p>'
        : '';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="background:#1d4ed8;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold">' . h(APP_NAME) . '</td></tr>'
        . '<tr><td style="padding:24px">'
        . '<h1 style="font-size:22px;line-height:1.3;margin:0 0 10px">' . h($heading) . '</h1>'
        . '<p style="font-size:15px;line-height:1.5;margin:0 0 16px;color:#334155">' . h($intro) . '</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0">' . $rowsHtml . '</table>'
        . $qr
        . '<p style="text-align:center;margin:24px 0 8px"><a href="' . h($ticketUrl) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">View your ticket</a></p>'
        . '<p style="font-size:12px;color:#64748b;word-break:break-all">If the button does not work, open this link: ' . h($ticketUrl) . '</p>'
        . '</td></tr></table>'
        . '<p style="font-size:12px;color:#94a3b8">All times are Cebu time (Asia/Manila, UTC+8).</p>'
        . '</td></tr></table></body></html>';
}

/**
 * Minimal SMTP client (STARTTLS / SSL + AUTH LOGIN). Throws RuntimeException on failure.
 * Settings come from .env: MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM, MAIL_FROM_NAME.
 */
function smtp_send(string $to, string $subject, string $html, string $text): void
{
    $host = (string) env('MAIL_HOST');
    $port = (int) env('MAIL_PORT', '587');
    $encryption = strtolower((string) env('MAIL_ENCRYPTION', 'tls'));
    $username = (string) env('MAIL_USERNAME', '');
    $password = (string) env('MAIL_PASSWORD', '');
    $from = (string) env('MAIL_FROM', 'no-reply@example.com');
    $fromName = (string) env('MAIL_FROM_NAME', APP_NAME);

    if (!valid_email($to) || !valid_email($from)) {
        throw new RuntimeException('Invalid sender or recipient address.');
    }

    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        throw new RuntimeException("Could not connect to $host:$port ($errstr)");
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $command = function (?string $line, array $expect, string $label = '') use ($fp, $read): string {
        if ($line !== null) {
            fwrite($fp, $line . "\r\n");
        }
        $response = $read();
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException('SMTP ' . ($label ?: (string) $line) . ' failed: ' . trim($response ?: 'no response'));
        }
        return $response;
    };

    try {
        $command(null, [220], 'greeting');
        $command('EHLO localhost', [250]);
        if ($encryption === 'tls') {
            $command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!stream_socket_enable_crypto($fp, true, $method)) {
                throw new RuntimeException('Could not start TLS encryption.');
            }
            $command('EHLO localhost', [250]);
        }
        if ($username !== '') {
            $command('AUTH LOGIN', [334]);
            $command(base64_encode($username), [334], 'username');
            $command(base64_encode($password), [235], 'password');
        }
        $command("MAIL FROM:<$from>", [250]);
        $command("RCPT TO:<$to>", [250, 251]);
        $command('DATA', [354]);

        $boundary = 'b_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <$from>",
            "To: <$to>",
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text))
              . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--$boundary--\r\n";
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $message = preg_replace('/^\./m', '..', $message); // SMTP dot-stuffing

        fwrite($fp, $message . "\r\n.\r\n");
        $command(null, [250], 'message');
        try {
            $command('QUIT', [221]);
        } catch (Throwable $ignored) {
        }
    } finally {
        fclose($fp);
    }
}
