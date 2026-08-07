<?php
declare(strict_types=1);

/**
 * The manual send queue.
 *
 * @var list<array<string,mixed>> $items
 * @var array<string,int> $counts
 * @var string $filter
 */

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;
use App\Ordinals;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="font-display text-3xl text-ink-100">Transfer queue</h1>
        <p class="mt-1 text-sm text-ink-500">
            Send each inscription from the project wallet, then record the transaction id here.
            No signing key exists on this server.
        </p>
    </div>

    <nav class="flex gap-1" aria-label="Queue filter">
        <?php foreach (['open' => 'Open', 'complete' => 'Complete', 'failed' => 'Failed', 'all' => 'All'] as $value => $label): ?>
            <a href="/admin/transfers?status=<?= Fmt::e($value) ?>"
               class="rounded-md px-3 py-1.5 text-sm <?= $filter === $value ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:text-ink-100' ?>">
                <?= Fmt::e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if ($items === []): ?>
    <div class="card mt-6 px-6 py-16 text-center">
        <p class="text-ink-400">Nothing in this view.</p>
    </div>
<?php else: ?>
    <div class="mt-6 space-y-4">
        <?php foreach ($items as $item): ?>
            <?php
            $status = (string) $item['status'];
            $badge = match ($status) {
                'pending'  => 'badge-pending',
                'claimed'  => 'badge-listed',
                'sent'     => 'badge-listed',
                'complete' => 'badge-ok',
                default    => 'badge-failed',
            };
            $txid = is_string($item['tx_hash'] ?? null) ? (string) $item['tx_hash'] : '';
            ?>
            <article class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge <?= Fmt::e($badge) ?>"><?= Fmt::e($status) ?></span>
                            <a href="/admin/orders/<?= (int) $item['order_id'] ?>" class="ident text-ember-500 hover:underline">
                                order #<?= (int) $item['order_id'] ?>
                            </a>
                            <span class="text-xs text-ink-600"><?= Fmt::e(Fmt::relative((string) $item['ordered_at'])) ?></span>
                        </div>

                        <h2 class="mt-2 font-display text-xl text-ink-100"><?= Fmt::e((string) $item['nft_name']) ?></h2>

                        <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-ink-500">Inscription</dt>
                                <dd><?= View::partial('partials/ident', [
                                        'value' => (string) $item['token_id'], 'head' => 6, 'tail' => 6,
                                        'label' => 'inscription id',
                                        'href' => Ordinals::explorerInscriptionUrl((string) $item['token_id']),
                                    ]) ?></dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-ink-500">Send to</dt>
                                <dd><?= View::partial('partials/ident', [
                                        'value' => (string) $item['buyer_wallet_address'], 'head' => 10, 'tail' => 8,
                                        'label' => 'buyer payout address',
                                    ]) ?></dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-ink-500">Buyer</dt>
                                <dd class="ident truncate text-ink-300"><?= Fmt::e((string) $item['buyer_email']) ?></dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-ink-500">Paid</dt>
                                <dd class="price text-ink-200"><?= Fmt::e(Fmt::money((int) $item['price_minor'])) ?></dd>
                            </div>
                        </dl>

                        <?php if (($item['last_error'] ?? null) !== null): ?>
                            <p class="mt-3 rounded-md border border-rose-600 bg-rose-900/30 px-3 py-2 text-sm text-rose-400">
                                <?= Fmt::e((string) $item['last_error']) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (($item['claimed_by_email'] ?? null) !== null && $status === 'claimed'): ?>
                            <p class="mt-2 text-xs text-ink-500">
                                Claimed by <?= Fmt::e((string) $item['claimed_by_email']) ?>,
                                <?= Fmt::e(Fmt::relative((string) $item['claimed_at'])) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php // ---- actions ---- ?>
                    <div class="flex w-full shrink-0 flex-col gap-2 sm:w-64">
                        <?php if ($status === 'pending'): ?>
                            <form method="post" action="/admin/transfers/<?= (int) $item['id'] ?>/claim">
                                <?= Auth::csrfField() ?>
                                <button class="btn btn-primary btn-sm w-full" type="submit">Claim to send</button>
                            </form>
                            <p class="hint text-xs">
                                Claiming stops another admin sending the same item at the same time.
                            </p>

                        <?php elseif ($status === 'claimed'): ?>
                            <form method="post" action="/admin/transfers/<?= (int) $item['id'] ?>/sent" class="space-y-2">
                                <?= Auth::csrfField() ?>
                                <label class="sr-only-focusable" for="txid-<?= (int) $item['id'] ?>">Transaction id</label>
                                <input class="field field-mono text-xs" type="text"
                                       id="txid-<?= (int) $item['id'] ?>" name="txid"
                                       placeholder="64-character txid" required
                                       spellcheck="false" pattern="[0-9a-fA-F]{64}">
                                <button class="btn btn-primary btn-sm w-full" type="submit">Record as sent</button>
                            </form>

                            <form method="post" action="/admin/transfers/<?= (int) $item['id'] ?>/release">
                                <?= Auth::csrfField() ?>
                                <button class="btn btn-ghost btn-sm w-full" type="submit">Release claim</button>
                            </form>

                        <?php elseif ($status === 'sent'): ?>
                            <?php if ($txid !== ''): ?>
                                <div class="text-right text-sm">
                                    <?= View::partial('partials/ident', [
                                        'value' => $txid, 'head' => 8, 'tail' => 6,
                                        'label' => 'transaction id', 'href' => Ordinals::explorerTxUrl($txid),
                                    ]) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="/admin/transfers/<?= (int) $item['id'] ?>/complete">
                                <?= Auth::csrfField() ?>
                                <button class="btn btn-primary btn-sm w-full" type="submit">Mark confirmed</button>
                            </form>
                            <p class="hint text-xs">Emails the buyer and closes the order.</p>
                        <?php endif; ?>

                        <?php if ($status !== 'complete'): ?>
                            <details class="mt-1">
                                <summary class="cursor-pointer text-xs text-ink-600 hover:text-rose-400">Report a problem</summary>
                                <form method="post" action="/admin/transfers/<?= (int) $item['id'] ?>/failed" class="mt-2 space-y-2">
                                    <?= Auth::csrfField() ?>
                                    <label class="sr-only-focusable" for="reason-<?= (int) $item['id'] ?>">Reason</label>
                                    <textarea class="field text-xs" rows="2" required
                                              id="reason-<?= (int) $item['id'] ?>" name="reason"
                                              placeholder="What went wrong?"></textarea>
                                    <button class="btn btn-danger btn-sm w-full" type="submit">Mark failed</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
