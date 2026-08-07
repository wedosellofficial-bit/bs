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
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';

            return implode("\r\n", $headers) . "\r\n\r\n" . self::qp($text);
        }

        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::qp($text) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::qp($html) . "\r\n"
            . "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Quoted-printable, with SMTP dot-stuffing.
     *
     * A line consisting of a single "." terminates the DATA command; a
     * message body containing one would be truncated there.
     */
    private static function qp(string $text): string
    {
        $encoded = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $text));
        $encoded = str_replace("\n", "\r\n", $encoded);

        return preg_replace('/^\./m', '..', $encoded) ?? $encoded;
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

    public static function sendVerification(string $email, string $token): bool
    {
        $url = Config::string('app.url') . '/verify-email?token=' . rawurlencode($token);
        $store = Config::string('app.name');

        return self::send(
            $email,
            "Confirm your email for {$store}",
            "Welcome to {$store}.\n\n"
            . "Confirm this address to activate your account:\n{$url}\n\n"
            . "The link is valid for 24 hours.\n\n"
            . "If you did not create an account, ignore this email - nothing will happen.\n"
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
            . "Set a new one here:\n{$url}\n\n"
            . "The link is valid for 60 minutes and can only be used once.\n\n"
            . "If this was not you, ignore this email. Your password has not changed, "
            . "and nobody can sign in without it.\n"
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
