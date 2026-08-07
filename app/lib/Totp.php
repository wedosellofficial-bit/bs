<?php

declare(strict_types=1);

namespace App\Lib;

use InvalidArgumentException;

/**
 * TOTP (RFC 6238) with HMAC-SHA1 and 6 digits - the parameters every
 * authenticator app actually implements.
 *
 * Hand-rolled rather than pulled in as a dependency: it is 80 lines, the
 * spec is stable, and it keeps the vendored-dependency count at zero for
 * a codebase that has to be uploaded by FTP.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    /**
     * How many periods either side of now are accepted. One period each
     * way (±30s) covers ordinary clock drift between a phone and a shared
     * host without meaningfully widening the guessing window.
     */
    private const WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh 160-bit secret, base32 encoded for the QR/manual entry. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Verify a submitted code.
     *
     * Every candidate in the window is compared with hash_equals and the
     * loop does not break early on success, so verification takes the same
     * time whether the match is at the start of the window or the end.
     */
    public static function verify(string $secret, string $code, ?int $atTime = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $key = self::base32Decode($secret);
        if ($key === null || $key === '') {
            return false;
        }

        $counter = intdiv($atTime ?? time(), self::PERIOD);
        $valid = false;

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::codeAt($key, $counter + $offset), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /** The current code, used by the setup screen's "test it" step. */
    public static function currentCode(string $secret, ?int $atTime = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === null) {
            throw new InvalidArgumentException('Invalid base32 secret.');
        }

        return self::codeAt($key, intdiv($atTime ?? time(), self::PERIOD));
    }

    /**
     * otpauth:// URI for the enrolment QR code.
     *
     * Rendered client-side by the same qrcode.js used on the wallet page -
     * the secret must never be handed to a third-party chart server.
     */
    public static function provisioningUri(string $secret, string $accountEmail, string $issuer): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($accountEmail)
            . '?' . http_build_query([
                'secret'    => $secret,
                'issuer'    => $issuer,
                'algorithm' => 'SHA1',
                'digits'    => self::DIGITS,
                'period'    => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Group a secret into readable blocks for manual entry. */
    public static function formatSecretForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** The HOTP value for a counter, zero-padded to DIGITS. */
    private static function codeAt(string $key, int $counter): string
    {
        // 8-byte big-endian counter. pack('J') needs 64-bit PHP, which
        // every supported build is.
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);

        // Dynamic truncation, RFC 4226 section 5.3.
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        $len = strlen($bytes);

        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private static function base32Decode(string $secret): ?string
    {
        // Users retype secrets with spaces and lowercase; padding is
        // optional in practice.
        $secret = strtoupper(str_replace([' ', '-', '='], '', trim($secret)));
        if ($secret === '') {
            return null;
        }

        $bits = '';
        $len = strlen($secret);

        for ($i = 0; $i < $len; $i++) {
            $index = strpos(self::BASE32_ALPHABET, $secret[$i]);
            if ($index === false) {
                return null;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
