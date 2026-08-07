<?php
declare(strict_types=1);

/**
 * @var array<string,mixed> $order
 * @var string $explorerTx
 * @var string $explorerInscription
 */

use App\Lib\Fmt;
use App\Lib\View;

$status = (string) $order['status'];

$steps = [
    ['paid', 'Paid', 'Your balance was debited and the order recorded.'],
    ['queued', 'Queued', 'Waiting for an admin to send the inscription from the project wallet.'],
    ['transferring', 'Sending', 'The transfer has been broadcast to the Bitcoin network.'],
    ['complete', 'Complete', 'Confirmed on-chain. The inscription is in your wallet.'],
];

$order_of = ['paid' => 0, 'queued' => 1, 'transferring' => 2, 'complete' => 3];
$currentStep = $order_of[$status] ?? 0;
$txid = is_string($order['tx_hash'] ?? null) ? (string) $order['tx_hash'] : '';
?>
<div class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6 lg:px-8">

    <a href="/account/orders" class="text-sm text-ink-500 hover:text-ink-200">&larr; Orders</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl text-ink-100">Order #<?= (int) $order['id'] ?></h1>
            <p class="mt-1 text-sm text-ink-500"><?= Fmt::e(Fmt::dateTime((string) $order['created_at'])) ?></p>
        </div>
        <span class="badge <?= $status === 'complete' ? 'badge-ok' : ($status === 'failed' ? 'badge-failed' : ($status === 'refunded' ? 'badge-muted' : 'badge-pending')) ?>">
            <?= Fmt::e($status) ?>
        </span>
    </div>

    <div class="card mt-6 flex gap-4 p-5">
        <img src="<?= Fmt::e(View::media($order['preview_path'] ?? null)) ?>" alt=""
             class="h-20 w-20 shrink-0 rounded object-cover" width="80" height="80">
        <div class="min-w-0">
            <h2 class="font-display text-xl text-ink-100"><?= Fmt::e((string) $order['nft_name']) ?></h2>
            <div class="mt-1 text-sm">
                <?= View::partial('partials/ident', [
                    'value' => (string) $order['token_id'], 'head' => 8, 'tail' => 7,
                    'label' => 'inscription id', 'href' => $explorerInscription,
                ]) ?>
            </div>
            <p class="price mt-2 text-lg text-ember-500"><?= Fmt::e(Fmt::money((int) $order['price_minor'])) ?></p>
        </div>
    </div>

    <?php if ($status === 'refunded'): ?>
        <div class="flash flash-info mt-6">
            <p>
                This order was refunded. The credit is on your statement next to the original
                charge &mdash; the ledger keeps both.
            </p>
        </div>
    <?php elseif ($status === 'failed'): ?>
        <div class="flash flash-error mt-6">
            <div>
                <p class="font-medium">This transfer could not be completed.</p>
                <?php if (($order['last_error'] ?? null) !== null): ?>
                    <p class="mt-1 text-sm opacity-90"><?= Fmt::e((string) $order['last_error']) ?></p>
                <?php endif; ?>
                <p class="mt-1 text-sm opacity-90">Contact support &mdash; we will either retry it or refund your balance.</p>
            </div>
        </div>
    <?php else: ?>
        <ol class="card mt-6 divide-y divide-ink-800">
            <?php foreach ($steps as $index => [$key, $label, $description]): ?>
                <?php $done = $index <= $currentStep; $active = $index === $currentStep; ?>
                <li class="flex gap-4 p-4">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs
                        <?= $done ? 'border-mint-600 bg-mint-900 text-mint-400' : 'border-ink-700 text-ink-600' ?>">
                        <?= $done && !$active ? '&check;' : (string) ($index + 1) ?>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-medium <?= $done ? 'text-ink-100' : 'text-ink-500' ?>"><?= Fmt::e($label) ?></p>
                        <p class="text-sm text-ink-500"><?= Fmt::e($description) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <dl class="card mt-6 divide-y divide-ink-800 text-sm">
        <div class="flex items-center justify-between gap-4 p-4">
            <dt class="text-ink-500">Sent to</dt>
            <dd><?= View::partial('partials/ident', [
                    'value' => (string) $order['buyer_wallet_address'],
                    'head' => 10, 'tail' => 8, 'label' => 'payout address',
                ]) ?></dd>
        </div>
        <div class="flex items-center justify-between gap-4 p-4">
            <dt class="text-ink-500">Transaction</dt>
            <dd><?= View::partial('partials/ident', [
                    'value' => $txid, 'head' => 10, 'tail' => 8,
                    'label' => 'transfer transaction', 'href' => $explorerTx,
                ]) ?></dd>
        </div>
        <?php if (($order['completed_at'] ?? null) !== null): ?>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-ink-500">Completed</dt>
                <dd class="text-ink-200"><?= Fmt::e(Fmt::dateTime((string) $order['completed_at'])) ?></dd>
            </div>
        <?php endif; ?>
    </dl>
</div>
