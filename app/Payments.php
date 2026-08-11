<?php

declare(strict_types=1);

namespace App;

use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\HttpClient;
use App\Lib\Logger;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Coinbase Commerce integration: charge creation and webhook handling.
 *
 * DISABLED. Coinbase Commerce is not reachable from this store's
 * operating country. Nothing in the application calls createDeposit()
 * or handleWebhook() any more - there is no route to /webhooks/coinbase
 * (WebhookController was removed) and no UI that calls createDeposit().
 * The live deposit path is a single operator-held BTC address shown on
 * the wallet page (see WalletController, App\Lib\Config's
 * 'manual_deposit' section), credited by hand through
 * AdminUserController::manualCredit() - the same ledger write every
 * other credit goes through.
 *
 * This class is kept, not deleted, in case a future market makes
 * Coinbase Commerce usable for this store again: re-add the webhook
 * route and the wallet-page "generate address" form (see git history
 * around the commit that disabled this) and it should work unchanged.
 * The `deposits` and `exchange_rates` tables it reads and writes are
 * likewise still in the schema, empty and harmless.
 *
 * Everything below this point describes the class as it behaves WHEN
 * routed to, which is why the original design notes are left intact.
 *
 * The one rule that shapes this whole file
 * ----------------------------------------
 * Money is created in exactly one place - handleWebhook(), after a
 * signature check. Never on a page load, never on the redirect back from
 * the provider, never from a client-side callback. Those are all
 * attacker-controllable: a redirect URL can be visited directly, and a
 * "payment succeeded" fetch from the browser is just a request anyone can
 * make.
 *
 * Idempotency is layered, because providers retry and will replay a
 * delivery even after a 2xx:
 *
 *   1. webhook_events has UNIQUE(provider, event_id) - a repeat delivery
 *      of the same event never reaches the handler body at all.
 *   2. deposits has UNIQUE(provider, provider_charge_id) - two events
 *      about the same charge share one deposit row, which is locked
 *      FOR UPDATE and re-checked before crediting.
 *   3. wallet_entries has UNIQUE(type, reference_type, reference_id) - if
 *      both of the above were somehow bypassed, the second credit still
 *      cannot be written.
 *
 * Any one of those would usually be enough. All three are cheap, and the
 * failure they prevent is "we gave someone free money and only found out
 * at reconciliation".
 */
final class Payments
{
    public const PROVIDER = 'coinbase_commerce';

    /** Coinbase Commerce pins behaviour to a dated API version. */
    private const API_VERSION = '2018-03-22';

    //-----------------------------------------------------------------
    // Signature verification
    //-----------------------------------------------------------------

    /**
     * Verify the X-CC-Webhook-Signature header.
     *
     * Coinbase Commerce signs the *raw request body* with the webhook
     * shared secret (HMAC-SHA256, hex). Two details matter:
     *
     *  - The body must be the bytes as received. Verifying a re-encoded
     *    json_decode/json_encode round-trip will fail on key order and
     *    unicode escaping, and "fixing" that by loosening the check is how
     *    signature verification becomes decorative.
     *
     *  - hash_equals(), not ===. String comparison short-circuits on the
     *    first differing byte, which leaks how much of a guess was right.
     *
     * Static and dependency-free so bin/test.php can exercise it directly.
     */
    public static function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        $signature = strtolower(trim($signatureHeader));

        if ($secret === '' || $signature === '') {
            return false;
        }

        // Reject anything that is not a 64-character hex digest before
        // spending an HMAC on it.
        if (preg_match('/^[0-9a-f]{64}$/', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * Amount to credit after the store's processing fee.
     *
     * intdiv() floors, so a fractional cent of fee always rounds towards
     * the store rather than towards the customer. The direction matters
     * less than it being deterministic and stated: a fee that rounds
     * differently on different amounts is unreconcilable.
     */
    public static function creditableMinor(int $grossMinor, int $feeBps): int
    {
        if ($grossMinor <= 0 || $feeBps <= 0) {
            return max(0, $grossMinor);
        }

        $fee = intdiv($grossMinor * $feeBps, 10_000);

        // Never reduce a deposit to nothing through fees alone.
        return max(1, $grossMinor - $fee);
    }

    //-----------------------------------------------------------------
    // Charge creation
    //-----------------------------------------------------------------

    /**
     * Create a Coinbase Commerce charge and the matching deposit row.
     *
     * Returns the deposit row, which carries the fresh address, the exact
     * BTC amount, the rate we quoted and when the quote expires. The
     * caller renders all of that plus a QR code.
     *
     * @return array<string,mixed>
     */
    public static function createDeposit(int $userId, int $amountMinorRequested): array
    {
        $min = Config::int('ledger.topup_min', 2500);
        $max = Config::int('ledger.topup_max', 2500000);

        if ($amountMinorRequested < $min || $amountMinorRequested > $max) {
            throw new RuntimeException(sprintf(
                'Top-up must be between %s and %s.',
                Fmt::money($min),
                Fmt::money($max)
            ));
        }

        $currency = Config::string('ledger.currency', 'USD');
        $digits = Config::int('ledger.minor_digits', 2);
        $localAmount = number_format($amountMinorRequested / (10 ** $digits), $digits, '.', '');

        $response = HttpClient::postJson(
            Config::string('payments.api_url') . '/charges',
            [
                'name'         => Config::string('app.name') . ' account top-up',
                'description'  => sprintf('Adds %s to your store balance.', Fmt::money($amountMinorRequested)),
                'pricing_type' => 'fixed_price',
                'local_price'  => ['amount' => $localAmount, 'currency' => $currency],
                // Echoed back on every webhook for this charge. Never
                // trusted as authority - the deposit row is looked up by
                // charge id, and this is only used to cross-check.
                'metadata'     => ['user_id' => (string) $userId],
                'redirect_url' => Config::string('app.url') . '/account/wallet?deposit=return',
                'cancel_url'   => Config::string('app.url') . '/account/wallet?deposit=cancelled',
            ],
            [
                'X-CC-Api-Key'  => Config::required('payments.api_key'),
                'X-CC-Version'  => self::API_VERSION,
                'Accept'        => 'application/json',
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::write('payments.charge_failed', 'Provider rejected charge creation', [
                'status'  => $response['status'],
                'user_id' => $userId,
            ]);

            throw new RuntimeException('The payment provider could not create a deposit address right now. Try again in a moment.');
        }

        $data = HttpClient::decode($response['body'])['data'] ?? null;
        if (!is_array($data) || !isset($data['id'])) {
            throw new RuntimeException('The payment provider returned an unexpected response.');
        }

        $asset = Config::string('payments.asset', 'BTC');
        $addresses = is_array($data['addresses'] ?? null) ? $data['addresses'] : [];
        $pricing = is_array($data['pricing'] ?? null) ? $data['pricing'] : [];

        // Coinbase Commerce keys addresses and pricing by lowercase network
        // name ("bitcoin"), not by ticker.
        $network = self::networkKey($asset);
        $address = is_string($addresses[$network] ?? null) ? $addresses[$network] : null;
        $cryptoAmount = is_array($pricing[$network] ?? null) && isset($pricing[$network]['amount'])
            ? (string) $pricing[$network]['amount']
            : null;

        if ($address === null || $cryptoAmount === null) {
            throw new RuntimeException("The payment provider did not return a {$asset} address for this charge.");
        }

        // Derive the rate from the charge itself rather than a separate
        // rate lookup. This is the exact rate the provider will settle at,
        // and it is quoted at a known instant - so the timestamp shown to
        // the user is real, not "now".
        $rate = self::deriveRate($amountMinorRequested, $cryptoAmount, $digits);

        $expiresAt = self::parseProviderTime($data['expires_at'] ?? null)
            ?? gmdate('Y-m-d H:i:s', time() + Config::int('payments.quote_lock', 3600));

        Database::run(
            'INSERT INTO deposits
                (user_id, provider, provider_charge_id, provider_charge_code, address, asset,
                 amount_crypto, amount_minor_requested, quoted_rate, quoted_at, quote_expires_at,
                 required_confirmations, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, UTC_TIMESTAMP())',
            [
                $userId,
                self::PROVIDER,
                (string) $data['id'],
                isset($data['code']) ? (string) $data['code'] : null,
                $address,
                $asset,
                $cryptoAmount,
                $amountMinorRequested,
                $rate,
                $expiresAt,
                Config::int('payments.required_confs', 2),
                'pending',
            ]
        );

        $depositId = Database::lastInsertId();

        // Cache the derived rate so the wallet page can show an indicative
        // figure before the next charge is created.
        self::storeRate($asset, $currency, $rate, 'coinbase_commerce_charge');

        Logger::audit(
            'deposit.created',
            sprintf('Deposit address issued for %s', Fmt::money($amountMinorRequested)),
            $userId,
            'deposit',
            $depositId,
            ['amount_minor' => $amountMinorRequested, 'asset' => $asset, 'address' => $address]
        );

        $deposit = self::findDeposit($depositId);
        if ($deposit === null) {
            throw new RuntimeException('Deposit could not be read back after creation.');
        }

        return $deposit;
    }

    //-----------------------------------------------------------------
    // Webhook handling
    //-----------------------------------------------------------------

    /**
     * Process one webhook delivery.
     *
     * @return array{status:string,http:int,detail:string}
     *         `http` is what the endpoint should return. A 2xx tells the
     *         provider to stop retrying, so it is returned for anything we
     *         have durably recorded - including events we deliberately
     *         ignore. 5xx is reserved for "we failed, please retry".
     */
    public static function handleWebhook(string $rawBody, string $signatureHeader): array
    {
        $secret = Config::string('payments.webhook_secret');

        if (!self::verifyWebhookSignature($rawBody, $signatureHeader, $secret)) {
            Logger::audit('webhook.rejected', 'Webhook signature verification failed', null, null, null, [
                'body_bytes'   => strlen($rawBody),
                'has_signature' => $signatureHeader !== '',
            ]);

            // 400, not 401: there is nothing to authenticate against and we
            // do not want the provider retrying a body we will never accept.
            return ['status' => 'rejected', 'http' => 400, 'detail' => 'invalid signature'];
        }

        $payload = HttpClient::decode($rawBody);
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : null;

        if ($event === null || !isset($event['id'], $event['type'])) {
            return ['status' => 'malformed', 'http' => 400, 'detail' => 'missing event envelope'];
        }

        $eventId = (string) $event['id'];
        $eventType = (string) $event['type'];

        // Layer 1: the event id itself. A duplicate delivery stops here.
        try {
            Database::run(
                'INSERT INTO webhook_events (provider, event_id, event_type, signature_ok, payload, received_at)
                 VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())',
                [self::PROVIDER, $eventId, $eventType, $rawBody]
            );
        } catch (Throwable $e) {
            if (Database::isDuplicateKey($e)) {
                Logger::write('webhook.duplicate', 'Replayed webhook ignored', [
                    'event_id' => $eventId,
                    'type'     => $eventType,
                ]);

                // 200: it is already handled, stop retrying.
                return ['status' => 'duplicate', 'http' => 200, 'detail' => 'already processed'];
            }

            throw $e;
        }

        $webhookRowId = Database::lastInsertId();

        try {
            $charge = is_array($event['data'] ?? null) ? $event['data'] : [];
            $result = self::dispatch($eventType, $charge);

            Database::run(
                'UPDATE webhook_events SET processed_at = UTC_TIMESTAMP() WHERE id = ?',
                [$webhookRowId]
            );

            return ['status' => $result, 'http' => 200, 'detail' => $eventType];
        } catch (Throwable $e) {
            Database::run(
                'UPDATE webhook_events SET process_error = ? WHERE id = ?',
                [mb_substr($e->getMessage(), 0, 500), $webhookRowId]
            );

            Logger::write('webhook.error', $e->getMessage(), [
                'event_id' => $eventId,
                'type'     => $eventType,
            ]);

            // 500 asks the provider to retry. The event row already exists,
            // so a retry would be swallowed as a duplicate - clear
            // processed_at handling is why process_error is recorded and
            // surfaced in the admin deposits screen for manual replay.
            return ['status' => 'error', 'http' => 500, 'detail' => 'processing failed'];
        }
    }

    /**
     * @param array<string,mixed> $charge
     */
    private static function dispatch(string $eventType, array $charge): string
    {
        $chargeId = isset($charge['id']) ? (string) $charge['id'] : '';
        if ($chargeId === '') {
            return 'ignored:no charge id';
        }

        return match ($eventType) {
            // We created the deposit row ourselves; nothing to do.
            'charge:created' => 'ignored',

            // Seen on-chain, not yet settled. Record the txid so the user's
            // pending row becomes clickable, but credit nothing.
            'charge:pending' => self::markPending($chargeId, $charge),

            // Settled. This is the only path that creates money.
            'charge:confirmed', 'charge:resolved' => self::creditDeposit($chargeId, $charge),

            // Underpaid, or paid after the quote expired. Never auto-credit:
            // the amount received may not match what was quoted, and an
            // automatic partial credit at a stale rate is a dispute waiting
            // to happen. Flag it for an admin.
            'charge:delayed' => self::markForReview($chargeId, $charge),

            'charge:failed' => self::markFailed($chargeId, $charge),

            default => 'ignored:' . $eventType,
        };
    }

    /** @param array<string,mixed> $charge */
    private static function markPending(string $chargeId, array $charge): string
    {
        $payment = self::latestPayment($charge);

        Database::run(
            "UPDATE deposits
                SET status = IF(status = 'pending', 'pending', status),
                    txid = COALESCE(?, txid),
                    confirmations = GREATEST(confirmations, ?)
              WHERE provider = ? AND provider_charge_id = ?",
            [
                $payment['txid'],
                $payment['confirmations'],
                self::PROVIDER,
                $chargeId,
            ]
        );

        return 'pending';
    }

    /**
     * Credit a settled deposit.
     *
     * @param array<string,mixed> $charge
     */
    private static function creditDeposit(string $chargeId, array $charge): string
    {
        $deposit = Database::first(
            'SELECT * FROM deposits WHERE provider = ? AND provider_charge_id = ?',
            [self::PROVIDER, $chargeId]
        );

        if ($deposit === null) {
            // A charge we have no record of. Do not invent a user to credit
            // - record it and let an admin look.
            Logger::audit('deposit.unknown_charge', 'Confirmed charge with no matching deposit row', null, null, null, [
                'charge_id' => $chargeId,
            ]);

            return 'ignored:unknown charge';
        }

        $userId = (int) $deposit['user_id'];
        $depositId = (int) $deposit['id'];
        $payment = self::latestPayment($charge);

        // What the provider says the payment was worth in our currency.
        // Preferred over the amount we asked for: if the customer sent less
        // (or more) BTC than quoted, this is the truthful figure.
        $receivedMinor = $payment['local_minor'] ?? (int) $deposit['amount_minor_requested'];
        $requestedMinor = (int) $deposit['amount_minor_requested'];

        // A tolerance of one minor unit absorbs the provider's own rounding
        // between the quote and the settlement value. Anything below that
        // is a genuine underpayment.
        if ($receivedMinor < $requestedMinor - 1) {
            return self::markForReview($chargeId, $charge, $receivedMinor);
        }

        $creditMinor = self::creditableMinor($receivedMinor, Config::int('payments.fee_bps', 0));
        $feeMinor = $receivedMinor - $creditMinor;

        Wallet::withUserLock($userId, static function () use (
            $depositId,
            $userId,
            $creditMinor,
            $feeMinor,
            $receivedMinor,
            $payment,
            $chargeId
        ): void {
            // Layer 2: re-read the deposit under the lock. Two events for
            // the same charge arriving at once both get here; the second
            // one sees status='credited' and stops.
            $fresh = Database::first('SELECT status FROM deposits WHERE id = ? FOR UPDATE', [$depositId]);

            if ($fresh === null || $fresh['status'] === 'credited') {
                return;
            }

            // Layer 3: the unique key on (type, reference_type, reference_id)
            // makes a second credit for this deposit impossible even here.
            Wallet::credit(
                $userId,
                $creditMinor,
                Wallet::TYPE_DEPOSIT,
                'deposit',
                $depositId,
                sprintf('Bitcoin deposit %s', Fmt::truncateMiddle($chargeId, 6, 4))
            );

            Database::run(
                "UPDATE deposits
                    SET status = 'credited',
                        amount_minor_credited = ?,
                        fee_minor = ?,
                        amount_crypto_received = COALESCE(?, amount_crypto_received),
                        txid = COALESCE(?, txid),
                        confirmations = GREATEST(confirmations, ?),
                        confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP()),
                        credited_at = UTC_TIMESTAMP()
                  WHERE id = ?",
                [
                    $creditMinor,
                    $feeMinor,
                    $payment['crypto_amount'],
                    $payment['txid'],
                    max($payment['confirmations'], 1),
                    $depositId,
                ]
            );
        });

        Logger::audit(
            'deposit.credited',
            sprintf('Credited %s from deposit #%d', Fmt::money($creditMinor), $depositId),
            null,
            'deposit',
            $depositId,
            [
                'user_id'        => $userId,
                'received_minor' => $receivedMinor,
                'credit_minor'   => $creditMinor,
                'fee_minor'      => $feeMinor,
                'txid'           => $payment['txid'],
            ]
        );

        return 'credited';
    }

    /** @param array<string,mixed> $charge */
    private static function markForReview(string $chargeId, array $charge, ?int $receivedMinor = null): string
    {
        $payment = self::latestPayment($charge);

        Database::run(
            "UPDATE deposits
                SET status = IF(status = 'credited', 'credited', 'underpaid'),
                    amount_crypto_received = COALESCE(?, amount_crypto_received),
                    txid = COALESCE(?, txid),
                    confirmations = GREATEST(confirmations, ?)
              WHERE provider = ? AND provider_charge_id = ?",
            [$payment['crypto_amount'], $payment['txid'], $payment['confirmations'], self::PROVIDER, $chargeId]
        );

        Logger::audit(
            'deposit.needs_review',
            'Deposit settled outside the quoted terms and needs a manual decision',
            null,
            null,
            null,
            [
                'charge_id'      => $chargeId,
                'received_minor' => $receivedMinor ?? $payment['local_minor'],
            ]
        );

        return 'review';
    }

    /** @param array<string,mixed> $charge */
    private static function markFailed(string $chargeId, array $charge): string
    {
        Database::run(
            "UPDATE deposits
                SET status = IF(status IN ('credited','underpaid'), status, 'failed')
              WHERE provider = ? AND provider_charge_id = ?",
            [self::PROVIDER, $chargeId]
        );

        return 'failed';
    }

    /**
     * Pull the most informative payment out of a charge payload.
     *
     * Coinbase Commerce reports payments as a list; a charge normally has
     * one, but a retried or topped-up payment can produce more. The
     * confirmed one wins, otherwise the last.
     *
     * @param array<string,mixed> $charge
     * @return array{txid:?string,confirmations:int,crypto_amount:?string,local_minor:?int}
     */
    private static function latestPayment(array $charge): array
    {
        $out = ['txid' => null, 'confirmations' => 0, 'crypto_amount' => null, 'local_minor' => null];

        $payments = is_array($charge['payments'] ?? null) ? $charge['payments'] : [];

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $status = strtoupper((string) ($payment['status'] ?? ''));
            $isConfirmed = $status === 'CONFIRMED';

            // Skip a non-confirmed payment once we already have a confirmed
            // one recorded.
            if (!$isConfirmed && $out['txid'] !== null && $out['confirmations'] > 0) {
                continue;
            }

            $txid = $payment['transaction_id'] ?? null;
            if (is_string($txid) && $txid !== '') {
                $out['txid'] = mb_substr($txid, 0, 100);
            }

            $block = is_array($payment['block'] ?? null) ? $payment['block'] : [];
            if (isset($block['confirmations']) && is_numeric($block['confirmations'])) {
                $out['confirmations'] = max($out['confirmations'], (int) $block['confirmations']);
            } elseif ($isConfirmed) {
                $out['confirmations'] = max($out['confirmations'], 1);
            }

            $value = is_array($payment['value'] ?? null) ? $payment['value'] : [];

            $crypto = is_array($value['crypto'] ?? null) ? $value['crypto'] : [];
            if (isset($crypto['amount']) && is_numeric((string) $crypto['amount'])) {
                $out['crypto_amount'] = (string) $crypto['amount'];
            }

            $local = is_array($value['local'] ?? null) ? $value['local'] : [];
            if (isset($local['amount']) && is_numeric((string) $local['amount'])) {
                $digits = Config::int('ledger.minor_digits', 2);
                // Round rather than truncate: the provider's local value is
                // already a decimal in our currency, so 99.995 is a rounding
                // artefact, not an attempt to shave a cent.
                $out['local_minor'] = (int) round(((float) $local['amount']) * (10 ** $digits));
            }
        }

        return $out;
    }

    //-----------------------------------------------------------------
    // Rates and lookups
    //-----------------------------------------------------------------

    /**
     * Indicative BTC rate for the wallet page, with the time it was
     * fetched. Cached for five minutes.
     *
     * Explicitly indicative: the binding rate is the one quoted on the
     * charge when a deposit address is generated. The UI says so, because
     * a rate presented without that distinction is a promise the store
     * cannot keep.
     *
     * @return array{rate:?string,fetched_at:?string,source:string}
     */
    public static function indicativeRate(): array
    {
        $asset = Config::string('payments.asset', 'BTC');
        $currency = Config::string('ledger.currency', 'USD');

        $cached = Database::first(
            'SELECT rate, source, fetched_at FROM exchange_rates
              WHERE base = ? AND quote = ?
              ORDER BY fetched_at DESC LIMIT 1',
            [$asset, $currency]
        );

        $isFresh = $cached !== null
            && strtotime((string) $cached['fetched_at'] . ' UTC') > time() - 300;

        if ($isFresh) {
            return [
                'rate'       => (string) $cached['rate'],
                'fetched_at' => (string) $cached['fetched_at'],
                'source'     => (string) $cached['source'],
            ];
        }

        try {
            $response = HttpClient::get(
                'https://api.coinbase.com/v2/exchange-rates?currency=' . rawurlencode($asset),
                ['Accept' => 'application/json'],
                8
            );

            if ($response['status'] === 200) {
                $rate = HttpClient::decode($response['body'])['data']['rates'][$currency] ?? null;

                if (is_numeric((string) $rate)) {
                    self::storeRate($asset, $currency, (string) $rate, 'coinbase_spot');

                    return [
                        'rate'       => (string) $rate,
                        'fetched_at' => gmdate('Y-m-d H:i:s'),
                        'source'     => 'coinbase_spot',
                    ];
                }
            }
        } catch (Throwable $e) {
            // A rate lookup failing must not break the wallet page. Fall
            // through to whatever was last cached, however old, and let the
            // UI show its real age.
            Logger::write('payments.rate_fetch_failed', $e->getMessage());
        }

        return $cached === null
            ? ['rate' => null, 'fetched_at' => null, 'source' => 'unavailable']
            : [
                'rate'       => (string) $cached['rate'],
                'fetched_at' => (string) $cached['fetched_at'],
                'source'     => (string) $cached['source'],
            ];
    }

    /** @return array<string,mixed>|null */
    public static function findDeposit(int $depositId): ?array
    {
        return Database::first('SELECT * FROM deposits WHERE id = ?', [$depositId]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function depositsForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return Database::all(
            "SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$userId]
        );
    }

    /** The user's most recent still-usable deposit quote, if any. */
    public static function activeDeposit(int $userId): ?array
    {
        return Database::first(
            "SELECT * FROM deposits
              WHERE user_id = ?
                AND status = 'pending'
                AND quote_expires_at > UTC_TIMESTAMP()
              ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    /**
     * Expire abandoned quotes. Called by cron.
     *
     * Only touches rows that are still `pending` - a deposit that was paid
     * late is handled by charge:delayed and must not be stomped on here.
     */
    public static function expireStaleQuotes(): int
    {
        $stmt = Database::run(
            "UPDATE deposits
                SET status = 'expired'
              WHERE status = 'pending'
                AND quote_expires_at < UTC_TIMESTAMP()"
        );

        return $stmt->rowCount();
    }

    //-----------------------------------------------------------------

    private static function storeRate(string $base, string $quote, string $rate, string $source): void
    {
        try {
            Database::run(
                'INSERT INTO exchange_rates (base, quote, rate, source, fetched_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                [$base, $quote, $rate, $source]
            );
        } catch (Throwable $e) {
            Logger::write('payments.rate_store_failed', $e->getMessage());
        }
    }

    /**
     * fiat minor units + crypto amount -> rate per whole coin, as a
     * decimal string with 8 places.
     */
    private static function deriveRate(int $amountMinor, string $cryptoAmount, int $digits): ?string
    {
        $crypto = (float) $cryptoAmount;
        if ($crypto <= 0.0) {
            return null;
        }

        return number_format(($amountMinor / (10 ** $digits)) / $crypto, 8, '.', '');
    }

    private static function networkKey(string $asset): string
    {
        return match (strtoupper($asset)) {
            'BTC'  => 'bitcoin',
            'ETH'  => 'ethereum',
            'USDC' => 'usdc',
            'LTC'  => 'litecoin',
            default => strtolower($asset),
        };
    }

    /** ISO-8601 from the provider -> a MySQL DATETIME in UTC. */
    private static function parseProviderTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
