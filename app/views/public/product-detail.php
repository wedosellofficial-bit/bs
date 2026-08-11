<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $product
 * @var list<array<string,mixed>> $tags
 * @var bool $isMember
 * @var bool $locked
 * @var int $price
 * @var bool $alreadyOwned
 */

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;

$hasMemberPrice = ($product['member_price_minor'] ?? null) !== null;
?>

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    <nav class="mb-6 flex items-center gap-2 text-sm text-ink-500" aria-label="Breadcrumb">
        <a href="/shop" class="transition-colors hover:text-ink-200">Collections</a>
        <span aria-hidden="true" class="text-ink-700">/</span>
        <span class="truncate text-ink-300"><?= Fmt::e((string) $product['name']) ?></span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="card overflow-hidden">
            <div class="art-frame">
                <img src="<?= Fmt::e(View::productMedia($product['image_path'] ?? $product['preview_path'] ?? null, 'full')) ?>"
                     alt="<?= Fmt::e((string) $product['name']) ?>"
                     width="1600" height="1600">
            </div>
        </div>

        <div class="min-w-0">
            <?php if (($product['category_name'] ?? null) !== null): ?>
                <a href="/shop?category=<?= Fmt::e(rawurlencode((string) $product['category_slug'])) ?>"
                   class="text-sm text-ink-400 hover:text-ember-500">
                    <?= Fmt::e((string) $product['category_name']) ?>
                </a>
            <?php endif; ?>

            <h1 class="mt-3 font-display text-4xl leading-tight text-ink-100 sm:text-5xl">
                <?= Fmt::e((string) $product['name']) ?>
            </h1>

            <?php if (($product['description'] ?? null) !== null): ?>
                <p class="mt-4 whitespace-pre-line leading-relaxed text-ink-400">
                    <?= Fmt::e((string) $product['description']) ?>
                </p>
            <?php endif; ?>

            <?php if ($tags !== []): ?>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($tags as $tag): ?>
                        <a href="/shop?tag=<?= Fmt::e(rawurlencode((string) $tag['slug'])) ?>" class="badge badge-muted hover:border-ink-600">
                            <?= Fmt::e((string) $tag['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card-raised mt-7 p-5">
                <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Price</p>
                <p class="price mt-1 text-4xl text-ember-500"><?= Fmt::e(Fmt::money($price)) ?></p>

                <?php if ($hasMemberPrice): ?>
                    <p class="mt-1 text-sm text-ink-500">
                        <?= $isMember
                            ? 'Member price applied.'
                            : sprintf('Members pay %s. ', Fmt::money((int) $product['member_price_minor']))
                              . '<a href="/membership" class="text-ember-500 hover:underline">Join Billions Membership</a>' ?>
                    </p>
                <?php endif; ?>

                <?php if ($locked): ?>
                    <div class="mt-5 rounded-lg border border-ink-800 bg-ink-900 p-4 text-center">
                        <p class="text-sm text-ink-300">This collection is members-only.</p>
                        <a href="/membership" class="btn btn-primary mt-3 w-full">Join to access</a>
                    </div>
                <?php elseif ($alreadyOwned): ?>
                    <p class="hint mt-4 text-center">You already own this. Find your download on
                        <a href="/account/product-orders" class="text-ember-500 hover:underline">your orders page</a>.</p>
                <?php elseif (Auth::check()): ?>
                    <form method="post" action="/products/<?= Fmt::e(rawurlencode((string) $product['slug'])) ?>/buy" class="mt-5">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-primary w-full text-base">Buy with store balance</button>
                    </form>
                    <p class="hint mt-3 text-center">Delivered instantly to your account after purchase.</p>
                <?php else: ?>
                    <a href="/login?next=<?= Fmt::e(rawurlencode('/products/' . (string) $product['slug'])) ?>"
                       class="btn btn-primary mt-5 w-full text-base">Sign in to buy</a>
                <?php endif; ?>
            </div>

            <?php if (($product['inscription_id'] ?? null) !== null): ?>
                <dl class="mt-6 space-y-0 divide-y divide-ink-800 border-y border-ink-800 text-sm">
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="text-ink-500">Inscription id</dt>
                        <dd>
                            <?= View::partial('partials/ident', [
                                'value' => (string) $product['inscription_id'],
                                'head'  => 8, 'tail' => 7,
                                'label' => 'inscription id',
                                'href'  => '',
                            ]) ?>
                        </dd>
                    </div>
                </dl>
                <p class="hint mt-2">
                    Display metadata only - a placeholder reference, not a live on-chain inscription tied to this purchase.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
