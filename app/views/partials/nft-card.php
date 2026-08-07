<?php

declare(strict_types=1);

/** @var array<string,mixed> $nft */

use App\Lib\Fmt;
use App\Lib\View;

$status = (string) ($nft['status'] ?? 'listed');
$isListed = $status === 'listed';

$badge = match ($status) {
    'listed'      => ['badge-listed', 'For sale'],
    'sold'        => ['badge-muted', 'Sold'],
    'transferred' => ['badge-ok', 'Transferred'],
    default       => ['badge-muted', ucfirst($status)],
};
?>
<article class="nft-card card group overflow-hidden transition-colors hover:border-ink-700">
    <a href="/nft/<?= (int) $nft['id'] ?>" class="block focus-visible:outline-offset-4">
        <div class="art-frame">
            <img src="<?= Fmt::e(View::media($nft['preview_path'] ?? null)) ?>"
                 alt="<?= Fmt::e((string) $nft['name']) ?>"
                 loading="lazy"
                 decoding="async"
                 width="640" height="640">

            <?php if (!$isListed): ?>
                <div class="absolute inset-0 bg-ink-950/55"></div>
            <?php endif; ?>

            <span class="badge <?= Fmt::e($badge[0]) ?> absolute left-3 top-3"><?= Fmt::e($badge[1]) ?></span>
        </div>

        <div class="border-t border-ink-800 p-4">
            <h3 class="truncate font-display text-lg leading-tight text-ink-100">
                <?= Fmt::e((string) $nft['name']) ?>
            </h3>

            <p class="mt-1 truncate text-xs text-ink-500">
                <?php if (($nft['inscription_number'] ?? null) !== null): ?>
                    <span class="font-mono">#<?= Fmt::e(number_format((int) $nft['inscription_number'])) ?></span>
                    <?php if (($nft['collection_name'] ?? null) !== null): ?>
                        <span class="text-ink-700"> / </span>
                    <?php endif; ?>
                <?php endif; ?>
                <?= Fmt::e((string) ($nft['collection_name'] ?? '')) ?>
            </p>

            <div class="mt-3 flex items-end justify-between gap-3">
                <div>
                    <div class="text-[0.65rem] uppercase tracking-wider text-ink-600">Price</div>
                    <div class="price mt-0.5 text-base <?= $isListed ? 'text-ember-500' : 'text-ink-500 line-through' ?>">
                        <?= Fmt::e(Fmt::money((int) $nft['price_minor'])) ?>
                    </div>
                </div>

                <?php if (($nft['rarity_score'] ?? 0) > 0): ?>
                    <div class="text-right">
                        <div class="text-[0.65rem] uppercase tracking-wider text-ink-600">Rarity</div>
                        <div class="price mt-0.5 text-sm text-ink-300">
                            <?= Fmt::e(number_format(((int) $nft['rarity_score']) / 100, 1)) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </a>
</article>
