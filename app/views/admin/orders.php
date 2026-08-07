<?php
declare(strict_types=1);
use App\Lib\Fmt;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="font-display text-3xl text-ink-100">Orders</h1>
        <p class="mt-1 text-sm text-ink-500">
            <?= Fmt::e(Fmt::money((int) $stats['revenue_minor'])) ?> revenue
            across <?= Fmt::e(number_format((int) $stats['total'])) ?> orders.
        </p>
    </div>

    <nav class="flex flex-wrap gap-1" aria-label="Order filter">
        <?php foreach (['all', 'paid', 'queued', 'transferring', 'complete', 'failed', 'refunded'] as $value): ?>
            <a href="/admin/orders?status=<?= Fmt::e($value) ?>"
               class="rounded-md px-2.5 py-1.5 text-sm <?= $status === $value ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:text-ink-100' ?>">
                <?= Fmt::e($value) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>

<div class="card mt-6">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr><th scope="col">#</th><th scope="col">Item</th><th scope="col">Buyer</th>
                <th scope="col">Price</th><th scope="col">Date</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <?php $s = (string) $order['status']; ?>
                <tr>
                    <td class="ident">
                        <a href="/admin/orders/<?= (int) $order['id'] ?>" class="hover:text-ember-500">#<?= (int) $order['id'] ?></a>
                    </td>
                    <td class="max-w-[14rem] truncate text-ink-100"><?= Fmt::e((string) $order['nft_name']) ?></td>
                    <td class="ident max-w-[14rem] truncate text-ink-300"><?= Fmt::e((string) $order['buyer_email']) ?></td>
                    <td class="price"><?= Fmt::e(Fmt::money((int) $order['price_minor'])) ?></td>
                    <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::date((string) $order['created_at'])) ?></td>
                    <td>
                        <span class="badge <?= $s === 'complete' ? 'badge-ok' : ($s === 'failed' ? 'badge-failed' : ($s === 'refunded' ? 'badge-muted' : 'badge-pending')) ?>">
                            <?= Fmt::e($s) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($orders === []): ?>
                <tr><td colspan="6" class="py-12 text-center text-ink-500">No orders in this view.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
