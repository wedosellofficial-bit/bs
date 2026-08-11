<?php

declare(strict_types=1);

namespace App;

use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\Logger;
use RuntimeException;

/**
 * Billions Membership: a one-time fee, paid from wallet balance through
 * the same ledger as any other purchase, that flips users.is_member.
 *
 * Gate mode
 * ---------
 * `membership.gate` decides what membership controls, and defaults to
 * 'all' - a full paywall, where nothing on the storefront is reachable
 * until a visitor registers and pays the fee. That default is a
 * deliberate operator choice for this store, not a technical necessity:
 * the code also supports 'purchase' (browsing open, buying gated) and
 * 'off' (membership is a pure upgrade, gates nothing) via the same
 * config key, so the operator can loosen it later without a code change.
 * See Router::membershipGateApplies() for where the mode is enforced.
 */
final class Membership
{
    public const GATE_ALL = 'all';
    public const GATE_PURCHASE = 'purchase';
    public const GATE_OFF = 'off';

    public static function feeMinor(): int
    {
        return max(0, Config::int('membership.fee_minor', 5000));
    }

    public static function gateMode(): string
    {
        $mode = Config::string('membership.gate', self::GATE_ALL);

        return in_array($mode, [self::GATE_ALL, self::GATE_PURCHASE, self::GATE_OFF], true)
            ? $mode
            : self::GATE_ALL;
    }

    /** @param array<string,mixed>|null $user */
    public static function isMember(?array $user): bool
    {
        return $user !== null && !empty($user['is_member']);
    }

    /**
     * Pay the membership fee from wallet balance and activate membership.
     *
     * @return array{balance_before:int,balance_after:int,fee_minor:int}
     * @throws RuntimeException with a message safe to show the buyer.
     */
    public static function join(int $userId): array
    {
        $fee = self::feeMinor();

        return Wallet::withUserLock($userId, static function (int $balanceBefore) use ($userId, $fee): array {
            $user = Database::first('SELECT is_member FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Account not found.');
            }

            if ((bool) $user['is_member']) {
                throw new RuntimeException('You are already a member.');
            }

            if ($fee > 0 && $balanceBefore < $fee) {
                $shortfall = $fee - $balanceBefore;

                throw new RuntimeException(sprintf(
                    'Your balance is %s and membership is %s. Top up %s to join.',
                    Fmt::money($balanceBefore),
                    Fmt::money($fee),
                    Fmt::money($shortfall)
                ));
            }

            Database::run(
                'UPDATE users SET is_member = 1, member_since = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$userId]
            );

            Database::run(
                'INSERT INTO membership_payments (user_id, fee_minor, currency, created_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [$userId, $fee, Config::string('ledger.currency', 'USD')]
            );

            $paymentId = Database::lastInsertId();

            if ($fee > 0) {
                Wallet::debit(
                    $userId,
                    $fee,
                    Wallet::TYPE_PURCHASE,
                    'membership_payment',
                    $paymentId,
                    'Billions Membership'
                );
            }

            Logger::audit(
                'membership.joined',
                sprintf('Joined Billions Membership for %s', Fmt::money($fee)),
                $userId,
                'user',
                $userId,
                ['fee_minor' => $fee]
            );

            return [
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceBefore - $fee,
                'fee_minor'      => $fee,
            ];
        });
    }

    /** Dashboard total: members and lifetime fee revenue. */
    public static function stats(): array
    {
        $row = Database::first(
            "SELECT COUNT(*) AS members FROM users WHERE is_member = 1"
        );

        $revenue = Database::first(
            'SELECT CAST(COALESCE(SUM(fee_minor), 0) AS SIGNED) AS revenue_minor FROM membership_payments'
        );

        return [
            'members'       => (int) ($row['members'] ?? 0),
            'revenue_minor' => (int) ($revenue['revenue_minor'] ?? 0),
        ];
    }
}
