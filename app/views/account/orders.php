<?php
declare(strict_types=1);

/** @var list<array<string,mixed>> $orders */

use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <h1 class="font-display text-4xl text-ink-100">Orders</h1>

    <div class="card mt-6">
        <?php if ($orders === []): ?>
            <div class="px-6 py-16 text-center">
                <p class="text-ink-400">No orders yet.</p>
                <a href="/collection" class="btn btn-primary mt-5">Browse the collection</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Item</th>
                        <th scope="col">Date</th>
                        <th scope="col">Paid</th>
                        <th scope="col">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $status = (string) $order['status'];
                        $badge = match ($status) {
                            'complete' => 'badge-ok',
                            'failed'   => 'badge-failed',
                            'refunded' => 'badge-muted',
                            default    => 'badge-pending',
                        };
                        ?>
                        <tr>
                            <td class="ident">
                                <a href="/account/orders/<?= (int) $order['id'] ?>" class="hover:text-ember-500">
                                    #<?= (int) $order['id'] ?>
                                </a>
                            </td>
                            <td class="max-w-[16rem] truncate text-ink-100"><?= Fmt::e((string) $order['nft_name']) ?></td>
                            <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::date((string) $order['created_at'])) ?></td>
                            <td class="price"><?= Fmt::e(Fmt::money((int) $order['price_minor'])) ?></td>
                            <td><span class="badge <?= Fmt::e($badge) ?>"><?= Fmt::e($status) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
