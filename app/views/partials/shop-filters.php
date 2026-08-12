<?php

declare(strict_types=1);

/**
 * Shared between the mobile <details> panel and the desktop rail on the
 * shop page, so the two cannot drift into showing different controls.
 *
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $tags
 * @var array{min:int,max:int} $priceBounds
 */

use App\Lib\Fmt;
?>
<div class="filter-group">
    <label class="label" for="filter-q">Search</label>
    <input type="search" id="filter-q" name="q" value="<?= Fmt::e($filters['q']) ?>"
           class="field" placeholder="Name or tag" autocomplete="off">
</div>

<?php if ($categories !== []): ?>
    <div class="filter-group">
        <label class="label" for="filter-category">Category</label>
        <select id="filter-category" name="category" class="field">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= Fmt::e((string) $category['slug']) ?>" <?= $filters['category'] === $category['slug'] ? 'selected' : '' ?>>
                    <?= Fmt::e((string) $category['name']) ?>
                    (<?= (int) $category['item_count'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>

<?php if ($tags !== []): ?>
    <div class="filter-group">
        <label class="label" for="filter-tag">Tag</label>
        <select id="filter-tag" name="tag" class="field">
            <option value="">Any tag</option>
            <?php foreach ($tags as $tag): ?>
                <option value="<?= Fmt::e((string) $tag['slug']) ?>" <?= $filters['tag'] === $tag['slug'] ? 'selected' : '' ?>>
                    <?= Fmt::e((string) $tag['name']) ?> (<?= (int) $tag['item_count'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>

<div class="filter-group">
    <span class="label">Price ($)</span>
    <div class="flex items-center gap-2">
        <input type="text" inputmode="decimal" name="min_price" placeholder="<?= Fmt::e(Fmt::money($priceBounds['min'], false)) ?>"
               value="<?= Fmt::e($filters['min_price'] !== null ? Fmt::money($filters['min_price'], false) : '') ?>" class="field">
        <span class="text-ink-600">&ndash;</span>
        <input type="text" inputmode="decimal" name="max_price" placeholder="<?= Fmt::e(Fmt::money($priceBounds['max'], false)) ?>"
               value="<?= Fmt::e($filters['max_price'] !== null ? Fmt::money($filters['max_price'], false) : '') ?>" class="field">
    </div>
</div>
