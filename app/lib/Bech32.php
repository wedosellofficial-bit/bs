<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Bech32 / bech32m codec and segwit address decoder.
 *
 * Implements BIP-173 (bech32, witness v0) and BIP-350 (bech32m, witness
 * v1+). Written out rather than pulled from a library because it is
 * ~120 lines, has no dependencies, and is the single check standing
 * between a buyer's typo and an inscription sent to an address nobody
 * controls. There is no recovering a taproot output sent to a valid-
 * checksum-wrong-owner address.
 *
 * The checksum is the whole point: bech32's BCH code detects any error
 * of up to 4 characters, which is why we validate the checksum rather
 * than pattern-matching `^bc1p[a-z0-9]{58}$` like a regex-only check
 * would.
 */
final class Bech32
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /** Generator coefficients of the BCH code. */
    private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    /** Checksum constant for bech32 (BIP-173, witness v0). */
    private const CONST_BECH32 = 1;

    /** Checksum constant for bech32m (BIP-350, witness v1+). */
    private const CONST_BECH32M = 0x2bc830a3;

    public const ENCODING_BECH32 = 'bech32';
    public const ENCODING_BECH32M = 'bech32m';

    /**
     * Decode a bech32/bech32m string into its human-readable part and
     * 5-bit data payload.
     *
     * @return array{hrp:string,data:list<int>,encoding:string}|null
     *         null when the string is malformed or the checksum fails.
     */
    public static function decodeRaw(string $input): ?array
    {
        // BIP-173 caps addresses at 90 characters. Anything longer would
        // weaken the checksum's error-detection guarantee.
        $len = strlen($input);
        if ($len < 8 || $len > 90) {
            return null;
        }

        // Mixed case is explicitly invalid: it would make the checksum
        // ambiguous, since case is folded before verification.
        $hasLower = $input !== strtoupper($input);
        $hasUpper = $input !== strtolower($input);
        if ($hasLower && $hasUpper) {
            return null;
        }

        $s = strtolower($input);

        // Printable ASCII only, per spec (33-126 before case folding).
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($s[$i]);
            if ($ord < 33 || $ord > 126) {
                return null;
            }
        }

        // The separator is the LAST '1' - the hrp itself may contain one.
        $sep = strrpos($s, '1');
        if ($sep === false || $sep < 1 || $sep + 7 > $len) {
            return null;
        }

        $hrp = substr($s, 0, $sep);
        $dataPart = substr($s, $sep + 1);

        $data = [];
        foreach (str_split($dataPart) as $char) {
            $index = strpos(self::CHARSET, $char);
            if ($index === false) {
                return null;
            }
            $data[] = $index;
        }

        $checksum = self::polymod(array_merge(self::hrpExpand($hrp), $data));

        $encoding = match ($checksum) {
            self::CONST_BECH32  => self::ENCODING_BECH32,
            self::CONST_BECH32M => self::ENCODING_BECH32M,
            default             => null,
        };

        if ($encoding === null) {
            return null;
        }

        return [
            'hrp'      => $hrp,
            // Drop the 6 checksum symbols.
            'data'     => array_slice($data, 0, -6),
            'encoding' => $encoding,
        ];
    }

    /**
     * Decode a segwit address.
     *
     * @param list<string> $allowedHrp Accepted human-readable parts,
     *        e.g. ['bc'] for mainnet. Prevents a testnet address (tb1...)
     *        being accepted as a payout target on mainnet.
     *
     * @return array{version:int,program:string,hrp:string}|null
     */
    public static function decodeSegwit(string $address, array $allowedHrp): ?array
    {
        $decoded = self::decodeRaw($address);
        if ($decoded === null || $decoded['data'] === []) {
            return null;
        }

        if (!in_array($decoded['hrp'], array_map('strtolower', $allowedHrp), true)) {
            return null;
        }

        $version = $decoded['data'][0];
        if ($version > 16) {
            return null;
        }

        // BIP-350: v0 must use bech32, v1+ must use bech32m. Enforcing
        // this is what stops a v1 address with a v0 checksum (or vice
        // versa) from being treated as valid.
        $expected = $version === 0 ? self::ENCODING_BECH32 : self::ENCODING_BECH32M;
        if ($decoded['encoding'] !== $expected) {
            return null;
        }

        $program = self::convertBits(array_slice($decoded['data'], 1), 5, 8, false);
        if ($program === null) {
            return null;
        }

        $length = count($program);

        // Witness program length rules (BIP-141 / BIP-350). Note that
        // "witness v1 must be 32 bytes" is NOT one of them - BIP-350
        // permits other lengths at the address layer, reserving them for
        // future use. Taproot's 32-byte requirement is store policy and
        // lives in Ordinals::validatePayoutAddress(), which keeps this
        // decoder verifiable against the published test vectors.
        if ($length < 2 || $length > 40) {
            return null;
        }
        if ($version === 0 && $length !== 20 && $length !== 32) {
            return null;
        }

        return [
            'version' => $version,
            'program' => implode('', array_map('chr', $program)),
            'hrp'     => $decoded['hrp'],
        ];
    }

    /**
     * Regroup bits between bases. 5 -> 8 when decoding a witness program,
     * 8 -> 5 when encoding.
     *
     * @param list<int> $values
     * @return list<int>|null null if the padding is invalid, which for
     *         5->8 means the address carries non-zero trailing bits and
     *         must be rejected rather than silently truncated.
     */
    public static function convertBits(array $values, int $from, int $to, bool $pad): ?array
    {
        $acc = 0;
        $bits = 0;
        $out = [];
        $maxValue = (1 << $to) - 1;
        $maxAcc = (1 << ($from + $to - 1)) - 1;

        foreach ($values as $value) {
            if ($value < 0 || ($value >> $from) !== 0) {
                return null;
            }
            $acc = (($acc << $from) | $value) & $maxAcc;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $out[] = ($acc >> $bits) & $maxValue;
            }
        }

        if ($pad) {
            if ($bits > 0) {
                $out[] = ($acc << ($to - $bits)) & $maxValue;
            }
        } elseif ($bits >= $from || (($acc << ($to - $bits)) & $maxValue) !== 0) {
            return null;
        }

        return $out;
    }

    /** @param list<int> $values */
    private static function polymod(array $values): int
    {
        $chk = 1;

        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if ((($top >> $i) & 1) !== 0) {
                    $chk ^= self::GENERATOR[$i];
                }
            }
        }

        return $chk;
    }

    /**
     * Expand the human-readable part into checksum input: high bits of
     * each character, a separator zero, then the low bits.
     *
     * @return list<int>
     */
    private static function hrpExpand(string $hrp): array
    {
        $high = [];
        $low = [];

        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($hrp[$i]);
            $high[] = $ord >> 5;
            $low[] = $ord & 31;
        }

        return array_merge($high, [0], $low);
    }
}
