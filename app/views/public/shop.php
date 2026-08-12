<?php

declare(strict_types=1);

/**
 * Product shop: plain GET-form filtering, no client-side wiring - see
 * the note on ProductController::index().
 *
 * @var array<string,mixed> $filters
 * @var array{items:list<array<string,mixed>>,total:int,page:int,pages:int} $results
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $tags
 * @var array{min:int,max:int} $priceBounds
 * @var array<string,string> $sortOptions
 */

use App\Lib\Fmt;
use App\Lib\View;

$activeFilterCount = ($filters['category'] !== '' ? 1 : 0)
    + ($filters['tag'] !== '' ? 1 : 0)
    + ($filters['min_price'] !== null || $filters['max_price'] !== null ? 1 : 0)
    + ($filters['q'] !== '' ? 1 : 0);
?>

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    <header class="mb-8">
        <h1 class="font-display text-4xl text-ink-100 sm:text-5xl">Collections</h1>
        <p class="mt-2 max-w-2xl text-ink-400">
            Digital art and design files. Pay from your store balance and download instantly.
        </p>
    </header>

    <form method="get" action="/shop" class="lg:grid lg:grid-cols-[17rem_1fr] lg:gap-8">

        <aside class="lg:sticky lg:top-24 lg:self-start">
            <details class="card mb-4 p-4 lg:hidden" <?= $activeFilterCount > 0 ? 'open' : '' ?>>
                <summary class="flex cursor-pointer items-center justify-between text-sm font-medium">
                    <span>Filters</span>
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="badge badge-listed"><?= (int) $activeFilterCount ?> active</span>
                    <?php endif; ?>
                </summary>
                <div class="mt-4">
                    <?= View::partial('partials/shop-filters', compact('filters', 'categories', 'tags', 'priceBounds')) ?>
                </div>
            </details>

            <div class="card hidden p-5 lg:block">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink-100">Filters</h2>
                    <a href="/shop" class="text-xs text-ink-500 underline-offset-2 transition-colors hover:text-ember-500 hover:underline <?= $activeFilterCount === 0 ? 'invisible' : '' ?>">
                        Clear all
                    </a>
                </div>
                <?= View::partial('partials/shop-filters', compact('filters', 'categories', 'tags', 'priceBounds')) ?>
                <button type="submit" class="btn btn-primary mt-5 w-full">Apply filters</button>
            </div>
        </aside>

        <section class="mt-6 min-w-0 lg:mt-0">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-400">
                    <?= $results['total'] === 1 ? '1 item' : number_format($results['total']) . ' items' ?>
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <span class="text-ink-500">Sort</span>
                    <select name="sort" class="field w-auto py-1.5 text-sm" onchange="this.form.submit()">
                        <?php foreach ($sortOptions as $value => $label): ?>
                            <option value="<?= Fmt::e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>>
                                <?= Fmt::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <?= View::partial('partials/product-grid', ['results' => $results, 'filters' => $filters]) ?>
        </section>
    </form>
</div>
