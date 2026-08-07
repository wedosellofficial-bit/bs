<?php

declare(strict_types=1);

/**
 * Collection browser. Left filter rail, results grid on the right.
 *
 * Every control here is a real form element inside a real <form>, so the
 * page works with JavaScript disabled: submitting reloads with the filters
 * in the query string. app.js then intercepts changes, fetches
 * /collection/results, and pushes the same URL with history.pushState -
 * which is what makes a filtered view both shareable and back-buttonable.
 *
 * @var array<string,mixed> $filters
 * @var array{items:list<array<string,mixed>>,total:int,page:int,pages:int} $results
 * @var list<array{trait_type:string,values:list<array{value:string,count:int}>}> $facets
 * @var array{min:int,max:int} $priceBounds
 * @var list<array<string,mixed>> $collections
 * @var array<string,string> $sortOptions
 * @var string $summary
 */

use App\Lib\Fmt;
use App\Lib\View;

$digits = 10 ** \App\Lib\Config::int('ledger.minor_digits', 2);

$selectedTraits = [];
foreach ($filters['traits'] as $type => $values) {
    foreach ($values as $value) {
        $selectedTraits[] = $type . ':' . $value;
    }
}

$activeFilterCount = count($selectedTraits)
    + ($filters['collection'] !== '' ? 1 : 0)
    + ($filters['min_price'] !== null || $filters['max_price'] !== null ? 1 : 0)
    + ($filters['status'] !== 'listed' ? 1 : 0)
    + ($filters['q'] !== '' ? 1 : 0);
?>

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    <header class="mb-8">
        <h1 class="font-display text-4xl text-ink-100 sm:text-5xl">Collection</h1>
        <p class="mt-2 max-w-2xl text-ink-400">
            Every inscription is held in the project wallet until it sells, then transferred
            to your taproot address by hand.
        </p>
    </header>

    <form id="filters" method="get" action="/collection" class="lg:grid lg:grid-cols-[17rem_1fr] lg:gap-8">

        <?php // ---------- Filter rail ---------- ?>
        <aside class="lg:sticky lg:top-24 lg:self-start">
            <details class="card mb-4 p-4 lg:hidden" <?= $activeFilterCount > 0 ? 'open' : '' ?>>
                <summary class="flex cursor-pointer items-center justify-between text-sm font-medium">
                    <span>Filters</span>
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="badge badge-listed"><?= (int) $activeFilterCount ?> active</span>
                    <?php endif; ?>
                </summary>
                <div class="mt-4" data-filter-panel-mobile></div>
            </details>

            <div class="card hidden p-5 lg:block" data-filter-panel>

                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink-100">Filters</h2>
                    <a href="/collection"
                       class="text-xs text-ink-500 underline-offset-2 transition-colors hover:text-ember-500 hover:underline <?= $activeFilterCount === 0 ? 'invisible' : '' ?>"
                       data-filter-reset>Clear all</a>
                </div>

                <?php // --- search --- ?>
                <div class="filter-group">
                    <label class="label" for="filter-q">Search</label>
                    <input type="search" id="filter-q" name="q" value="<?= Fmt::e($filters['q']) ?>"
                           class="field" placeholder="Name or inscription id"
                           autocomplete="off" data-filter-input data-debounce="350">
                </div>

                <?php // --- collection --- ?>
                <?php if ($collections !== []): ?>
                    <div class="filter-group">
                        <label class="label" for="filter-collection">Collection</label>
                        <select id="filter-collection" name="collection" class="field" data-filter-input>
                            <option value="">All collections</option>
                            <?php foreach ($collections as $collection): ?>
                                <option value="<?= Fmt::e((string) $collection['slug']) ?>"
                                    <?= $filters['collection'] === $collection['slug'] ? 'selected' : '' ?>>
                                    <?= Fmt::e((string) $collection['name']) ?>
                                    (<?= (int) $collection['listed_count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php // --- price --- ?>
                <?php
                $boundMin = (int) $priceBounds['min'];
                $boundMax = max($boundMin + 1, (int) $priceBounds['max']);
                $valueMin = $filters['min_price'] ?? $boundMin;
                $valueMax = $filters['max_price'] ?? $boundMax;
                ?>
                <div class="filter-group" data-price-filter
                     data-bound-min="<?= (int) $boundMin ?>" data-bound-max="<?= (int) $boundMax ?>">
                    <div class="mb-3 flex items-baseline justify-between">
                        <span class="label mb-0">Price</span>
                        <output class="price text-xs text-ink-300" data-price-output>
                            <?= Fmt::e(Fmt::money($valueMin)) ?> &ndash; <?= Fmt::e(Fmt::money($valueMax)) ?>
                        </output>
                    </div>

                    <div class="range-stack">
                        <div class="range-track"><div class="range-fill" data-price-fill></div></div>

                        <?php
                        // Two native range inputs. Native means keyboard
                        // support, screen-reader announcements and a working
                        // no-JS fallback come for free.
                        ?>
                        <input type="range" name="min_price"
                               min="<?= (int) $boundMin ?>" max="<?= (int) $boundMax ?>"
                               value="<?= (int) $valueMin ?>" step="100"
                               aria-label="Minimum price" data-price-min data-filter-input data-debounce="400">

                        <input type="range" name="max_price"
                               min="<?= (int) $boundMin ?>" max="<?= (int) $boundMax ?>"
                               value="<?= (int) $valueMax ?>" step="100"
                               aria-label="Maximum price" data-price-max data-filter-input data-debounce="400">
                    </div>
                </div>

                <?php // --- status --- ?>
                <div class="filter-group">
                    <span class="label">Status</span>
                    <div class="flex flex-col gap-0.5">
                        <?php foreach (['listed' => 'For sale', 'sold' => 'Sold', 'all' => 'Everything'] as $value => $label): ?>
                            <label class="check-row">
                                <input type="radio" name="status" value="<?= Fmt::e($value) ?>"
                                       class="accent-ember-500"
                                    <?= $filters['status'] === $value ? 'checked' : '' ?> data-filter-input>
                                <span><?= Fmt::e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php // --- traits --- ?>
                <?php foreach ($facets as $facet): ?>
                    <?php
                    $selectedInGroup = count($filters['traits'][$facet['trait_type']] ?? []);
                    ?>
                    <details class="filter-group" <?= $selectedInGroup > 0 ? 'open' : '' ?>>
                        <summary class="flex cursor-pointer list-none items-center justify-between">
                            <span class="label mb-0"><?= Fmt::e($facet['trait_type']) ?></span>
                            <span class="flex items-center gap-2">
                                <?php if ($selectedInGroup > 0): ?>
                                    <span class="badge badge-listed"><?= (int) $selectedInGroup ?></span>
                                <?php endif; ?>
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="text-ink-500">
                                    <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                            </span>
                        </summary>

                        <div class="mt-2 flex max-h-56 flex-col gap-0.5 overflow-y-auto pr-1">
                            <?php foreach ($facet['values'] as $option): ?>
                                <?php $pair = $facet['trait_type'] . ':' . $option['value']; ?>
                                <label class="check-row justify-between">
                                    <span class="flex min-w-0 items-center gap-2.5">
                                        <input type="checkbox" name="traits[]" value="<?= Fmt::e($pair) ?>"
                                            <?= in_array($pair, $selectedTraits, true) ? 'checked' : '' ?>
                                               data-filter-input>
                                        <span class="truncate"><?= Fmt::e($option['value']) ?></span>
                                    </span>
                                    <span class="price shrink-0 text-xs text-ink-600"><?= (int) $option['count'] ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php // No-JS submit. Hidden once app.js takes over. ?>
                <noscript>
                    <button type="submit" class="btn btn-primary mt-5 w-full">Apply filters</button>
                </noscript>
            </div>
        </aside>

        <?php // ---------- Results ---------- ?>
        <section class="mt-6 min-w-0 lg:mt-0">

            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-400" data-result-count aria-live="polite">
                    <?= Fmt::e($summary) ?>
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <span class="text-ink-500">Sort</span>
                    <select name="sort" class="field w-auto py-1.5 text-sm" data-filter-input>
                        <?php foreach ($sortOptions as $value => $label): ?>
                            <option value="<?= Fmt::e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>>
                                <?= Fmt::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <?php // --- active filter chips --- ?>
            <?php if ($activeFilterCount > 0): ?>
                <div class="mb-5 flex flex-wrap gap-2">
                    <?php foreach ($selectedTraits as $pair): ?>
                        <?php [$type, $value] = explode(':', $pair, 2); ?>
                        <button type="button" class="badge badge-muted hover:border-ink-600"
                                data-remove-trait="<?= Fmt::e($pair) ?>">
                            <span class="text-ink-500"><?= Fmt::e($type) ?></span>
                            <span><?= Fmt::e($value) ?></span>
                            <span aria-hidden="true" class="text-ink-500">&times;</span>
                            <span class="sr-only">Remove filter</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="results" class="grid-fade">
                <?= View::partial('partials/nft-grid', [
                    'results'      => $results,
                    'filters'      => $filters,
                    'emptyMessage' => $emptyMessage ?? $summary,
                ]) ?>
            </div>
        </section>
    </form>
</div>
