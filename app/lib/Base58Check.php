<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Base58Check decoder for legacy Bitcoin addresses (1... P2PKH,
 * 3... P2SH).
 *
 * We never pay out to these - ordinals require taproot - but we do need
 * to recognise them so a buyer who pastes an exchange deposit address
 * gets "that is a legacy address, ordinals sent there can be lost"
 * instead of "invalid address", which reads like a typo and invites a
 * second attempt at the same wrong thing.
 *
 * Big-integer division is done on a byte array because Hostinger's
 * default PHP builds ship neither GMP nor BCMath.
 */
final class Base58Check
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * @return array{version:int,payload:string}|null null when the string
     *         is not valid base58 or the 4-byte double-SHA256 checksum
     *         does not match.
     */
    public static function decode(string $input): ?array
    {
        if ($input === '' || strlen($input) > 120) {
            return null;
        }

        /**
         * Big-endian accumulator, most significant byte first. Starts
         * empty rather than [0] so it never carries a spurious leading
         * zero that would have to be told apart from a genuine one.
         *
         * @var list<int> $bytes
         */
        $bytes = [];

        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $digit = strpos(self::ALPHABET, $input[$i]);
            if ($digit === false) {
                return null;
            }

            // bytes = bytes * 58 + digit
            $carry = $digit;
            for ($j = count($bytes) - 1; $j >= 0; $j--) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                array_unshift($bytes, $carry & 0xff);
                $carry >>= 8;
            }
        }

        // Each leading '1' in base58 encodes one leading zero byte.
        for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
            array_unshift($bytes, 0);
        }

        $raw = implode('', array_map('chr', $bytes));

        // version(1) + payload(>=1) + checksum(4)
        if (strlen($raw) < 6) {
            return null;
        }

        $body = substr($raw, 0, -4);
        $checksum = substr($raw, -4);

        if (!hash_equals(substr(hash('sha256', hash('sha256', $body, true), true), 0, 4), $checksum)) {
            return null;
        }

        return [
            'version' => ord($body[0]),
            'payload' => substr($body, 1),
        ];
    }
}
