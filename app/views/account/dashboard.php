<?php
declare(strict_types=1);

/**
 * @var int $balance
 * @var list<array<string,mixed>> $orders
 * @var list<array<string,mixed>> $owned
 * @var list<array<string,mixed>> $productOrders
 * @var list<array<string,mixed>> $statement
 * @var bool $isActive
 * @var int $minActivationMinor
 * @var list<array<string,mixed>> $announcements
 */

use App\Lib\Fmt;
use App\Lib\View;
?>
<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

    <h1 class="font-display text-4xl text-ink-100">Dashboard</h1>

    <?php if (!$isActive): ?>
        <div class="mt-6 flash flash-info">
            <div>
                <p class="font-medium">
                    Your account is pending. Fund your wallet with at least
                    <?= Fmt::e(Fmt::money($minActivationMinor)) ?> to activate marketplace access - the
                    catalog and checkout both open up once you do.
                </p>
                <a href="/account/wallet" class="mt-1 inline-block text-sm underline">Add funds</a>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($announcements as $announcement): ?>
        <div class="mt-6 card p-4 <?= $announcement['level'] === 'critical' ? 'border-rose-600' : ($announcement['level'] === 'warning' ? 'border-amber-400/40' : '') ?>">
            <h2 class="text-sm font-semibold text-ink-100"><?= Fmt::e((string) $announcement['title']) ?></h2>
            <p class="mt-1 whitespace-pre-line text-sm text-ink-400"><?= Fmt::e((string) $announcement['body']) ?></p>
        </div>
    <?php endforeach; ?>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <a href="/account/wallet" class="card ambient-glow-sm p-5 transition-colors hover:border-ink-700">
            <p class="text-xs uppercase tracking-wider text-ink-500">Balance</p>
            <p class="price mt-1 text-3xl text-ink-100"><?= Fmt::e(Fmt::money($balance)) ?></p>
            <p class="mt-2 text-sm text-ember-500">Add funds &rarr;</p>
        </a>

        <a href="/account/nfts" class="card ambient-glow-sm p-5 transition-colors hover:border-ink-700">
            <p class="text-xs uppercase tracking-wider text-ink-500">Inscriptions</p>
            <p class="price mt-1 text-3xl text-ink-100"><?= count($owned) ?></p>
            <p class="mt-2 text-sm text-ember-500">View them &rarr;</p>
        </a>

        <a href="/account/orders" class="card ambient-glow-sm p-5 transition-colors hover:border-ink-700">
            <p class="text-xs uppercase tracking-wider text-ink-500">Orders</p>
            <p class="price mt-1 text-3xl text-ink-100"><?= count($orders) ?></p>
            <p class="mt-2 text-sm text-ember-500">Track them &rarr;</p>
        </a>

        <a href="/account/product-orders" class="card ambient-glow-sm p-5 transition-colors hover:border-ink-700">
            <p class="text-xs uppercase tracking-wider text-ink-500">Downloads</p>
            <p class="price mt-1 text-3xl text-ink-100"><?= count($productOrders) ?></p>
            <p class="mt-2 text-sm text-ember-500">View orders &rarr;</p>
        </a>

        <a href="/account/wallet" class="card ambient-glow-sm p-5 transition-colors hover:border-ink-700">
            <p class="text-xs uppercase tracking-wider text-ink-500">Account</p>
            <p class="price mt-1 text-3xl text-ink-100"><?= $isActive ? 'Active' : 'Pending' ?></p>
            <p class="mt-2 text-sm text-ember-500"><?= $isActive ? 'Ready to buy' : 'Fund to activate' ?> &rarr;</p>
        </a>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="card">
            <div class="flex items-center justify-between border-b border-ink-800 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink-100">Recent orders</h2>
                <a href="/account/orders" class="text-xs text-ember-500 hover:underline">All orders</a>
            </div>

            <?php if ($orders === []): ?>
                <div class="px-5 py-10 text-center">
                    <p class="text-sm text-ink-500">No orders yet.</p>
                    <?php if ($isActive): ?>
                        <a href="/collection" class="btn btn-secondary btn-sm mt-4">Browse the collection</a>
                    <?php else: ?>
                        <a href="/account/wallet" class="btn btn-secondary btn-sm mt-4">Fund your wallet to browse</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="divide-y divide-ink-800">
                    <?php foreach ($orders as $order): ?>
                        <li>
                            <a href="/account/orders/<?= (int) $order['id'] ?>"
                               class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-ink-850">
                                <img src="<?= Fmt::e(View::media($order['preview_path'] ?? null)) ?>" alt=""
                                     class="h-10 w-10 shrink-0 rounded object-cover" width="40" height="40">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-ink-200"><?= Fmt::e((string) $order['nft_name']) ?></p>
                                    <p class="text-xs text-ink-600"><?= Fmt::e(Fmt::relative((string) $order['created_at'])) ?></p>
                                </div>
                                <span class="price shrink-0 text-sm text-ink-300"><?= Fmt::e(Fmt::money((int) $order['price_minor'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="flex items-center justify-between border-b border-ink-800 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink-100">Recent balance activity</h2>
                <a href="/account/wallet/statement" class="text-xs text-ember-500 hover:underline">Statement</a>
            </div>

            <?php if ($statement === []): ?>
                <p class="px-5 py-10 text-center text-sm text-ink-500">Nothing yet.</p>
            <?php else: ?>
                <ul class="divide-y divide-ink-800">
                    <?php foreach ($statement as $entry): ?>
                        <?php $amount = (int) $entry['amount_minor']; ?>
                        <li class="flex items-start justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-ink-200">
                                    <?= Fmt::e((string) ($entry['memo'] ?? ucfirst((string) $entry['type']))) ?>
                                </p>
                                <p class="text-xs text-ink-600"><?= Fmt::e(Fmt::relative((string) $entry['created_at'])) ?></p>
                            </div>
                            <span class="price shrink-0 text-sm <?= $amount >= 0 ? 'text-mint-400' : 'text-ink-300' ?>">
                                <?= Fmt::e(Fmt::moneySigned($amount)) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
