<?php

declare(strict_types=1);

namespace App;

use App\Lib\Base58Check;
use App\Lib\Bech32;
use App\Lib\Config;
use App\Lib\Fmt;

/**
 * Bitcoin Ordinals domain rules: what a valid payout address looks like,
 * what a valid inscription identifier looks like, and where to point a
 * block explorer.
 *
 * Why taproot is required for payouts
 * -----------------------------------
 * An inscription lives on a specific satoshi. Moving it means spending
 * the UTXO holding that sat while keeping it at the right offset in the
 * outputs. Wallets that are not ordinals-aware treat that UTXO as
 * ordinary change or, worse, as fee material - the inscription is then
 * either buried in a mixed output or handed to a miner. In practice the
 * ordinals-aware wallets (Xverse, Leather, Unisat, OrdinalsWallet) all
 * receive to taproot, so requiring witness v1 is the closest proxy we
 * have for "this address belongs to a wallet that will not destroy the
 * thing we just sent it".
 *
 * This is a policy check, not a guarantee. It is still worth making,
 * because the failure it prevents is unrecoverable.
 */
final class Ordinals
{
    public const WITNESS_TAPROOT = 1;
    public const WITNESS_V0 = 0;

    /**
     * Validate a buyer payout address.
     *
     * @return array{ok:bool,error:string,kind:string,normalized:string}
     *         `kind` is one of taproot|segwit-v0|segwit-future|legacy|invalid.
     *         On success `normalized` is the lowercase form to store.
     */
    public static function validatePayoutAddress(string $input): array
    {
        $address = trim($input);

        $fail = static fn (string $error, string $kind = 'invalid'): array => [
            'ok'         => false,
            'error'      => $error,
            'kind'       => $kind,
            'normalized' => '',
        ];

        if ($address === '') {
            return $fail('Enter the Bitcoin address that should receive the inscription.');
        }

        // Reject whitespace inside the address before anything else. A
        // pasted address with a line break in it is the most common bad
        // input, and silently stripping it would mean storing something
        // the user never actually checked.
        if (preg_match('/\s/', $address) === 1) {
            return $fail('That address contains a space or line break. Paste it again as a single unbroken string.');
        }

        /** @var list<string> $hrp */
        $hrp = Config::get('chain.address_hrp', ['bc']);

        $segwit = Bech32::decodeSegwit($address, $hrp);

        if ($segwit !== null) {
            $normalized = strtolower($address);

            // Taproot proper: witness v1 with a 32-byte program (an x-only
            // public key). Other v1 lengths are valid bech32m addresses
            // reserved for future use, and are not spendable by any wallet
            // today - paying one would be a permanent loss.
            if ($segwit['version'] === self::WITNESS_TAPROOT) {
                if (strlen($segwit['program']) !== 32) {
                    return $fail(
                        'That address has a witness v1 program of an unexpected length. '
                        . 'A taproot address encodes exactly 32 bytes.',
                        'segwit-future'
                    );
                }

                return ['ok' => true, 'error' => '', 'kind' => 'taproot', 'normalized' => $normalized];
            }

            if (!Config::bool('chain.require_taproot', true)) {
                return [
                    'ok'         => true,
                    'error'      => '',
                    'kind'       => $segwit['version'] === self::WITNESS_V0 ? 'segwit-v0' : 'segwit-future',
                    'normalized' => $normalized,
                ];
            }

            if ($segwit['version'] === self::WITNESS_V0) {
                return $fail(
                    'That is a SegWit v0 address (bc1q…). Inscriptions must be sent to a taproot address, '
                    . 'which starts with bc1p. Use the Ordinals or taproot receive address from your wallet.',
                    'segwit-v0'
                );
            }

            return $fail(
                'That address uses a witness version this store does not pay out to. Use a taproot (bc1p…) address.',
                'segwit-future'
            );
        }

        // Not bech32. Distinguish "legacy address" from "typo" so the error
        // message tells the user what to do differently.
        $legacy = Base58Check::decode($address);
        if ($legacy !== null) {
            return $fail(
                'That is a legacy Bitcoin address. It can receive bitcoin, but a wallet using it is unlikely to '
                . 'track inscriptions, and sending one there risks losing it. Use a taproot (bc1p…) address.',
                'legacy'
            );
        }

        if (str_starts_with(strtolower($address), 'bc1')) {
            return $fail(
                'That address failed its checksum, which means at least one character is wrong. '
                . 'Copy it again from your wallet rather than retyping it.'
            );
        }

        return $fail('That does not look like a Bitcoin address. Inscriptions are paid out to taproot (bc1p…) addresses.');
    }

    /** Convenience wrapper for callers that only need a yes/no. */
    public static function isValidPayoutAddress(string $address): bool
    {
        return self::validatePayoutAddress($address)['ok'];
    }

    /**
     * Validate an inscription id: the 64-hex txid of the reveal
     * transaction, then `i`, then the inscription's index within it.
     * e.g. 6fb976ab...b6d3i0
     */
    public static function isValidInscriptionId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{64}i(0|[1-9][0-9]{0,4})$/', strtolower(trim($id))) === 1;
    }

    public static function isValidTxid(string $txid): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', strtolower(trim($txid))) === 1;
    }

    /** The reveal txid embedded in an inscription id, or null. */
    public static function txidFromInscriptionId(string $id): ?string
    {
        if (!self::isValidInscriptionId($id)) {
            return null;
        }

        return substr(strtolower(trim($id)), 0, 64);
    }

    public static function explorerTxUrl(string $txid): string
    {
        return self::isValidTxid($txid)
            ? sprintf(Config::string('chain.explorer_tx'), strtolower($txid))
            : '';
    }

    public static function explorerAddressUrl(string $address): string
    {
        return self::isValidPayoutAddress($address) || Base58Check::decode($address) !== null
            ? sprintf(Config::string('chain.explorer_addr'), $address)
            : '';
    }

    public static function explorerInscriptionUrl(string $inscriptionId): string
    {
        return self::isValidInscriptionId($inscriptionId)
            ? sprintf(Config::string('chain.explorer_insc'), strtolower($inscriptionId))
            : '';
    }

    /** Display form for an inscription id: 6fb976ab…b6d3i0 */
    public static function shortInscriptionId(string $id): string
    {
        return Fmt::truncateMiddle($id, 8, 7);
    }
}
