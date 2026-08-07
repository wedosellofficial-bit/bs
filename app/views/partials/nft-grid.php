<?php

declare(strict_types=1);

/**
 * The results grid. Rendered on the server for the first page load and
 * returned as an HTML fragment by /collection/results for every filter
 * change - one template, so the two paths cannot drift apart.
 *
 * @var array{items:list<array<string,mixed>>,total:int,page:int,pages:int} $results
 * @var array<string,mixed> $filters
 */

use App\Lib\Fmt;
use App\Lib\View;

if ($results['items'] === []): ?>
    <div class="card flex flex-col items-center justify-center px-6 py-20 text-center">
        <svg width="40" height="40" viewBox="0 0 40 40" fill="none" aria-hidden="true" class="text-ink-700">
            <rect x="6.5" y="6.5" width="27" height="27" rx="3" stroke="currentColor" stroke-width="1.5"/>
            <path d="M6.5 25.5 14 18l6 6 5-5 8.5 8.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>

        <h2 class="mt-5 font-display text-xl text-ink-200">Nothing here</h2>

        <?php
        // The empty state names the filter to loosen. The summary string is
        // built by CollectionController::summary(), which knows which
        // filter is actually doing the excluding.
        ?>
        <p class="mt-2 max-w-md text-sm leading-relaxed text-ink-500">
            <?= Fmt::e($emptyMessage ?? 'Try widening your filters.') ?>
        </p>

        <a href="/collection" class="btn btn-secondary btn-sm mt-6" data-filter-reset>Clear all filters</a>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
        <?php foreach ($results['items'] as $nft): ?>
            <?= View::partial('partials/nft-card', ['nft' => $nft]) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($results['pages'] > 1): ?>
        <nav class="mt-10 flex items-center justify-center gap-1" aria-label="Pagination">
            <?php
            $page = (int) $results['page'];
            $pages = (int) $results['pages'];

            // A compact window: first, last, and two either side of the
            // current page. Rendering 400 page links is not navigation.
            $window = [];
            for ($i = 1; $i <= $pages; $i++) {
                if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
                    $window[] = $i;
                }
            }

            $previous = 0;
            ?>

            <?php if ($page > 1): ?>
                <a href="<?= Fmt::e(View::filterUrl($filters, ['page' => $page - 1])) ?>"
                   class="btn btn-secondary btn-sm" data-page-link rel="prev">Previous</a>
            <?php endif; ?>

            <?php foreach ($window as $number): ?>
                <?php if ($previous !== 0 && $number - $previous > 1): ?>
                    <span class="px-2 text-ink-600" aria-hidden="true">&hellip;</span>
                <?php endif; ?>

                <a href="<?= Fmt::e(View::filterUrl($filters, ['page' => $number])) ?>"
                   data-page-link
                   class="min-w-9 rounded-md px-3 py-1.5 text-center text-sm transition-colors <?= $number === $page ? 'bg-ember-500 font-semibold text-ink-950' : 'text-ink-400 hover:bg-ink-850 hover:text-ink-100' ?>"
                   <?= $number === $page ? 'aria-current="page"' : '' ?>>
                    <?= Fmt::e((string) $number) ?>
                </a>

                <?php $previous = $number; ?>
            <?php endforeach; ?>

            <?php if ($page < $pages): ?>
                <a href="<?= Fmt::e(View::filterUrl($filters, ['page' => $page + 1])) ?>"
                   class="btn btn-secondary btn-sm" data-page-link rel="next">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
