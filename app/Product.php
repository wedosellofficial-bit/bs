<?php

declare(strict_types=1);

namespace App;

/**
 * The digital-art product catalog: search, filtering, categories and
 * tags.
 *
 * This is a separate catalog from `nfts` (see Nft.php). A product has no
 * on-chain leg - see ProductOrders::purchase() - and its optional
 * inscription_id is display metadata only (Ordinals::isValidInscriptionId
 * checks its shape, nothing more).
 *
 * Every filter value is bound; only sort column/direction come from the
 * SORTS whitelist, matching the convention in Nft::search().
 */
final class Product
{
    public const STATUS_LISTED = 'listed';
    public const STATUS_HIDDEN = 'hidden';

    public const PER_PAGE = 24;

    private const SORTS = [
        'newest'     => ['p.created_at', 'DESC', 'Newest first'],
        'oldest'     => ['p.created_at', 'ASC', 'Oldest first'],
        'price_asc'  => ['p.price_minor', 'ASC', 'Price: low to high'],
        'price_desc' => ['p.price_minor', 'DESC', 'Price: high to low'],
        'name_asc'   => ['p.name', 'ASC', 'Name: A to Z'],
    ];

    /** @return array<string,string> sort key => label, for the UI. */
    public static function sortOptions(): array
    {
        return array_map(static fn (array $s): string => $s[2], self::SORTS);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{category:string,tag:string,min_price:?int,max_price:?int,q:string,sort:string,page:int}
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

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $sort = is_string($input['sort'] ?? null) ? $input['sort'] : 'newest';
        if (!array_key_exists($sort, self::SORTS)) {
            $sort = 'newest';
        }

        return [
            'category'  => is_string($input['category'] ?? null) ? trim($input['category']) : '',
            'tag'       => is_string($input['tag'] ?? null) ? trim($input['tag']) : '',
            'min_price' => $min,
            'max_price' => $max,
            'q'         => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 80) : '',
            'sort'      => $sort,
            'page'      => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /**
     * @param array<string,mixed> $filters Output of normalizeFilters().
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters, int $perPage = self::PER_PAGE): array
    {
        $filters = self::normalizeFilters($filters);
        [$where, $params] = self::buildWhere($filters);

        $total = (int) Database::scalar(
            "SELECT COUNT(DISTINCT p.id) FROM products p {$where}",
            $params,
            0
        );

        $perPage = max(1, min(96, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * $perPage;

        [$column, $direction] = self::SORTS[$filters['sort']];

        $items = Database::all(
            "SELECT DISTINCT p.id, p.slug, p.name, p.price_minor, p.status,
                    p.image_path, p.preview_path, p.inscription_id, p.created_at,
                    c.name AS category_name, c.slug AS category_slug
               FROM products p
               LEFT JOIN product_categories c ON c.id = p.category_id
               {$where}
              ORDER BY {$column} {$direction}, p.id DESC
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
        $clauses = ["p.status = 'listed'"];
        $params = [];

        if ($filters['category'] !== '') {
            $clauses[] = 'p.category_id = (SELECT id FROM product_categories WHERE slug = ? LIMIT 1)';
            $params[] = $filters['category'];
        }

        if ($filters['tag'] !== '') {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM product_tag_map ptm
                  JOIN product_tags t ON t.id = ptm.tag_id
                 WHERE ptm.product_id = p.id AND t.slug = ?
            )';
            $params[] = $filters['tag'];
        }

        if ($filters['min_price'] !== null) {
            $clauses[] = 'p.price_minor >= ?';
            $params[] = $filters['min_price'];
        }

        if ($filters['max_price'] !== null) {
            $clauses[] = 'p.price_minor <= ?';
            $params[] = $filters['max_price'];
        }

        if ($filters['q'] !== '') {
            $escaped = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $clauses[] = '(p.name LIKE ? OR EXISTS (
                SELECT 1 FROM product_tag_map ptm
                  JOIN product_tags t ON t.id = ptm.tag_id
                 WHERE ptm.product_id = p.id AND t.name LIKE ?
            ))';
            $params[] = $escaped;
            $params[] = $escaped;
        }

        return ['WHERE ' . implode("\n    AND ", $clauses), $params];
    }

    /** @return array{min:int,max:int} */
    public static function priceBounds(): array
    {
        $row = Database::first(
            "SELECT CAST(COALESCE(MIN(price_minor), 0) AS SIGNED) AS min_price,
                    CAST(COALESCE(MAX(price_minor), 0) AS SIGNED) AS max_price
               FROM products WHERE status = 'listed'"
        );

        return [
            'min' => (int) ($row['min_price'] ?? 0),
            'max' => (int) ($row['max_price'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
               FROM products p
               LEFT JOIN product_categories c ON c.id = p.category_id
              WHERE p.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Database::first(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
               FROM products p
               LEFT JOIN product_categories c ON c.id = p.category_id
              WHERE p.slug = ?',
            [$slug]
        );
    }

    /** @return list<array<string,mixed>> Tags attached to a product. */
    public static function tagsFor(int $productId): array
    {
        return Database::all(
            'SELECT t.id, t.slug, t.name
               FROM product_tag_map ptm
               JOIN product_tags t ON t.id = ptm.tag_id
              WHERE ptm.product_id = ?
              ORDER BY t.name ASC',
            [$productId]
        );
    }

    /**
     * Replace a product's tags with the given tag names, creating any
     * that do not exist yet. Called after every admin create/update so
     * the tag list is always in sync with the free-text field.
     *
     * @param list<string> $tagNames
     */
    public static function syncTags(int $productId, array $tagNames): void
    {
        Database::transaction(static function () use ($productId, $tagNames): void {
            Database::run('DELETE FROM product_tag_map WHERE product_id = ?', [$productId]);

            $seen = [];
            foreach ($tagNames as $raw) {
                $name = mb_substr(trim($raw), 0, 80);
                if ($name === '') {
                    continue;
                }

                $slug = self::slugify($name);
                if ($slug === '' || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;

                Database::run(
                    'INSERT INTO product_tags (slug, name) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE name = VALUES(name)',
                    [$slug, $name]
                );

                $tagId = (int) Database::scalar('SELECT id FROM product_tags WHERE slug = ?', [$slug], 0);

                Database::run(
                    'INSERT IGNORE INTO product_tag_map (product_id, tag_id) VALUES (?, ?)',
                    [$productId, $tagId]
                );
            }
        });
    }

    /** @return list<array<string,mixed>> All tags with a usage count, for filters and the admin picker. */
    public static function allTags(): array
    {
        return Database::all(
            'SELECT t.id, t.slug, t.name, COUNT(ptm.product_id) AS item_count
               FROM product_tags t
               LEFT JOIN product_tag_map ptm ON ptm.tag_id = t.id
              GROUP BY t.id
              ORDER BY t.name ASC'
        );
    }

    /** @return list<array<string,mixed>> */
    public static function categories(bool $visibleOnly = true): array
    {
        $sql = 'SELECT c.*, COUNT(p.id) AS item_count
                  FROM product_categories c
                  LEFT JOIN products p ON p.category_id = c.id AND p.status = \'listed\'';

        if ($visibleOnly) {
            $sql .= ' WHERE c.is_visible = 1';
        }

        $sql .= ' GROUP BY c.id ORDER BY c.sort_order ASC, c.name ASC';

        return Database::all($sql);
    }

    public static function categoryBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM product_categories WHERE slug = ?', [$slug]);
    }

    /**
     * Turn a name into a URL slug. Shared by product, category and tag
     * creation so all three produce the same shape of identifier.
     */
    public static function slugify(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return mb_substr($slug, 0, 140);
    }

    /**
     * A slug that does not collide with an existing row. Tried plain
     * first, then with a numeric suffix - the common case (a unique
     * name) never pays for a suffix it does not need.
     */
    public static function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug = self::slugify($base);
        if ($slug === '') {
            $slug = 'item';
        }

        $candidate = $slug;
        $suffix = 2;

        while (self::slugTaken($candidate, $excludeId)) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private static function slugTaken(string $slug, ?int $excludeId): bool
    {
        if ($excludeId !== null) {
            return Database::first(
                'SELECT id FROM products WHERE slug = ? AND id <> ?',
                [$slug, $excludeId]
            ) !== null;
        }

        return Database::first('SELECT id FROM products WHERE slug = ?', [$slug]) !== null;
    }

    /** Same idea as uniqueSlug(), scoped to product_categories instead of products. */
    public static function uniqueSlugForCategory(string $base): string
    {
        $slug = self::slugify($base);
        if ($slug === '') {
            $slug = 'category';
        }

        $candidate = $slug;
        $suffix = 2;

        while (Database::first('SELECT id FROM product_categories WHERE slug = ?', [$candidate]) !== null) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
