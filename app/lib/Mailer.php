<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;
use Throwable;

/**
 * SMTP mailer.
 *
 * Speaks enough SMTP to authenticate and send a single multipart message.
 * PHP's mail() is avoided because on shared hosting it routes through
 * sendmail with the account's default envelope sender, which lands in
 * spam and gives no delivery error to log.
 *
 * In development (or when SMTP is not configured) messages are written to
 * storage/logs/mail/ instead of being sent, so a local signup flow can be
 * completed by opening the file and clicking the link.
 */
final class Mailer
{
    public static function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): bool
    {
        $host = Config::string('mail.host');
        $user = Config::string('mail.user');

        if ($host === '' || $user === '' || Config::string('mail.transport') === 'log') {
            return self::writeToDisk($toEmail, $subject, $textBody);
        }

        try {
            return self::sendSmtp($toEmail, $subject, $textBody, $htmlBody);
        } catch (Throwable $e) {
            Logger::write('mail.failed', $e->getMessage(), ['to_domain' => self::domainOf($toEmail)]);

            // A failed verification email must not take down registration -
            // the user can request a resend. The failure is logged.
            return false;
        }
    }

    private static function sendSmtp(string $toEmail, string $subject, string $textBody, ?string $htmlBody): bool
    {
        $host = Config::string('mail.host');
        $port = Config::int('mail.port', 465);
        $encryption = strtolower(Config::string('mail.encryption', 'ssl'));

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                // Certificate verification stays on. An SMTP session carries
                // the mailbox password on the next line.
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);

        try {
            self::expect($socket, 220);

            $ehloHost = parse_url(Config::string('app.url'), PHP_URL_HOST) ?: 'localhost';
            self::command($socket, 'EHLO ' . $ehloHost, 250);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', 220);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }

                // The EHLO must be repeated after the upgrade; the
                // capabilities advertised in clear are not to be trusted.
                self::command($socket, 'EHLO ' . $ehloHost, 250);
            }

            self::command($socket, 'AUTH LOGIN', 334);
            self::command($socket, base64_encode(Config::string('mail.user')), 334);
            self::command($socket, base64_encode(Config::string('mail.pass')), 235);

            self::command($socket, 'MAIL FROM:<' . Config::string('mail.from_addr') . '>', 250);
            self::command($socket, 'RCPT TO:<' . $toEmail . '>', 250);
            self::command($socket, 'DATA', 354);

            fwrite($socket, self::buildMessage($toEmail, $subject, $textBody, $htmlBody) . "\r\n.\r\n");
            self::expect($socket, 250);

            self::command($socket, 'QUIT', 221);

            Logger::write('mail.sent', 'Message delivered to SMTP', [
                'to_domain' => self::domainOf($toEmail),
                'subject'   => $subject,
            ]);

            return true;
        } finally {
            fclose($socket);
        }
    }

    private static function buildMessage(string $toEmail, string $subject, string $text, ?string $html): string
    {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));
        $fromName = Config::string('mail.from_name', 'Billions Store');
        $fromAddr = Config::string('mail.from_addr');

        // Encode the display name so a non-ASCII store name cannot break
        // the header, and strip anything CR/LF-ish from the subject -
        // header injection through a subject line is a classic.
        $safeSubject = self::encodeHeader(str_replace(["\r", "\n"], ' ', $subject));

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromAddr . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $safeSubject,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (parse_url(Config::string('app.url'), PHP_URL_HOST) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
        ];

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';

            return implode("\r\n", $headers) . "\r\n\r\n" . self::b64($text);
        }

        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::b64($text) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::b64($html) . "\r\n"
            . "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Base64 body encoding, wrapped to 76 columns per RFC 2045.
     *
     * This replaced quoted-printable deliberately. Quoted-printable
     * soft-wraps any line over 76 characters by inserting `=\r\n` mid
     * -line, and a mail client that is not fully RFC-2045-compliant -
     * several webmail "linkify this bare URL" heuristics among them -
     * can auto-link only up to that literal line break, silently
     * truncating a verification or password-reset URL mid-token. Every
     * such link then reads as valid but points at a token that is missing
     * its second half, which looks exactly like "expired" from the
     * user's side because Auth::consumeToken() never finds a match.
     *
     * Base64 has no such failure mode: decoding reassembles the exact
     * original bytes regardless of where the base64 text itself is
     * wrapped, because the wrapping does not correspond to anything in
     * the decoded content - unlike quoted-printable, where a line break
     * in the ENCODED form can only be removed if the decoder recognises
     * the trailing `=` as "this is a soft break, not a real one." There is
     * no equivalent ambiguity to get wrong here.
     */
    private static function b64(string $text): string
    {
        return chunk_split(base64_encode($text), 76, "\r\n");
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private static function command($socket, string $command, int $expected): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $expected);
    }

    private static function expect($socket, int $expected): void
    {
        $response = '';

        // Multi-line replies use "250-" for continuation and "250 " for the
        // final line.
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr(trim($response), 0, 3);

        if ($code !== $expected) {
            // The response can echo the command, which for AUTH LOGIN would
            // be a base64 password. Only the code is reported.
            throw new RuntimeException("SMTP expected {$expected}, got {$code}");
        }
    }

    /** Development fallback: write the message where a human can read it. */
    private static function writeToDisk(string $toEmail, string $subject, string $body): bool
    {
        $dir = Config::string('app.storage', BASE_PATH . '/storage') . '/logs/mail';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';

        $written = @file_put_contents(
            $file,
            "To: {$toEmail}\nSubject: {$subject}\nDate: " . gmdate('c') . "\n\n{$body}\n"
        );

        Logger::write('mail.written_to_disk', 'SMTP not configured; message written to storage', [
            'to_domain' => self::domainOf($toEmail),
            'file'      => basename($file),
        ]);

        return $written !== false;
    }

    /** Log the recipient domain, never the full address. */
    private static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'unknown' : substr($email, $at + 1);
    }

    //-----------------------------------------------------------------
    // Message templates
    //-----------------------------------------------------------------

    /**
     * HTML body for a single-action email: a short intro, one real
     * `<a href>` button, the same URL printed as visible text underneath
     * (for a client that strips styling, or a user who wants to copy it
     * by hand), and a footer note.
     *
     * The button is a real anchor - clicking it does not depend on any
     * mail client's "detect a bare URL in plain text" heuristic, which is
     * exactly the heuristic that was truncating the token before (see the
     * note on b64() above). Escaping every value is not optional here:
     * $url is built from a token we generated, but the store name and
     * intro strings ultimately trace back to config, and an email client
     * renders HTML same as a browser does.
     */
    private static function actionEmailHtml(
        string $store,
        string $intro,
        string $instruction,
        string $url,
        string $buttonLabel,
        string $footerNote,
    ): string {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f4f4f5;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
        <tr><td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border-radius:10px;padding:36px;">
        <tr><td style="font-size:19px;font-weight:700;color:#0e0e12;padding-bottom:20px;">{$e($store)}</td></tr>
        <tr><td style="font-size:15px;line-height:1.6;color:#33333c;padding-bottom:6px;">{$e($intro)}</td></tr>
        <tr><td style="font-size:15px;line-height:1.6;color:#33333c;padding-bottom:24px;">{$e($instruction)}</td></tr>
        <tr><td align="center" style="padding-bottom:24px;">
        <a href="{$e($url)}" style="display:inline-block;background:#f2a03d;color:#1a1206;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;font-weight:700;">{$e($buttonLabel)}</a>
        </td></tr>
        <tr><td style="font-size:13px;line-height:1.5;color:#6b6b7b;padding-bottom:20px;">
        If the button above doesn't work, copy this link into your browser:<br>
        <a href="{$e($url)}" style="color:#a85f13;word-break:break-all;">{$e($url)}</a>
        </td></tr>
        <tr><td style="font-size:13px;line-height:1.5;color:#8b8b9c;border-top:1px solid #e5e5ea;padding-top:16px;">{$e($footerNote)}</td></tr>
        </table>
        </td></tr>
        </table>
        </body>
        </html>
        HTML;
    }

    public static function sendVerification(string $email, string $token): bool
    {
        $url = Config::string('app.url') . '/verify-email?token=' . rawurlencode($token);
        $store = Config::string('app.name');

        return self::send(
            $email,
            "Confirm your email for {$store}",
            "Welcome to {$store}.\n\n"
            . "Confirm this address to activate your account by opening this link:\n\n{$url}\n\n"
            . "The link is valid for 24 hours.\n\n"
            . "If you did not create an account, ignore this email - nothing will happen.\n",
            self::actionEmailHtml(
                $store,
                'Welcome to ' . $store . '.',
                'Confirm this address to activate your account.',
                $url,
                'Confirm email',
                'The link is valid for 24 hours. If you did not create an account, ignore this email — nothing will happen.'
            )
        );
    }

    public static function sendPasswordReset(string $email, string $token): bool
    {
        $url = Config::string('app.url') . '/reset-password?token=' . rawurlencode($token);
        $store = Config::string('app.name');

        return self::send(
            $email,
            "Reset your {$store} password",
            "Someone asked to reset the password for this address.\n\n"
            . "Set a new one by opening this link:\n\n{$url}\n\n"
            . "The link is valid for 60 minutes and can only be used once.\n\n"
            . "If this was not you, ignore this email. Your password has not changed, "
            . "and nobody can sign in without it.\n",
            self::actionEmailHtml(
                $store,
                'Someone asked to reset the password for this address.',
                'If this was you, choose a new password now.',
                $url,
                'Choose a new password',
                'The link is valid for 60 minutes and can only be used once. If this was not you, ignore this email — your password has not changed, and nobody can sign in without it.'
            )
        );
    }

    public static function sendDepositCredited(string $email, string $amount, string $balance): bool
    {
        $store = Config::string('app.name');

        return self::send(
            $email,
            "{$amount} added to your {$store} balance",
            "Your deposit has been confirmed and credited.\n\n"
            . "Credited: {$amount}\nNew balance: {$balance}\n\n"
            . Config::string('app.url') . "/account/wallet\n"
        );
    }

    public static function sendTransferComplete(string $email, string $itemName, string $txid): bool
    {
        $store = Config::string('app.name');

        $explorerTemplate = Config::string('chain.explorer_tx');
        $explorerLine = $explorerTemplate === ''
            ? ''
            : sprintf($explorerTemplate, $txid) . "\n";

        return self::send(
            $email,
            'Your inscription has been transferred',
            "{$itemName} has been sent to your wallet.\n\n"
            . "Transaction: {$txid}\n"
            . $explorerLine
            . "\n"
            . "It may take a little while to appear in your wallet while the "
            . "transaction confirms.\n\n"
            . "- {$store}\n"
        );
    }
}
