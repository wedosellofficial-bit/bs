<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $product
 * @var bool $isMember
 */

use App\Lib\Fmt;
use App\Lib\View;
use App\Product;

$locked = (bool) ($product['category_is_members_only'] ?? false) && !($isMember ?? false);
$price = Product::effectivePriceMinor($product, $isMember ?? false);
$hasMemberPrice = ($product['member_price_minor'] ?? null) !== null;
?>
<article class="product-card card group overflow-hidden transition-colors hover:border-ink-700">
    <a href="/products/<?= Fmt::e(rawurlencode((string) $product['slug'])) ?>" class="block focus-visible:outline-offset-4">
        <div class="art-frame">
            <img src="<?= Fmt::e(View::productMedia($product['preview_path'] ?? null)) ?>"
                 alt="<?= Fmt::e((string) $product['name']) ?>"
                 loading="lazy"
                 decoding="async"
                 width="640" height="640">

            <?php if ($locked): ?>
                <div class="absolute inset-0 flex items-center justify-center bg-ink-950/70">
                    <span class="badge badge-muted">Members only</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="border-t border-ink-800 p-4">
            <h3 class="truncate font-display text-lg leading-tight text-ink-100">
                <?= Fmt::e((string) $product['name']) ?>
            </h3>

            <p class="mt-1 truncate text-xs text-ink-500">
                <?= Fmt::e((string) ($product['category_name'] ?? '')) ?>
            </p>

            <div class="mt-3 flex items-end justify-between gap-3">
                <div>
                    <div class="text-[0.65rem] uppercase tracking-wider text-ink-600">Price</div>
                    <div class="price mt-0.5 text-base text-ember-500">
                        <?= Fmt::e(Fmt::money($price)) ?>
                    </div>
                    <?php if ($hasMemberPrice && !($isMember ?? false)): ?>
                        <div class="text-[0.65rem] text-ink-500">
                            <?= Fmt::e(Fmt::money((int) $product['member_price_minor'])) ?> for members
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </a>
</article>
