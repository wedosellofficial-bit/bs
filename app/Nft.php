<?php

declare(strict_types=1);

namespace App;

use App\Lib\Logger;
use InvalidArgumentException;

/**
 * Catalog, filtering, and the transfer queue.
 *
 * Filter semantics
 * ----------------
 * Traits combine as OR within a trait type and AND across trait types.
 * Picking "Background: Gold" and "Background: Ember" means *either*
 * background; adding "Eyes: Laser" narrows that to items which are one of
 * those backgrounds *and* have laser eyes. This is what every marketplace
 * does and what users expect - the alternative (AND everywhere) returns
 * an empty grid almost immediately and reads as broken.
 *
 * Every filter value is bound. Only sort direction and column come from a
 * whitelist, and nothing user-supplied is ever interpolated into SQL.
 */
final class Nft
{
    public const STATUS_LISTED = 'listed';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SOLD = 'sold';
    public const STATUS_TRANSFERRED = 'transferred';

    public const PER_PAGE = 24;

    /**
     * Sort options. The map is the whitelist: a `sort` parameter that is
     * not a key here falls back to the default rather than reaching SQL.
     */
    private const SORTS = [
        'newest'     => ['n.created_at', 'DESC', 'Newest first'],
        'oldest'     => ['n.created_at', 'ASC', 'Oldest first'],
        'price_asc'  => ['n.price_minor', 'ASC', 'Price: low to high'],
        'price_desc' => ['n.price_minor', 'DESC', 'Price: high to low'],
        'rarity'     => ['n.rarity_score', 'DESC', 'Rarest first'],
        'number'     => ['n.inscription_number', 'ASC', 'Inscription number'],
    ];

    /** @return array<string,string> sort key => label, for the UI. */
    public static function sortOptions(): array
    {
        return array_map(static fn (array $s): string => $s[2], self::SORTS);
    }

    /**
     * Normalise raw request input into a filter set.
     *
     * Doing this in one place means the AJAX endpoint, the server-rendered
     * page, and the URL builder all agree on what a filter is - which is
     * what makes the shareable URL and the back button work.
     *
     * @param array<string,mixed> $input
     * @return array{
     *   collection:string, min_price:?int, max_price:?int,
     *   traits:array<string,list<string>>, status:string, sort:string, page:int, q:string
     * }
     */
    public static function normalizeFilters(array $input): array
    {
        $toInt = static function (mixed $v): ?int {
            if (!is_scalar($v) || trim((string) $v) === '' || !is_numeric((string) $v)) {
                return null;
            }

            return max(0, (int) $v);
        };

        $min = $toInt($input['min_price'] ?? null);
        $max = $toInt($input['max_price'] ?? null);

        // A reversed range is a slider dragged past itself, not an error
        // worth showing - swap it and carry on.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $status = is_string($input['status'] ?? null) ? $input['status'] : 'listed';
        if (!in_array($status, ['listed', 'sold', 'all'], true)) {
            $status = 'listed';
        }

        $sort = is_string($input['sort'] ?? null) ? $input['sort'] : 'newest';
        if (!array_key_exists($sort, self::SORTS)) {
            $sort = 'newest';
        }

        return [
            'collection' => is_string($input['collection'] ?? null) ? trim($input['collection']) : '',
            'min_price'  => $min,
            'max_price'  => $max,
            'traits'     => self::parseTraits($input['traits'] ?? null),
            'status'     => $status,
            'sort'       => $sort,
            'page'       => max(1, (int) ($input['page'] ?? 1)),
            'q'          => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 80) : '',
        ];
    }

    /**
     * Traits arrive as `Trait Type:Value` pairs, either as a repeated
     * query parameter or comma-joined. Grouped by trait type here.
     *
     * Accepts its own output as input as well, so normalizeFilters() is
     * idempotent. That matters because search() normalises defensively
     * and callers normalise before calling it - without this, the second
     * pass would see the grouped array, find array values where it
     * expected "Type:Value" strings, and silently drop every trait
     * filter.
     *
     * @return array<string,list<string>>
     */
    private static function parseTraits(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $grouped = [];

        foreach ($raw as $key => $entry) {
            // Already-grouped form: "Background" => ["Void", "Ember"].
            if (is_string($key) && is_array($entry)) {
                foreach ($entry as $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }

                    $pair = self::validTraitPair($key, (string) $value);
                    if ($pair !== null) {
                        $grouped[$pair[0]][] = $pair[1];
                    }
                }

                continue;
            }

            // Flat form: "Background:Void".
            if (!is_scalar($entry)) {
                continue;
            }

            $flat = trim((string) $entry);
            if ($flat === '' || !str_contains($flat, ':')) {
                continue;
            }

            [$type, $value] = explode(':', $flat, 2);

            $pair = self::validTraitPair($type, $value);
            if ($pair !== null) {
                $grouped[$pair[0]][] = $pair[1];
            }
        }

        // Cap the number of trait groups so a crafted URL cannot build a
        // query with 200 EXISTS clauses.
        $grouped = array_slice($grouped, 0, 12, true);

        foreach ($grouped as $type => $values) {
            $grouped[$type] = array_values(array_slice(array_unique($values), 0, 30));
        }

        return $grouped;
    }

    /**
     * Trim and bounds-check one trait type/value pair.
     *
     * @return array{0:string,1:string}|null null when either side is
     *         empty or longer than its column allows.
     */
    private static function validTraitPair(string $type, string $value): ?array
    {
        $type = trim($type);
        $value = trim($value);

        if ($type === '' || $value === '' || mb_strlen($type) > 60 || mb_strlen($value) > 120) {
            return null;
        }

        return [$type, $value];
    }

    /**
     * Run a filtered catalog query.
     *
     * @param array<string,mixed> $filters Output of normalizeFilters().
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters, int $perPage = self::PER_PAGE): array
    {
        $filters = self::normalizeFilters($filters);
        [$where, $params] = self::buildWhere($filters);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM nfts n ' . $where,
            $params,
            0
        );

        $perPage = max(1, min(96, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * $perPage;

        [$column, $direction] = self::SORTS[$filters['sort']];

        // $column and $direction come from the SORTS constant, never from
        // input; $perPage and $offset are clamped integers. MySQL will not
        // take placeholders for ORDER BY or LIMIT with emulation off.
        $items = Database::all(
            "SELECT n.id, n.token_id, n.chain, n.inscription_number, n.name, n.price_minor,
                    n.status, n.rarity_score, n.preview_path, n.image_path, n.created_at,
                    c.name AS collection_name, c.slug AS collection_slug
               FROM nfts n
               LEFT JOIN collections c ON c.id = n.collection_id
               {$where}
              ORDER BY {$column} {$direction}, n.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        $clauses[] = match ($filters['status']) {
            'listed' => "n.status = 'listed'",
            'sold'   => "n.status IN ('sold','transferred')",
            // 'all' still hides `reserved` - those are inventory an admin
            // has deliberately pulled from the storefront.
            default  => "n.status IN ('listed','sold','transferred')",
        };

        if ($filters['collection'] !== '') {
            $clauses[] = 'n.collection_id = (SELECT id FROM collections WHERE slug = ? LIMIT 1)';
            $params[] = $filters['collection'];
        }

        if ($filters['min_price'] !== null) {
            $clauses[] = 'n.price_minor >= ?';
            $params[] = $filters['min_price'];
        }

        if ($filters['max_price'] !== null) {
            $clauses[] = 'n.price_minor <= ?';
            $params[] = $filters['max_price'];
        }

        if ($filters['q'] !== '') {
            $clauses[] = '(n.name LIKE ? OR n.token_id = ?)';
            // escape LIKE wildcards so a search for "100%" is a literal.
            $params[] = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params[] = $filters['q'];
        }

        // One EXISTS per trait type: AND across types, IN (...) for OR
        // within a type.
        foreach ($filters['traits'] as $type => $values) {
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $clauses[] = "EXISTS (
                SELECT 1 FROM nft_attributes a
                 WHERE a.nft_id = n.id AND a.trait_type = ? AND a.value IN ({$placeholders})
            )";
            $params[] = $type;
            foreach ($values as $value) {
                $params[] = $value;
            }
        }

        return ['WHERE ' . implode("\n    AND ", $clauses), $params];
    }

    /**
     * Facets for the filter rail: trait types, their values, and how many
     * items each would match.
     *
     * Counts are computed against the current status filter only, not the
     * full filter set. Recomputing every facet against every other facet
     * is a much heavier query, and the usual expectation is that
     * unselected options still show their standalone count.
     *
     * @return list<array{trait_type:string,values:list<array{value:string,count:int}>}>
     */
    public static function facets(string $status = 'listed', string $collectionSlug = ''): array
    {
        $params = [];
        $clauses = [];

        $clauses[] = match ($status) {
            'sold'  => "n.status IN ('sold','transferred')",
            'all'   => "n.status IN ('listed','sold','transferred')",
            default => "n.status = 'listed'",
        };

        if ($collectionSlug !== '') {
            $clauses[] = 'n.collection_id = (SELECT id FROM collections WHERE slug = ? LIMIT 1)';
            $params[] = $collectionSlug;
        }

        $rows = Database::all(
            'SELECT a.trait_type, a.value, COUNT(*) AS item_count
               FROM nft_attributes a
               JOIN nfts n ON n.id = a.nft_id
              WHERE ' . implode(' AND ', $clauses) . '
              GROUP BY a.trait_type, a.value
              ORDER BY a.trait_type ASC, item_count DESC, a.value ASC',
            $params
        );

        $grouped = [];
        foreach ($rows as $row) {
            $type = (string) $row['trait_type'];
            $grouped[$type][] = [
                'value' => (string) $row['value'],
                'count' => (int) $row['item_count'],
            ];
        }

        $out = [];
        foreach ($grouped as $type => $values) {
            $out[] = ['trait_type' => $type, 'values' => $values];
        }

        return $out;
    }

    /** @return array{min:int,max:int} Price bounds for the slider. */
    public static function priceBounds(string $status = 'listed'): array
    {
        $condition = match ($status) {
            'sold'  => "status IN ('sold','transferred')",
            'all'   => "status IN ('listed','sold','transferred')",
            default => "status = 'listed'",
        };

        $row = Database::first(
            "SELECT CAST(COALESCE(MIN(price_minor), 0) AS SIGNED) AS min_price,
                    CAST(COALESCE(MAX(price_minor), 0) AS SIGNED) AS max_price
               FROM nfts WHERE {$condition}"
        );

        return [
            'min' => (int) ($row['min_price'] ?? 0),
            'max' => (int) ($row['max_price'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function collections(bool $visibleOnly = true): array
    {
        $sql = 'SELECT c.*, COUNT(n.id) AS item_count,
                       SUM(CASE WHEN n.status = \'listed\' THEN 1 ELSE 0 END) AS listed_count
                  FROM collections c
                  LEFT JOIN nfts n ON n.collection_id = c.id';

        if ($visibleOnly) {
            $sql .= ' WHERE c.is_visible = 1';
        }

        $sql .= ' GROUP BY c.id ORDER BY c.sort_order ASC, c.name ASC';

        return Database::all($sql);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT n.*, c.name AS collection_name, c.slug AS collection_slug
               FROM nfts n
               LEFT JOIN collections c ON c.id = n.collection_id
              WHERE n.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findByToken(string $tokenId, string $chain = 'bitcoin-ordinals'): ?array
    {
        return Database::first(
            'SELECT * FROM nfts WHERE token_id = ? AND chain = ?',
            [$tokenId, $chain]
        );
    }

    /**
     * Decode the display attributes.
     *
     * @return list<array{trait_type:string,value:string}>
     */
    public static function attributes(array $nft): array
    {
        $raw = $nft['attributes'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $entry) {
            // Accept both [{trait_type,value}] and {"Trait": "Value"}.
            if (is_array($entry) && isset($entry['trait_type'], $entry['value'])) {
                $out[] = [
                    'trait_type' => (string) $entry['trait_type'],
                    'value'      => (string) $entry['value'],
                ];
            } elseif (is_scalar($entry) && is_string($key)) {
                $out[] = ['trait_type' => $key, 'value' => (string) $entry];
            }
        }

        return $out;
    }

    /**
     * Rebuild the normalised attribute rows for one item from its JSON.
     *
     * Called after any admin edit. The JSON stays the source of truth;
     * this table is a derived index (see migration 003).
     */
    public static function syncAttributes(int $nftId): void
    {
        $nft = Database::first('SELECT attributes FROM nfts WHERE id = ?', [$nftId]);
        if ($nft === null) {
            return;
        }

        Database::transaction(static function () use ($nftId, $nft): void {
            Database::run('DELETE FROM nft_attributes WHERE nft_id = ?', [$nftId]);

            foreach (self::attributes($nft) as $attribute) {
                $type = mb_substr($attribute['trait_type'], 0, 60);
                $value = mb_substr($attribute['value'], 0, 120);

                if ($type === '' || $value === '') {
                    continue;
                }

                // INSERT IGNORE: the unique key means a JSON blob listing the
                // same trait twice collapses to one row instead of failing
                // the whole sync.
                Database::run(
                    'INSERT IGNORE INTO nft_attributes (nft_id, trait_type, value) VALUES (?, ?, ?)',
                    [$nftId, $type, $value]
                );
            }
        });
    }

    /**
     * Recompute rarity across a collection.
     *
     * Score is the sum over traits of (collection size / trait frequency),
     * scaled to an integer - the standard "statistical rarity" measure. An
     * item with a trait only one other item has scores far above one whose
     * traits are all common.
     *
     * Stored rather than computed at query time so it can be an indexed
     * sort.
     */
    public static function recomputeRarity(?int $collectionId = null): int
    {
        $params = [];
        $scope = '';
        if ($collectionId !== null) {
            $scope = 'WHERE n.collection_id = ?';
            $params[] = $collectionId;
        }

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM nfts n {$scope}",
            $params,
            0
        );

        if ($total === 0) {
            return 0;
        }

        // Frequency of every (trait_type, value) within the scope.
        $frequencies = Database::all(
            "SELECT a.trait_type, a.value, COUNT(*) AS freq
               FROM nft_attributes a
               JOIN nfts n ON n.id = a.nft_id
               {$scope}
              GROUP BY a.trait_type, a.value",
            $params
        );

        $freqMap = [];
        foreach ($frequencies as $row) {
            $freqMap[$row['trait_type'] . "\0" . $row['value']] = max(1, (int) $row['freq']);
        }

        $rows = Database::all(
            "SELECT n.id, a.trait_type, a.value
               FROM nfts n
               LEFT JOIN nft_attributes a ON a.nft_id = n.id
               {$scope}",
            $params
        );

        $scores = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $scores[$id] ??= 0.0;

            if ($row['trait_type'] === null) {
                continue;
            }

            $freq = $freqMap[$row['trait_type'] . "\0" . $row['value']] ?? $total;
            $scores[$id] += $total / $freq;
        }

        $updated = 0;
        foreach ($scores as $id => $score) {
            Database::run(
                'UPDATE nfts SET rarity_score = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [(int) round($score * 100), $id]
            );
            $updated++;
        }

        return $updated;
    }

    //-----------------------------------------------------------------
    // Transfer queue
    //
    // Manual mode: no signing key exists in this codebase. An admin sends
    // the inscription from the project wallet and records the txid here.
    //-----------------------------------------------------------------

    /** How long an admin may hold a claim before the cron sweep releases it. */
    public const CLAIM_TIMEOUT_SECONDS = 1800;

    public static function enqueueTransfer(int $orderId): int
    {
        Database::run(
            "INSERT INTO transfer_queue (order_id, status, created_at)
             VALUES (?, 'pending', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()",
            [$orderId]
        );

        return Database::lastInsertId();
    }

    /**
     * The admin queue view.
     *
     * @return list<array<string,mixed>>
     */
    public static function transferQueue(string $status = 'open', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));

        $condition = match ($status) {
            'open'     => "q.status IN ('pending','claimed','sent')",
            'complete' => "q.status = 'complete'",
            'failed'   => "q.status = 'failed'",
            default    => '1 = 1',
        };

        return Database::all(
            "SELECT q.*, o.user_id, o.nft_id, o.buyer_wallet_address, o.price_minor, o.tx_hash,
                    o.status AS order_status, o.created_at AS ordered_at,
                    n.name AS nft_name, n.token_id, n.inscription_number,
                    u.email AS buyer_email,
                    admin.email AS claimed_by_email
               FROM transfer_queue q
               JOIN orders o ON o.id = q.order_id
               JOIN nfts n ON n.id = o.nft_id
               JOIN users u ON u.id = o.user_id
               LEFT JOIN users admin ON admin.id = q.claimed_by
              WHERE {$condition}
              ORDER BY FIELD(q.status, 'failed', 'pending', 'claimed', 'sent', 'complete'), q.created_at ASC
              LIMIT {$limit}"
        );
    }

    /**
     * Claim a queue item.
     *
     * The UPDATE is conditional on the row still being unclaimed, so two
     * admins clicking at once produce one winner and one "already claimed"
     * - rather than both being told they own it and both broadcasting.
     */
    public static function claimTransfer(int $queueId, int $adminUserId): bool
    {
        $stmt = Database::run(
            "UPDATE transfer_queue
                SET status = 'claimed', claimed_by = ?, claimed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ?
                AND (status = 'pending'
                     OR (status = 'claimed' AND claimed_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)))",
            [$adminUserId, $queueId, self::CLAIM_TIMEOUT_SECONDS]
        );

        $claimed = $stmt->rowCount() === 1;

        if ($claimed) {
            Database::run(
                "UPDATE orders o
                   JOIN transfer_queue q ON q.order_id = o.id
                    SET o.status = 'transferring'
                  WHERE q.id = ?",
                [$queueId]
            );

            Logger::audit('transfer.claimed', 'Transfer claimed for sending', $adminUserId, 'transfer_queue', $queueId);
        }

        return $claimed;
    }

    public static function releaseTransfer(int $queueId, int $adminUserId): void
    {
        Database::run(
            "UPDATE transfer_queue
                SET status = 'pending', claimed_by = NULL, claimed_at = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'claimed'",
            [$queueId]
        );

        Database::run(
            "UPDATE orders o JOIN transfer_queue q ON q.order_id = o.id
                SET o.status = 'queued'
              WHERE q.id = ? AND o.status = 'transferring'",
            [$queueId]
        );

        Logger::audit('transfer.released', 'Transfer claim released', $adminUserId, 'transfer_queue', $queueId);
    }

    /**
     * Record a broadcast transfer.
     *
     * @throws InvalidArgumentException when the txid is not a 64-hex hash.
     */
    public static function markTransferSent(int $queueId, int $adminUserId, string $txid): void
    {
        $txid = strtolower(trim($txid));

        if (!Ordinals::isValidTxid($txid)) {
            throw new InvalidArgumentException(
                'That is not a Bitcoin transaction id. Paste the 64-character hex hash from your wallet.'
            );
        }

        Database::transaction(static function () use ($queueId, $adminUserId, $txid): void {
            $row = Database::first(
                'SELECT q.id, q.order_id, o.nft_id FROM transfer_queue q
                   JOIN orders o ON o.id = q.order_id
                  WHERE q.id = ? FOR UPDATE',
                [$queueId]
            );

            if ($row === null) {
                throw new InvalidArgumentException('That queue item no longer exists.');
            }

            Database::run(
                "UPDATE transfer_queue
                    SET status = 'sent', attempts = attempts + 1, last_error = NULL, updated_at = UTC_TIMESTAMP()
                  WHERE id = ?",
                [$queueId]
            );

            Database::run(
                "UPDATE orders SET tx_hash = ?, status = 'transferring' WHERE id = ?",
                [$txid, (int) $row['order_id']]
            );

            Logger::audit(
                'transfer.sent',
                'Inscription transfer broadcast',
                $adminUserId,
                'order',
                (int) $row['order_id'],
                ['txid' => $txid, 'nft_id' => (int) $row['nft_id']]
            );
        });
    }

    /** Confirm a transfer: the order closes and the item becomes transferred. */
    public static function markTransferComplete(int $queueId, int $adminUserId): void
    {
        Database::transaction(static function () use ($queueId, $adminUserId): void {
            $row = Database::first(
                'SELECT q.order_id, o.nft_id FROM transfer_queue q
                   JOIN orders o ON o.id = q.order_id
                  WHERE q.id = ? FOR UPDATE',
                [$queueId]
            );

            if ($row === null) {
                throw new InvalidArgumentException('That queue item no longer exists.');
            }

            Database::run(
                "UPDATE transfer_queue SET status = 'complete', updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$queueId]
            );
            Database::run(
                "UPDATE orders SET status = 'complete', completed_at = UTC_TIMESTAMP() WHERE id = ?",
                [(int) $row['order_id']]
            );
            Database::run(
                "UPDATE nfts SET status = 'transferred', updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [(int) $row['nft_id']]
            );

            Logger::audit(
                'transfer.complete',
                'Transfer confirmed on-chain',
                $adminUserId,
                'order',
                (int) $row['order_id']
            );
        });
    }

    public static function markTransferFailed(int $queueId, int $adminUserId, string $reason): void
    {
        Database::transaction(static function () use ($queueId, $adminUserId, $reason): void {
            $row = Database::first('SELECT order_id FROM transfer_queue WHERE id = ? FOR UPDATE', [$queueId]);
            if ($row === null) {
                return;
            }

            Database::run(
                "UPDATE transfer_queue
                    SET status = 'failed', attempts = attempts + 1, last_error = ?, updated_at = UTC_TIMESTAMP()
                  WHERE id = ?",
                [mb_substr($reason, 0, 500), $queueId]
            );

            Database::run("UPDATE orders SET status = 'failed' WHERE id = ?", [(int) $row['order_id']]);

            Logger::audit(
                'transfer.failed',
                'Transfer marked failed: ' . mb_substr($reason, 0, 200),
                $adminUserId,
                'order',
                (int) $row['order_id']
            );
        });
    }

    /** Release claims an admin walked away from. Called by cron. */
    public static function releaseStaleClaims(): int
    {
        $stmt = Database::run(
            "UPDATE transfer_queue
                SET status = 'pending', claimed_by = NULL, claimed_at = NULL, updated_at = UTC_TIMESTAMP()
              WHERE status = 'claimed' AND claimed_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)",
            [self::CLAIM_TIMEOUT_SECONDS]
        );

        return $stmt->rowCount();
    }

    /** Counts for the admin dashboard. */
    public static function queueCounts(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM transfer_queue GROUP BY status');

        $out = ['pending' => 0, 'claimed' => 0, 'sent' => 0, 'complete' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }
}
