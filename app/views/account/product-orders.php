<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $orders */

use App\AccountActivation;
use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;

$isActive = AccountActivation::isActive(Auth::user());
?>
<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <h1 class="font-display text-4xl text-ink-100">Orders</h1>

    <div class="card mt-6">
        <?php if ($orders === []): ?>
            <div class="px-6 py-16 text-center">
                <p class="text-ink-400">No orders yet.</p>
                <?php if ($isActive): ?>
                    <a href="/shop" class="btn btn-primary mt-5">Browse Collections</a>
                <?php else: ?>
                    <a href="/account/wallet" class="btn btn-primary mt-5">Fund your wallet to browse</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th scope="col"></th>
                        <th scope="col">Product</th>
                        <th scope="col">Date</th>
                        <th scope="col">Paid</th>
                        <th scope="col">Status</th>
                        <th scope="col"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $badge = $order['status'] === 'refunded' ? 'badge-muted' : 'badge-ok'; ?>
                        <tr>
                            <td>
                                <img src="<?= Fmt::e(View::productMedia($order['preview_path'] ?? null)) ?>" alt=""
                                     class="h-10 w-10 shrink-0 rounded object-cover" width="40" height="40">
                            </td>
                            <td class="max-w-[16rem] truncate text-ink-100"><?= Fmt::e((string) $order['product_name']) ?></td>
                            <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::date((string) $order['created_at'])) ?></td>
                            <td class="price"><?= Fmt::e(Fmt::money((int) $order['price_minor'])) ?></td>
                            <td><span class="badge <?= Fmt::e($badge) ?>"><?= Fmt::e((string) $order['status']) ?></span></td>
                            <td>
                                <?php if ($order['status'] === 'paid'): ?>
                                    <form method="post" action="/account/product-orders/<?= (int) $order['id'] ?>/download">
                                        <?= Auth::csrfField() ?>
                                        <button type="submit" class="btn btn-secondary btn-sm">Download</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
