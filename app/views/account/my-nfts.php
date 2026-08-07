<?php
declare(strict_types=1);

/** @var list<array<string,mixed>> $items */

use App\Lib\Fmt;
use App\Lib\View;
use App\Ordinals;
?>
<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

    <h1 class="font-display text-4xl text-ink-100">My inscriptions</h1>
    <p class="mt-1 text-ink-400">Everything you have bought, and where each transfer has got to.</p>

    <?php if ($items === []): ?>
        <div class="card mt-8 px-6 py-16 text-center">
            <p class="text-ink-400">You have not bought anything yet.</p>
            <a href="/collection" class="btn btn-primary mt-5">Browse the collection</a>
        </div>
    <?php else: ?>
        <div class="mt-8 grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($items as $item): ?>
                <?php
                $status = (string) $item['order_status'];
                [$badgeClass, $badgeLabel] = match ($status) {
                    'complete'     => ['badge-ok', 'In your wallet'],
                    'transferring' => ['badge-pending', 'Sending'],
                    default        => ['badge-pending', 'Queued'],
                };
                $txid = is_string($item['tx_hash'] ?? null) ? (string) $item['tx_hash'] : '';
                ?>
                <article class="card overflow-hidden">
                    <a href="/nft/<?= (int) $item['nft_id'] ?>" class="block art-frame">
                        <img src="<?= Fmt::e(View::media($item['preview_path'] ?? null)) ?>"
                             alt="<?= Fmt::e((string) $item['name']) ?>" loading="lazy" width="640" height="640">
                    </a>

                    <div class="border-t border-ink-800 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <h2 class="min-w-0 truncate font-display text-lg text-ink-100">
                                <?= Fmt::e((string) $item['name']) ?>
                            </h2>
                            <span class="badge <?= Fmt::e($badgeClass) ?> shrink-0"><?= Fmt::e($badgeLabel) ?></span>
                        </div>

                        <dl class="mt-3 space-y-2 text-xs">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-600">Sent to</dt>
                                <dd><?= View::partial('partials/ident', [
                                        'value' => (string) $item['buyer_wallet_address'],
                                        'head' => 6, 'tail' => 5, 'label' => 'payout address',
                                    ]) ?></dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-600">Transaction</dt>
                                <dd><?= View::partial('partials/ident', [
                                        'value' => $txid, 'head' => 6, 'tail' => 5,
                                        'label' => 'transfer transaction',
                                        'href' => $txid !== '' ? Ordinals::explorerTxUrl($txid) : '',
                                    ]) ?></dd>
                            </div>
                        </dl>

                        <a href="/account/orders/<?= (int) $item['order_id'] ?>"
                           class="mt-3 inline-block text-xs text-ember-500 hover:underline">Order details &rarr;</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
