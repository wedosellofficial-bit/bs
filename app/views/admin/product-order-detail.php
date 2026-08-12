<?php
declare(strict_types=1);

use App\Auth;
use App\Lib\Fmt;

$status = (string) $order['status'];
?>
<a href="/admin/product-orders" class="text-sm text-ink-500 hover:text-ink-200">&larr; Product orders</a>

<div class="mt-4 flex flex-wrap items-start justify-between gap-4">
    <h1 class="font-display text-3xl text-ink-100">Order #<?= (int) $order['id'] ?></h1>
    <span class="badge <?= $status === 'refunded' ? 'badge-muted' : 'badge-ok' ?>"><?= Fmt::e($status) ?></span>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">Details</h2>
        <dl class="mt-3 space-y-2.5 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-ink-500">Product</dt>
                <dd class="text-right text-ink-100"><?= Fmt::e((string) $order['product_name']) ?></dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-ink-500">Buyer</dt>
                <dd class="ident text-ink-200">
                    <a href="/admin/users/<?= (int) ($buyer['id'] ?? 0) ?>" class="hover:text-ember-500">
                        <?= Fmt::e((string) ($buyer['email'] ?? 'unknown')) ?>
                    </a>
                </dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-ink-500">Price</dt>
                <dd class="price text-ink-100"><?= Fmt::e(Fmt::money((int) $order['price_minor'])) ?></dd>
            </div>
        </dl>

        <h2 class="mt-6 text-sm font-semibold text-ink-100">Downloads</h2>
        <ul class="mt-3 divide-y divide-ink-800 text-sm">
            <?php foreach ($downloads as $download): ?>
                <li class="flex items-center justify-between gap-4 py-2">
                    <span class="text-ink-400"><?= Fmt::e(Fmt::dateTime((string) $download['created_at'])) ?></span>
                    <span class="text-ink-200">
                        <?= $download['used_at'] !== null ? 'Used ' . Fmt::e(Fmt::relative((string) $download['used_at'])) : 'Unused' ?>
                    </span>
                </li>
            <?php endforeach; ?>
            <?php if ($downloads === []): ?>
                <li class="py-4 text-center text-ink-500">No download links generated yet.</li>
            <?php endif; ?>
        </ul>
    </section>

    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">Ledger entries</h2>
        <ul class="mt-3 divide-y divide-ink-800 text-sm">
            <?php foreach ($ledger as $entry): ?>
                <?php $amount = (int) $entry['amount_minor']; ?>
                <li class="flex items-center justify-between gap-4 py-2">
                    <span class="text-ink-400"><?= Fmt::e((string) $entry['type']) ?></span>
                    <span class="price <?= $amount >= 0 ? 'text-mint-400' : 'text-ink-200' ?>">
                        <?= Fmt::e(Fmt::moneySigned($amount)) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($status !== 'refunded'): ?>
            <div class="mt-5 border-t border-ink-800 pt-4">
                <h3 class="text-sm font-semibold text-ink-100">Refund</h3>
                <p class="mt-1 text-xs text-ink-500">
                    Writes a compensating credit. The original debit stays on the statement. The buyer keeps
                    the file they already downloaded - there is nothing to relist for a digital good.
                </p>

                <form method="post" action="/admin/product-orders/<?= (int) $order['id'] ?>/refund" class="mt-3 space-y-2">
                    <?= Auth::csrfField() ?>
                    <label class="sr-only-focusable" for="reason">Reason</label>
                    <textarea class="field text-sm" rows="2" id="reason" name="reason" required minlength="5"
                              placeholder="Why is this being refunded?"></textarea>
                    <button class="btn btn-danger btn-sm w-full" type="submit">Refund this order</button>
                </form>
            </div>
        <?php endif; ?>
    </section>
</div>
