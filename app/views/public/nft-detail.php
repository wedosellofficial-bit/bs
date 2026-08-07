<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $nft
 * @var list<array{trait_type:string,value:string}> $attributes
 * @var array<string,mixed>|null $order
 * @var string $explorerInscription
 * @var string $explorerTx
 * @var list<array<string,mixed>> $related
 */

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;

$status = (string) $nft['status'];
$isListed = $status === 'listed';
?>

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    <nav class="mb-6 flex items-center gap-2 text-sm text-ink-500" aria-label="Breadcrumb">
        <a href="/collection" class="transition-colors hover:text-ink-200">Collection</a>
        <span aria-hidden="true" class="text-ink-700">/</span>
        <span class="truncate text-ink-300"><?= Fmt::e((string) $nft['name']) ?></span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-2">

        <div class="card overflow-hidden">
            <div class="art-frame">
                <img src="<?= Fmt::e(View::media($nft['image_path'] ?? $nft['preview_path'] ?? null, 'full')) ?>"
                     alt="<?= Fmt::e((string) $nft['name']) ?>"
                     width="1600" height="1600">
            </div>
        </div>

        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <span class="badge <?= $isListed ? 'badge-listed' : ($status === 'transferred' ? 'badge-ok' : 'badge-muted') ?>">
                    <?= Fmt::e($isListed ? 'For sale' : ucfirst($status)) ?>
                </span>
                <?php if (($nft['collection_name'] ?? null) !== null): ?>
                    <a href="/collection?collection=<?= Fmt::e(rawurlencode((string) $nft['collection_slug'])) ?>"
                       class="text-sm text-ink-400 hover:text-ember-500">
                        <?= Fmt::e((string) $nft['collection_name']) ?>
                    </a>
                <?php endif; ?>
            </div>

            <h1 class="mt-3 font-display text-4xl leading-tight text-ink-100 sm:text-5xl">
                <?= Fmt::e((string) $nft['name']) ?>
            </h1>

            <?php if (($nft['description'] ?? null) !== null): ?>
                <p class="mt-4 whitespace-pre-line leading-relaxed text-ink-400">
                    <?= Fmt::e((string) $nft['description']) ?>
                </p>
            <?php endif; ?>

            <div class="card-raised mt-7 p-5">
                <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Price</p>
                <p class="price mt-1 text-4xl <?= $isListed ? 'text-ember-500' : 'text-ink-500 line-through' ?>">
                    <?= Fmt::e(Fmt::money((int) $nft['price_minor'])) ?>
                </p>

                <?php if ($isListed): ?>
                    <?php if (Auth::check()): ?>
                        <a href="/buy/<?= (int) $nft['id'] ?>" class="btn btn-primary mt-5 w-full text-base">
                            Buy with store balance
                        </a>
                    <?php else: ?>
                        <a href="/login?next=<?= Fmt::e(rawurlencode('/buy/' . (int) $nft['id'])) ?>"
                           class="btn btn-primary mt-5 w-full text-base">Sign in to buy</a>
                    <?php endif; ?>

                    <p class="hint mt-3 text-center">
                        Paid from your balance. You will confirm the payout address before anything is charged.
                    </p>
                <?php elseif ($order !== null && ($order['tx_hash'] ?? '') !== ''): ?>
                    <div class="mt-4 border-t border-ink-800 pt-4">
                        <p class="text-xs uppercase tracking-wider text-ink-500">Transferred</p>
                        <div class="mt-1">
                            <?= View::partial('partials/ident', [
                                'value' => (string) $order['tx_hash'],
                                'head'  => 10, 'tail' => 8,
                                'label' => 'transfer transaction',
                                'href'  => $explorerTx,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php // --- provenance --- ?>
            <dl class="mt-6 space-y-0 divide-y divide-ink-800 border-y border-ink-800 text-sm">
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-ink-500">Inscription id</dt>
                    <dd>
                        <?= View::partial('partials/ident', [
                            'value' => (string) $nft['token_id'],
                            'head'  => 8, 'tail' => 7,
                            'label' => 'inscription id',
                            'href'  => $explorerInscription,
                        ]) ?>
                    </dd>
                </div>

                <?php if (($nft['inscription_number'] ?? null) !== null): ?>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="text-ink-500">Inscription number</dt>
                        <dd class="ident text-ink-200">#<?= Fmt::e(number_format((int) $nft['inscription_number'])) ?></dd>
                    </div>
                <?php endif; ?>

                <?php if (($nft['sat_ordinal'] ?? null) !== null): ?>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="text-ink-500">Sat ordinal</dt>
                        <dd class="ident text-ink-200"><?= Fmt::e(number_format((int) $nft['sat_ordinal'])) ?></dd>
                    </div>
                <?php endif; ?>

                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-ink-500">Chain</dt>
                    <dd class="ident text-ink-200">Bitcoin &middot; Ordinals</dd>
                </div>

                <?php if ((int) ($nft['rarity_score'] ?? 0) > 0): ?>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="text-ink-500">Rarity score</dt>
                        <dd class="ident text-ink-200"><?= Fmt::e(number_format(((int) $nft['rarity_score']) / 100, 2)) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($attributes !== []): ?>
                <h2 class="mt-7 text-sm font-semibold text-ink-100">Traits</h2>
                <dl class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                    <?php foreach ($attributes as $attribute): ?>
                        <a href="/collection?traits=<?= Fmt::e(rawurlencode($attribute['trait_type'] . ':' . $attribute['value'])) ?>"
                           class="rounded-md border border-ink-800 bg-ink-850 px-3 py-2 transition-colors hover:border-ember-700">
                            <dt class="truncate text-[0.65rem] uppercase tracking-wider text-ink-600">
                                <?= Fmt::e($attribute['trait_type']) ?>
                            </dt>
                            <dd class="mt-0.5 truncate text-sm text-ink-200"><?= Fmt::e($attribute['value']) ?></dd>
                        </a>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($related !== []): ?>
        <section class="mt-16">
            <h2 class="mb-5 font-display text-2xl text-ink-100">More from this collection</h2>
            <div class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-4">
                <?php foreach ($related as $item): ?>
                    <?= View::partial('partials/nft-card', ['nft' => $item]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
