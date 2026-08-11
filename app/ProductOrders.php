<?php

declare(strict_types=1);

namespace App;

use App\Lib\Fmt;
use App\Lib\Logger;
use App\Lib\Request;
use RuntimeException;

/**
 * Digital-product purchases and their download-access grant.
 *
 * This is the instant-delivery counterpart to Orders::purchase(): there
 * is no transfer queue, no payout address, and nothing on-chain. Paying
 * and granting access happen in the same locked transaction, so a
 * successful purchase always has something to download.
 *
 * The buyer-vs-buyer race this closes is the same shape as
 * Orders::purchase() - Wallet::withUserLock() serialises a user against
 * themselves - but there is no item-row race to close here, because a
 * digital product has unlimited supply. Nothing is "sold out".
 */
final class ProductOrders
{
    private const DOWNLOAD_TTL_SECONDS = 300;

    /**
     * Buy a product with store balance.
     *
     * $isMember is supplied by the caller (read from the session user a
     * moment earlier) rather than re-queried here, the same way
     * Orders::purchase() takes an already-validated payout address - the
     * lock protects the balance, not the membership flag, which does not
     * change concurrently with a purchase.
     *
     * @return array{order_id:int,balance_before:int,balance_after:int,price_minor:int}
     * @throws RuntimeException with a message safe to show the buyer.
     */
    public static function purchase(int $userId, int $productId, bool $isMember): array
    {
        return Wallet::withUserLock($userId, static function (int $balanceBefore) use ($userId, $productId, $isMember): array {
            $product = Database::first(
                'SELECT p.id, p.name, p.status, p.price_minor, p.member_price_minor, p.deliverable_path,
                        c.is_members_only AS category_is_members_only
                   FROM products p
                   LEFT JOIN product_categories c ON c.id = p.category_id
                  WHERE p.id = ? FOR UPDATE',
                [$productId]
            );

            if ($product === null) {
                throw new RuntimeException('That product no longer exists.');
            }

            if ($product['status'] !== Product::STATUS_LISTED) {
                throw new RuntimeException('That product is not currently for sale.');
            }

            if ((bool) $product['category_is_members_only'] && !$isMember) {
                throw new RuntimeException('That collection is members-only. Join Billions Membership to buy it.');
            }

            if ($product['deliverable_path'] === null) {
                throw new RuntimeException('This product has no deliverable file attached yet. Contact support.');
            }

            $price = Product::effectivePriceMinor($product, $isMember);

            if ($balanceBefore < $price) {
                $shortfall = $price - $balanceBefore;

                throw new RuntimeException(sprintf(
                    'Your balance is %s and this item is %s. Top up %s to complete the purchase.',
                    Fmt::money($balanceBefore),
                    Fmt::money($price),
                    Fmt::money($shortfall)
                ));
            }

            Database::run(
                'INSERT INTO product_orders (user_id, product_id, price_minor, currency, was_member_price, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [
                    $userId,
                    $productId,
                    $price,
                    \App\Lib\Config::string('ledger.currency', 'USD'),
                    ($isMember && $product['member_price_minor'] !== null) ? 1 : 0,
                    'paid',
                ]
            );

            $orderId = Database::lastInsertId();

            Wallet::debit(
                $userId,
                $price,
                Wallet::TYPE_PURCHASE,
                'product_order',
                $orderId,
                sprintf('Purchase: %s', mb_substr((string) $product['name'], 0, 80))
            );

            Logger::audit(
                'product_order.created',
                sprintf('Bought %s for %s', mb_substr((string) $product['name'], 0, 60), Fmt::money($price)),
                $userId,
                'product_order',
                $orderId,
                ['product_id' => $productId, 'price_minor' => $price]
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
     * Refund a product order. Unlike Orders::refund() there is no item to
     * relist - a digital good is never "out of stock" - so this only
     * reverses the ledger entry and marks the order refunded. Existing
     * download tokens are not revoked retroactively; the file has
     * already been in the buyer's hands since purchase, and revoking a
     * spent, single-use token achieves nothing.
     */
    public static function refund(int $orderId, int $adminUserId, string $reason): void
    {
        $order = Database::first('SELECT * FROM product_orders WHERE id = ?', [$orderId]);

        if ($order === null) {
            throw new RuntimeException('Order not found.');
        }

        if ($order['status'] === 'refunded') {
            throw new RuntimeException('That order has already been refunded.');
        }

        $userId = (int) $order['user_id'];
        $price = (int) $order['price_minor'];

        Wallet::withUserLock($userId, static function () use ($orderId, $userId, $price, $adminUserId, $reason): void {
            $fresh = Database::first('SELECT status FROM product_orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($fresh === null || $fresh['status'] === 'refunded') {
                return;
            }

            Wallet::credit(
                $userId,
                $price,
                Wallet::TYPE_REFUND,
                'product_order',
                $orderId,
                'Refund: order #' . $orderId,
                $adminUserId
            );

            Database::run("UPDATE product_orders SET status = 'refunded' WHERE id = ?", [$orderId]);
        });

        Logger::audit(
            'product_order.refunded',
            sprintf('Refunded %s for product order #%d: %s', Fmt::money($price), $orderId, mb_substr($reason, 0, 120)),
            $adminUserId,
            'product_order',
            $orderId,
            ['user_id' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $orderId): ?array
    {
        return Database::first(
            'SELECT o.*, p.name AS product_name, p.slug AS product_slug, p.preview_path, p.inscription_id
               FROM product_orders o
               JOIN products p ON p.id = o.product_id
              WHERE o.id = ?',
            [$orderId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::all(
            "SELECT o.*, p.name AS product_name, p.slug AS product_slug, p.preview_path
               FROM product_orders o
               JOIN products p ON p.id = o.product_id
              WHERE o.user_id = ?
              ORDER BY o.id DESC
              LIMIT {$limit}",
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
            "SELECT o.*, p.name AS product_name, u.email AS buyer_email
               FROM product_orders o
               JOIN products p ON p.id = o.product_id
               JOIN users u ON u.id = o.user_id
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
                    CAST(COALESCE(SUM(CASE WHEN status <> 'refunded' THEN price_minor ELSE 0 END), 0) AS SIGNED) AS revenue_minor
               FROM product_orders"
        );

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'revenue_minor' => (int) ($row['revenue_minor'] ?? 0),
        ];
    }

    //-----------------------------------------------------------------
    // Download access
    //-----------------------------------------------------------------

    /**
     * Mint a fresh, single-use download link for a completed order the
     * caller has already confirmed belongs to this user.
     *
     * Returns the plaintext token for the redirect URL; only its hash is
     * stored, matching Auth::issueToken()'s reasoning for verification
     * links.
     */
    public static function issueDownloadToken(int $productOrderId, int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        Database::run(
            'INSERT INTO download_tokens (product_order_id, user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, (UTC_TIMESTAMP() + INTERVAL ? SECOND), UTC_TIMESTAMP())',
            [$productOrderId, $userId, hash('sha256', $token), self::DOWNLOAD_TTL_SECONDS]
        );

        return $token;
    }

    /**
     * Redeem a download token: validated, single-use, and logged in the
     * same statement that consumes it.
     *
     * @return array{product_order_id:int,user_id:int}|null
     */
    public static function consumeDownloadToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return Database::transaction(static function () use ($token): ?array {
            $row = Database::first(
                'SELECT id, product_order_id, user_id FROM download_tokens
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                  FOR UPDATE',
                [hash('sha256', $token)]
            );

            if ($row === null) {
                return null;
            }

            Database::run(
                'UPDATE download_tokens SET used_at = UTC_TIMESTAMP(), used_ip = ? WHERE id = ?',
                [@inet_pton(Request::clientIp()) ?: null, (int) $row['id']]
            );

            return [
                'product_order_id' => (int) $row['product_order_id'],
                'user_id'           => (int) $row['user_id'],
            ];
        });
    }

    /** Whether $userId owns a paid order for $productId - the download gate. */
    public static function ownsProduct(int $userId, int $productId): bool
    {
        return Database::first(
            "SELECT id FROM product_orders WHERE user_id = ? AND product_id = ? AND status = 'paid' LIMIT 1",
            [$userId, $productId]
        ) !== null;
    }
}
