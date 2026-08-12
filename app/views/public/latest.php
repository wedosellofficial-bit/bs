<?php

declare(strict_types=1);

/**
 * "Latest": newest items from both catalogs on one page. Only reachable
 * once the marketplace is activated - see LatestController.
 *
 * @var list<array<string,mixed>> $nfts
 * @var list<array<string,mixed>> $products
 */

use App\Lib\View;
?>

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    <header class="mb-8">
        <h1 class="font-display text-4xl text-ink-100 sm:text-5xl">Latest</h1>
        <p class="mt-2 max-w-2xl text-ink-400">
            The newest listings across the marketplace &mdash; ordinals and digital art, together.
        </p>
    </header>

    <section class="mb-12">
        <div class="mb-5 flex items-center justify-between gap-4">
            <h2 class="font-display text-2xl text-ink-100">Newest inscriptions</h2>
            <a href="/collection" class="text-sm text-ember-500 hover:underline">View all</a>
        </div>

        <?php if ($nfts === []): ?>
            <div class="card px-6 py-10 text-center text-sm text-ink-500">Nothing listed yet.</div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                <?php foreach ($nfts as $nft): ?>
                    <?= View::partial('partials/nft-card', ['nft' => $nft]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section>
        <div class="mb-5 flex items-center justify-between gap-4">
            <h2 class="font-display text-2xl text-ink-100">Newest digital art</h2>
            <a href="/shop" class="text-sm text-ember-500 hover:underline">View all</a>
        </div>

        <?php if ($products === []): ?>
            <div class="card px-6 py-10 text-center text-sm text-ink-500">Nothing listed yet.</div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                <?php foreach ($products as $product): ?>
                    <?= View::partial('partials/product-card', ['product' => $product]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
