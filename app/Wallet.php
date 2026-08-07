<?php

declare(strict_types=1);

namespace App;

use App\Lib\Config;
use App\Lib\Logger;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * The balance ledger.
 *
 * Rules this class exists to enforce
 * ---------------------------------
 * 1. `wallet_entries` is append-only. There is no update() and no
 *    delete(). A refund is a new positive entry referencing the order; a
 *    correction is an `adjustment`. History is never rewritten.
 *
 * 2. Balance is always SUM(amount_minor). `wallet_balance_cache` exists,
 *    but no money decision ever reads it - see balance() and
 *    cachedBalances().
 *
 * 3. Every balance-changing operation runs inside withUserLock(), which
 *    serialises them per user. Without that, two concurrent purchases both
 *    read the pre-purchase balance and both pass the affordability check.
 *
 * Why the lock is on the user row and not on the ledger rows
 * ---------------------------------------------------------
 * `SELECT SUM(amount_minor) ... WHERE user_id = ? FOR UPDATE` does work -
 * InnoDB takes next-key locks over the scanned range, which blocks
 * concurrent inserts for that user. But the lock set grows with the
 * user's history, so a customer with 5,000 ledger rows locks 5,000 rows
 * on every purchase.
 *
 * Locking the single `users` row instead is O(1) and gives the same
 * mutual exclusion, provided every writer takes it. That invariant is
 * enforced here: credit() and debit() both refuse to run unless a
 * transaction is open, and the only supported way to open one is
 * withUserLock().
 */
final class Wallet
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    private const TYPES = [
        self::TYPE_DEPOSIT,
        self::TYPE_PURCHASE,
        self::TYPE_REFUND,
        self::TYPE_ADJUSTMENT,
    ];

    /** Users whose row is currently locked by this request, by depth. */
    private static array $lockedUsers = [];

    /**
     * Run $fn with the user's row locked and their authoritative balance
     * already read.
     *
     * The callback receives the balance in minor units as it stands inside
     * the lock. Anything the callback does through credit()/debit() is
     * atomic with respect to other requests for the same user.
     *
     * @template T
     * @param callable(int $balanceMinor, PDO $pdo):T $fn
     * @return T
     */
    public static function withUserLock(int $userId, callable $fn): mixed
    {
        return Database::transaction(static function (PDO $pdo) use ($userId, $fn) {
            // The mutex. SELECT ... FOR UPDATE on a primary-key lookup takes
            // exactly one record lock and blocks any other transaction that
            // tries the same.
            $row = Database::first('SELECT id, status FROM users WHERE id = ? FOR UPDATE', [$userId]);

            if ($row === null) {
                throw new RuntimeException("Cannot lock unknown user {$userId}.");
            }

            self::$lockedUsers[$userId] = true;

            try {
                // Read the balance *inside* the lock. Reading it before
                // would make the lock pointless.
                return $fn(self::balance($userId), $pdo);
            } finally {
                unset(self::$lockedUsers[$userId]);
            }
        });
    }

    /**
     * Authoritative balance: the sum of the ledger, every time.
     *
     * CAST(... AS SIGNED) is not decoration. SUM() over an INT column
     * returns DECIMAL, which PDO hands back as a string because PHP has no
     * decimal type; the cast makes the driver produce a real integer so
     * `$balance >= $price` is an integer comparison rather than a string
     * one.
     */
    public static function balance(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT CAST(COALESCE(SUM(amount_minor), 0) AS SIGNED)
               FROM wallet_entries
              WHERE user_id = ?',
            [$userId],
            0
        );
    }

    /**
     * Write a credit. Amount must be positive.
     *
     * `$referenceType`/`$referenceId` are not optional in practice: the
     * unique key on (type, reference_type, reference_id) is what makes a
     * replayed webhook unable to credit twice, and a NULL reference
     * defeats it. Callers that genuinely have no reference (an admin
     * adjustment) pass 'manual' plus the admin's own audit id.
     */
    public static function credit(
        int $userId,
        int $amountMinor,
        string $type,
        string $referenceType,
        int $referenceId,
        string $memo,
        ?int $createdBy = null,
    ): int {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('credit() requires a positive amount; use debit() to subtract.');
        }

        return self::append($userId, $amountMinor, $type, $referenceType, $referenceId, $memo, $createdBy);
    }

    /**
     * Write a debit. Amount is given positive and stored negative.
     *
     * This does NOT check affordability - the caller must do that inside
     * withUserLock(), because only the caller knows what else it is about
     * to do in the same transaction. See Orders::purchase().
     */
    public static function debit(
        int $userId,
        int $amountMinor,
        string $type,
        string $referenceType,
        int $referenceId,
        string $memo,
        ?int $createdBy = null,
    ): int {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('debit() requires a positive amount.');
        }

        return self::append($userId, -$amountMinor, $type, $referenceType, $referenceId, $memo, $createdBy);
    }

    /**
     * The single INSERT. Everything that touches the ledger comes through
     * here, which is why the audit write and the lock assertion can live
     * in one place.
     */
    private static function append(
        int $userId,
        int $signedAmount,
        string $type,
        string $referenceType,
        int $referenceId,
        string $memo,
        ?int $createdBy,
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown ledger entry type: {$type}");
        }

        if (!Database::pdo()->inTransaction()) {
            // A ledger write outside a transaction cannot be atomic with
            // whatever it is paying for, and cannot have been serialised
            // against a concurrent write. Fail loudly rather than write a
            // row that might be half of a purchase.
            throw new RuntimeException(
                'Ledger writes must happen inside Wallet::withUserLock(). '
                . 'Wrap the operation rather than calling credit()/debit() directly.'
            );
        }

        if (!isset(self::$lockedUsers[$userId])) {
            throw new RuntimeException(
                "Ledger write for user {$userId} without holding that user's row lock. "
                . 'Open the transaction with Wallet::withUserLock($userId, ...).'
            );
        }

        Database::run(
            'INSERT INTO wallet_entries
                (user_id, amount_minor, currency, type, reference_type, reference_id, memo, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $userId,
                $signedAmount,
                Config::string('ledger.currency', 'USD'),
                $type,
                $referenceType,
                $referenceId,
                $memo === '' ? null : mb_substr($memo, 0, 200),
                $createdBy,
            ]
        );

        $entryId = Database::lastInsertId();

        // Keep the display cache in step. If this fails the cache is stale,
        // which is detectable (last_entry_id falls behind) and repaired by
        // the nightly reconcile - it never affects a money decision,
        // because those read SUM().
        self::touchCache($userId);

        // Requirement: every ledger write is logged.
        Logger::audit(
            'ledger.write',
            sprintf('%s %s%d minor units', $type, $signedAmount >= 0 ? '+' : '', $signedAmount),
            $createdBy,
            'wallet_entry',
            $entryId,
            [
                'user_id'        => $userId,
                'amount_minor'   => $signedAmount,
                'type'           => $type,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]
        );

        return $entryId;
    }

    /**
     * Paged statement rows for the account wallet page.
     *
     * @return list<array<string,mixed>>
     */
    public static function statement(int $userId, int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        // LIMIT/OFFSET are integers cast in PHP and interpolated, because
        // MySQL will not accept a placeholder for them with emulation off.
        // They are never user strings - only clamped ints - so this is not
        // an injection path.
        return Database::all(
            "SELECT id, amount_minor, currency, type, reference_type, reference_id, memo, created_at
               FROM wallet_entries
              WHERE user_id = ?
              ORDER BY id DESC
              LIMIT {$limit} OFFSET {$offset}",
            [$userId]
        );
    }

    public static function statementCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM wallet_entries WHERE user_id = ?',
            [$userId],
            0
        );
    }

    /** Total ever credited / debited, for the wallet page summary. */
    public static function totals(int $userId): array
    {
        $row = Database::first(
            'SELECT
                CAST(COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) AS SIGNED) AS credits,
                CAST(COALESCE(SUM(CASE WHEN amount_minor < 0 THEN -amount_minor ELSE 0 END), 0) AS SIGNED) AS debits,
                COUNT(*) AS entries
               FROM wallet_entries
              WHERE user_id = ?',
            [$userId]
        );

        return [
            'credits' => (int) ($row['credits'] ?? 0),
            'debits'  => (int) ($row['debits'] ?? 0),
            'entries' => (int) ($row['entries'] ?? 0),
        ];
    }

    //-----------------------------------------------------------------
    // Balance cache.
    //
    // Used ONLY for bulk display - the admin user list would otherwise run
    // one SUM per row. Any single-user money decision calls balance().
    //-----------------------------------------------------------------

    /**
     * Cached balances for a set of users, for list views.
     *
     * @param list<int> $userIds
     * @return array<int,array{balance_minor:int,stale:bool}>
     */
    public static function cachedBalances(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        // The LEFT JOIN against MAX(id) is what makes staleness visible:
        // if the newest ledger entry is ahead of the cached one, the row is
        // flagged rather than trusted.
        $rows = Database::all(
            "SELECT u.id AS user_id,
                    c.balance_minor,
                    c.last_entry_id,
                    CAST(COALESCE(MAX(e.id), 0) AS SIGNED) AS newest_entry_id
               FROM users u
               LEFT JOIN wallet_balance_cache c ON c.user_id = u.id
               LEFT JOIN wallet_entries e ON e.user_id = u.id
              WHERE u.id IN ({$placeholders})
              GROUP BY u.id, c.balance_minor, c.last_entry_id",
            $userIds
        );

        $out = [];
        foreach ($rows as $row) {
            $cached = $row['balance_minor'];
            $stale = $cached === null
                || (int) $row['last_entry_id'] !== (int) $row['newest_entry_id'];

            $out[(int) $row['user_id']] = [
                // A stale entry is not displayed - it is recomputed. Showing
                // a number we know is wrong is worse than a slower page.
                'balance_minor' => $stale
                    ? self::balance((int) $row['user_id'])
                    : (int) $cached,
                'stale'         => $stale,
            ];
        }

        return $out;
    }

    /** Recompute and store one user's cached balance. */
    public static function rebuildCache(int $userId): int
    {
        $row = Database::first(
            'SELECT CAST(COALESCE(SUM(amount_minor), 0) AS SIGNED) AS balance_minor,
                    CAST(COALESCE(MAX(id), 0) AS SIGNED)           AS last_entry_id,
                    COUNT(*)                                       AS entry_count
               FROM wallet_entries
              WHERE user_id = ?',
            [$userId]
        );

        $balance = (int) ($row['balance_minor'] ?? 0);

        Database::run(
            'INSERT INTO wallet_balance_cache (user_id, balance_minor, last_entry_id, entry_count, rebuilt_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                balance_minor = VALUES(balance_minor),
                last_entry_id = VALUES(last_entry_id),
                entry_count   = VALUES(entry_count),
                rebuilt_at    = VALUES(rebuilt_at)',
            [$userId, $balance, (int) ($row['last_entry_id'] ?? 0), (int) ($row['entry_count'] ?? 0)]
        );

        return $balance;
    }

    private static function touchCache(int $userId): void
    {
        try {
            self::rebuildCache($userId);
        } catch (\Throwable $e) {
            Logger::write('wallet.cache_update_failed', $e->getMessage(), ['user_id' => $userId]);
        }
    }

    /**
     * Nightly reconciliation: compare every cached balance against the
     * ledger and report drift. Also flags negative balances, which should
     * be impossible and therefore matter more than drift if they appear.
     *
     * @return array{checked:int,drift:list<array<string,mixed>>,negative:list<array<string,mixed>>}
     */
    public static function reconcile(bool $repair = true): array
    {
        $rows = Database::all(
            'SELECT u.id AS user_id,
                    CAST(COALESCE(SUM(e.amount_minor), 0) AS SIGNED) AS ledger_balance,
                    CAST(COALESCE(MAX(e.id), 0) AS SIGNED)           AS newest_entry_id,
                    c.balance_minor                                  AS cached_balance,
                    c.last_entry_id                                  AS cached_entry_id
               FROM users u
               LEFT JOIN wallet_entries e ON e.user_id = u.id
               LEFT JOIN wallet_balance_cache c ON c.user_id = u.id
              GROUP BY u.id, c.balance_minor, c.last_entry_id'
        );

        $drift = [];
        $negative = [];

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $ledger = (int) $row['ledger_balance'];
            $cached = $row['cached_balance'] === null ? null : (int) $row['cached_balance'];

            if ($ledger < 0) {
                $negative[] = ['user_id' => $userId, 'balance_minor' => $ledger];
                Logger::audit(
                    'ledger.negative_balance',
                    "User {$userId} has a negative ledger balance of {$ledger} minor units",
                    null,
                    'user',
                    $userId,
                    ['balance_minor' => $ledger]
                );
            }

            if ($cached !== null && $cached === $ledger
                && (int) $row['cached_entry_id'] === (int) $row['newest_entry_id']) {
                continue;
            }

            if ($cached !== null && $cached !== $ledger) {
                $drift[] = [
                    'user_id'        => $userId,
                    'cached_balance' => $cached,
                    'ledger_balance' => $ledger,
                    'delta'          => $cached - $ledger,
                ];

                Logger::audit(
                    'ledger.cache_drift',
                    sprintf('Cached balance for user %d was %d, ledger says %d', $userId, $cached, $ledger),
                    null,
                    'user',
                    $userId,
                    ['cached' => $cached, 'ledger' => $ledger]
                );
            }

            if ($repair) {
                self::rebuildCache($userId);
            }
        }

        return ['checked' => count($rows), 'drift' => $drift, 'negative' => $negative];
    }
}
