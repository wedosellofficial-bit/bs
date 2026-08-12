<?php

declare(strict_types=1);

namespace App;

use App\Lib\Fmt;
use App\Lib\Logger;
use RuntimeException;

/**
 * The purchase transaction.
 *
 * Everything here exists to make one sequence atomic:
 *
 *   lock the buyer -> read their balance -> lock the item -> check it is
 *   still for sale -> debit -> create the order -> queue the transfer ->
 *   mark the item sold
 *
 * Two races have to be closed, and they are different races:
 *
 *  - Two purchases by the SAME user with enough balance for one. Both read
 *    the old balance, both pass the affordability check, and the account
 *    goes negative. Closed by Wallet::withUserLock(), which serialises on
 *    the user row.
 *
 *  - Two purchases by DIFFERENT users of the SAME item. Neither user lock
 *    helps, because they are different rows. Closed by locking the nft row
 *    FOR UPDATE inside the transaction: the second transaction blocks on
 *    that lock, and once it can proceed it sees status <> 'listed' and
 *    stops. There is deliberately no unique key on orders.nft_id backing
 *    this up - see migration 006 - because that would also forbid ever
 *    reselling an item after a refund, which is a normal thing to do,
 *    not a race.
 *
 * Lock ordering is always user-then-item. If some future code path locks
 * them the other way round, two concurrent purchases can deadlock; keeping
 * every path in this class is what makes that easy to guarantee.
 */
final class Orders
{
    /**
     * Buy an item with store balance.
     *
     * @return array{order_id:int,balance_before:int,balance_after:int,price_minor:int}
     * @throws RuntimeException with a message safe to show the buyer.
     */
    public static function purchase(int $userId, int $nftId, string $payoutAddress): array
    {
        $validation = Ordinals::validatePayoutAddress($payoutAddress);
        if (!$validation['ok']) {
            throw new RuntimeException($validation['error']);
        }

        $address = $validation['normalized'];

        return Wallet::withUserLock($userId, static function (int $balanceBefore) use ($userId, $nftId, $address): array {
            // Activation gate: a pending account cannot check out. Read
            // fresh, inside the lock, rather than trusting a value the
            // controller fetched a moment earlier.
            if (AccountActivation::requiredToPurchase()) {
                $buyer = Database::first('SELECT account_status FROM users WHERE id = ?', [$userId]);
                if (!AccountActivation::isActive($buyer)) {
                    throw new RuntimeException(AccountActivation::activationPrompt($balanceBefore));
                }
            }

            // Lock the item. Any concurrent purchase of the same item now
            // waits here, and when it proceeds it will see status = 'sold'.
            $nft = Database::first(
                'SELECT id, name, token_id, price_minor, status FROM nfts WHERE id = ? FOR UPDATE',
                [$nftId]
            );

            if ($nft === null) {
                throw new RuntimeException('That item no longer exists.');
            }

            if ($nft['status'] !== Nft::STATUS_LISTED) {
                throw new RuntimeException('That item has just been sold. Nothing has been charged to your balance.');
            }

            $price = (int) $nft['price_minor'];

            if ($balanceBefore < $price) {
                $shortfall = $price - $balanceBefore;

                throw new RuntimeException(sprintf(
                    'Your balance is %s and this item is %s. Top up %s to complete the purchase.',
                    Fmt::money($balanceBefore),
                    Fmt::money($price),
                    Fmt::money($shortfall)
                ));
            }

            // The order is inserted before the debit so that the ledger
            // entry can reference a real order id. Both are in the same
            // transaction, so neither exists without the other.
            Database::run(
                'INSERT INTO orders (user_id, nft_id, price_minor, currency, status, buyer_wallet_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [
                    $userId,
                    $nftId,
                    $price,
                    \App\Lib\Config::string('ledger.currency', 'USD'),
                    'paid',
                    $address,
                ]
            );

            $orderId = Database::lastInsertId();

            Wallet::debit(
                $userId,
                $price,
                Wallet::TYPE_PURCHASE,
                'order',
                $orderId,
                sprintf('Purchase: %s', mb_substr((string) $nft['name'], 0, 80))
            );

            Database::run(
                "UPDATE nfts SET status = 'sold', updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$nftId]
            );

            Nft::enqueueTransfer($orderId);

            Database::run("UPDATE orders SET status = 'queued' WHERE id = ?", [$orderId]);

            Logger::audit(
                'order.created',
                sprintf('Bought %s for %s', mb_substr((string) $nft['name'], 0, 60), Fmt::money($price)),
                $userId,
                'order',
                $orderId,
                [
                    'nft_id'      => $nftId,
                    'token_id'    => (string) $nft['token_id'],
                    'price_minor' => $price,
                    'payout'      => $address,
                ]
            );

            return [
                'order_id'       => $orderId,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceBefore - $price,
                'price_minor'    => $price,
            ];
        });
    }

    /**
     * Refund an order and return the item to the storefront.
     *
     * The refund is a new positive ledger entry, never an edit or deletion
     * of the original debit - the statement must show both the charge and
     * its reversal.
     */
    public static function refund(int $orderId, int $adminUserId, string $reason, bool $relist = true): void
    {
        $order = Database::first('SELECT * FROM orders WHERE id = ?', [$orderId]);

        if ($order === null) {
            throw new RuntimeException('Order not found.');
        }

        if ($order['status'] === 'refunded') {
            throw new RuntimeException('That order has already been refunded.');
        }

        if ($order['status'] === 'complete') {
            throw new RuntimeException(
                'That order is already complete - the inscription has been transferred. '
                . 'Refunding it would give back the money and the item. Use an adjustment if a partial credit is intended.'
            );
        }

        $userId = (int) $order['user_id'];
        $price = (int) $order['price_minor'];
        $nftId = (int) $order['nft_id'];

        Wallet::withUserLock($userId, static function () use (
            $orderId,
            $userId,
            $price,
            $nftId,
            $adminUserId,
            $reason,
            $relist
        ): void {
            // Re-read under the lock: two admins hitting refund at once must
            // not both write a credit. The unique key on
            // (type, reference_type, reference_id) is the final backstop.
            $fresh = Database::first('SELECT status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($fresh === null || $fresh['status'] === 'refunded') {
                return;
            }

            Wallet::credit(
                $userId,
                $price,
                Wallet::TYPE_REFUND,
                'order',
                $orderId,
                'Refund: order #' . $orderId,
                $adminUserId
            );

            Database::run("UPDATE orders SET status = 'refunded' WHERE id = ?", [$orderId]);
            Database::run(
                "UPDATE transfer_queue SET status = 'failed', last_error = ?, updated_at = UTC_TIMESTAMP()
                  WHERE order_id = ? AND status <> 'complete'",
                ['Refunded: ' . mb_substr($reason, 0, 400), $orderId]
            );

            if ($relist) {
                Database::run(
                    "UPDATE nfts SET status = 'listed', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'sold'",
                    [$nftId]
                );

                // The refunded order row stays exactly where it is - it is
                // the record of what happened, and orders.nft_id carries no
                // unique constraint that a leftover row could violate (see
                // migration 006). A future purchase of this same nft_id
                // simply becomes a second, later order row; only the
                // now-irrelevant transfer_queue entry needs clearing.
                Database::run('DELETE FROM transfer_queue WHERE order_id = ?', [$orderId]);
            }
        });

        Logger::audit(
            'order.refunded',
            sprintf('Refunded %s for order #%d: %s', Fmt::money($price), $orderId, mb_substr($reason, 0, 120)),
            $adminUserId,
            'order',
            $orderId,
            ['user_id' => $userId, 'relisted' => $relist]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $orderId): ?array
    {
        return Database::first(
            'SELECT o.*, n.name AS nft_name, n.token_id, n.inscription_number, n.preview_path, n.image_path,
                    n.chain, q.status AS queue_status, q.last_error
               FROM orders o
               JOIN nfts n ON n.id = o.nft_id
               LEFT JOIN transfer_queue q ON q.order_id = o.id
              WHERE o.id = ?',
            [$orderId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::all(
            "SELECT o.*, n.name AS nft_name, n.token_id, n.inscription_number, n.preview_path, n.image_path,
                    q.status AS queue_status
               FROM orders o
               JOIN nfts n ON n.id = o.nft_id
               LEFT JOIN transfer_queue q ON q.order_id = o.id
              WHERE o.user_id = ?
              ORDER BY o.id DESC
              LIMIT {$limit}",
            [$userId]
        );
    }

    /**
     * Items the user owns: orders that reached a state where the
     * inscription is theirs, whether or not it has landed on-chain yet.
     *
     * @return list<array<string,mixed>>
     */
    public static function ownedByUser(int $userId): array
    {
        return Database::all(
            "SELECT o.id AS order_id, o.status AS order_status, o.tx_hash, o.buyer_wallet_address,
                    o.completed_at, o.created_at, o.price_minor,
                    n.id AS nft_id, n.name, n.token_id, n.inscription_number, n.preview_path, n.image_path,
                    c.name AS collection_name
               FROM orders o
               JOIN nfts n ON n.id = o.nft_id
               LEFT JOIN collections c ON c.id = n.collection_id
              WHERE o.user_id = ?
                AND o.status IN ('paid','queued','transferring','complete')
              ORDER BY o.id DESC",
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recent(string $status = 'all', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));

        $condition = '1 = 1';
        $params = [];

        if ($status !== 'all') {
            $condition = 'o.status = ?';
            $params[] = $status;
        }

        return Database::all(
            "SELECT o.*, n.name AS nft_name, n.token_id, u.email AS buyer_email, q.status AS queue_status
               FROM orders o
               JOIN nfts n ON n.id = o.nft_id
               JOIN users u ON u.id = o.user_id
               LEFT JOIN transfer_queue q ON q.order_id = o.id
              WHERE {$condition}
              ORDER BY o.id DESC
              LIMIT {$limit}",
            $params
        );
    }

    /** Dashboard totals. */
    public static function stats(): array
    {
        $row = Database::first(
            "SELECT COUNT(*) AS total,
                    CAST(COALESCE(SUM(CASE WHEN status <> 'refunded' THEN price_minor ELSE 0 END), 0) AS SIGNED) AS revenue_minor,
                    SUM(CASE WHEN status IN ('paid','queued','transferring') THEN 1 ELSE 0 END) AS open_orders,
                    SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) AS completed
               FROM orders"
        );

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'revenue_minor' => (int) ($row['revenue_minor'] ?? 0),
            'open_orders'   => (int) ($row['open_orders'] ?? 0),
            'completed'     => (int) ($row['completed'] ?? 0),
        ];
    }
}
