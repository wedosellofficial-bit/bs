<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $featured
 * @var list<array<string,mixed>> $collections
 * @var array{listed:int,sold:int,floor_price:int} $stats
 * @var array<string,mixed>|null $announcement
 */

use App\Lib\Fmt;
use App\Lib\View;
?>

<?php if ($announcement !== null): ?>
    <div class="border-b <?= $announcement['level'] === 'critical' ? 'border-rose-600 bg-rose-900/40' : 'border-ink-800 bg-ink-900' ?>">
        <div class="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
            <p class="text-sm">
                <strong class="text-ink-100"><?= Fmt::e((string) $announcement['title']) ?></strong>
                <span class="text-ink-400"><?= Fmt::e(mb_substr((string) $announcement['body'], 0, 180)) ?></span>
            </p>
        </div>
    </div>
<?php endif; ?>

<section class="border-b border-ink-800">
    <div class="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <p class="font-mono text-xs uppercase tracking-[0.28em] text-ember-500">Bitcoin Ordinals</p>

        <h1 class="mt-5 max-w-3xl font-display text-5xl leading-[1.05] text-ink-100 sm:text-7xl">
            Inscriptions, held properly<br class="hidden sm:block">
            and handed over by hand.
        </h1>

        <p class="mt-6 max-w-xl text-lg leading-relaxed text-ink-400">
            Every piece sits in our project wallet until it sells. Pay from your store
            balance, give us a taproot address, and we send it &mdash; checked by a person,
            not a hot wallet.
        </p>

        <div class="mt-9 flex flex-wrap gap-3">
            <a href="/collection" class="btn btn-primary">Browse the collection</a>
            <a href="/about" class="btn btn-secondary">How it works</a>
        </div>

        <dl class="mt-16 grid max-w-2xl grid-cols-2 gap-8 border-t border-ink-800 pt-8 sm:grid-cols-3">
            <div>
                <dt class="text-xs uppercase tracking-wider text-ink-500">Listed now</dt>
                <dd class="price mt-1 text-3xl text-ink-100"><?= Fmt::e(number_format($stats['listed'])) ?></dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-ink-500">Sold</dt>
                <dd class="price mt-1 text-3xl text-ink-100"><?= Fmt::e(number_format($stats['sold'])) ?></dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-ink-500">Floor</dt>
                <dd class="price mt-1 text-3xl text-ember-500">
                    <?= $stats['floor_price'] > 0 ? Fmt::e(Fmt::money($stats['floor_price'])) : Fmt::e(Fmt::EM_DASH) ?>
                </dd>
            </div>
        </dl>
    </div>
</section>

<?php if ($featured !== []): ?>
    <section class="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h2 class="font-display text-3xl text-ink-100">Rarest available</h2>
                <p class="mt-1 text-sm text-ink-500">Scored on trait frequency across the collection.</p>
            </div>
            <a href="/collection?sort=rarity" class="btn btn-ghost btn-sm shrink-0">See all</a>
        </div>

        <div class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <?php foreach ($featured as $nft): ?>
                <?= View::partial('partials/nft-card', ['nft' => $nft]) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($collections !== []): ?>
    <section class="mx-auto w-full max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
        <h2 class="mb-6 font-display text-3xl text-ink-100">Collections</h2>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($collections as $collection): ?>
                <a href="/collection?collection=<?= Fmt::e(rawurlencode((string) $collection['slug'])) ?>"
                   class="card p-5 transition-colors hover:border-ink-700">
                    <h3 class="font-display text-xl text-ink-100"><?= Fmt::e((string) $collection['name']) ?></h3>
                    <?php if (($collection['description'] ?? null) !== null): ?>
                        <p class="mt-1.5 line-clamp-2 text-sm text-ink-500">
                            <?= Fmt::e(mb_substr((string) $collection['description'], 0, 140)) ?>
                        </p>
                    <?php endif; ?>
                    <p class="price mt-4 text-sm text-ember-500">
                        <?= (int) $collection['listed_count'] ?> listed
                        <span class="text-ink-600">/ <?= (int) $collection['item_count'] ?> total</span>
                    </p>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="border-t border-ink-800 bg-ink-900">
    <div class="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <h2 class="font-display text-3xl text-ink-100">How buying works</h2>

        <ol class="mt-8 grid gap-8 sm:grid-cols-3">
            <?php
            $steps = [
                ['Top up', 'Send BTC to our deposit address, shown on your wallet page. An admin checks it on-chain and credits your balance by hand.'],
                ['Buy', 'Pick a piece and pay from your balance. You give us the taproot address it should go to, and confirm it before anything is charged.'],
                ['Receive', 'An admin sends the inscription from the project wallet and records the transaction id. You can follow it on a block explorer.'],
            ];
            foreach ($steps as $index => [$heading, $body]):
                ?>
                <li>
                    <span class="price text-sm text-ember-500"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <h3 class="mt-2 font-display text-xl text-ink-100"><?= Fmt::e($heading) ?></h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-ink-400"><?= Fmt::e($body) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
