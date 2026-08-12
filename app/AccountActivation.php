<?php

declare(strict_types=1);

namespace App;

use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\Logger;

/**
 * Account activation: a new registration is `pending` until its wallet
 * balance reaches a configured minimum, then it becomes `active`.
 *
 * This is deliberately not a fee. The minimum is ordinary, fully
 * spendable wallet balance, credited through the same
 * `Wallet::credit()` path as any other deposit - see maybeActivate()
 * below, which only ever reads the balance and flips a status flag. It
 * never writes a ledger entry itself, so there is no code path here that
 * could turn a customer's deposit into a non-refundable toll.
 *
 * There is no separate paid membership tier layered on top of this -
 * activation is the only account-level gate in this application. An
 * active account can browse and buy everything in the catalog at one
 * standard price.
 */
final class AccountActivation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';

    public static function minActivationMinor(): int
    {
        return max(0, Config::int('account.min_activation_minor', 5000));
    }

    public static function requiredToPurchase(): bool
    {
        return Config::bool('account.require_activation_to_purchase', true);
    }

    /** @param array<string,mixed>|null $user */
    public static function isActive(?array $user): bool
    {
        return $user !== null && (string) ($user['account_status'] ?? self::STATUS_PENDING) === self::STATUS_ACTIVE;
    }

    /**
     * A buyer-facing sentence naming the exact shortfall, for the
     * checkout error and the pending-account banner alike - not a
     * generic "not allowed".
     */
    public static function activationPrompt(int $currentBalanceMinor): string
    {
        $threshold = self::minActivationMinor();
        $shortfall = max(0, $threshold - $currentBalanceMinor);

        return sprintf(
            'Fund your account with at least %s to start buying. Add %s more and your account activates '
            . 'automatically - the deposit becomes your spendable balance, it is not a fee.',
            Fmt::money($threshold),
            Fmt::money($shortfall)
        );
    }

    /**
     * Activate the account if its balance has reached the minimum.
     *
     * Called from Wallet::credit() - the single choke point every
     * credit passes through - so this check runs "at the point of every
     * credit" without every caller (manual admin credit, a future
     * automated deposit path, anything else) needing to remember to call
     * it itself.
     *
     * One-directional: this only ever moves pending -> active. It never
     * demotes an account back to pending because its balance later drops
     * from ordinary spending - that would make buying something the
     * trigger for locking yourself out of buying the next thing, which
     * is not what "activation" means here. An admin can still demote by
     * hand (AdminUserController::updateAccountStatus()) if that is ever
     * genuinely needed.
     */
    public static function maybeActivate(int $userId): void
    {
        $user = Database::first('SELECT account_status FROM users WHERE id = ?', [$userId]);

        if ($user === null || (string) $user['account_status'] !== self::STATUS_PENDING) {
            return;
        }

        if (Wallet::balance($userId) < self::minActivationMinor()) {
            return;
        }

        Database::run(
            "UPDATE users SET account_status = 'active', updated_at = UTC_TIMESTAMP() WHERE id = ? AND account_status = 'pending'",
            [$userId]
        );

        Logger::audit(
            'account.activated',
            'Account activated: balance reached the minimum funding threshold',
            $userId,
            'user',
            $userId,
            ['threshold_minor' => self::minActivationMinor()]
        );
    }
}
