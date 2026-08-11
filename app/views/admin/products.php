<?php
declare(strict_types=1);

use App\Lib\Fmt;
use App\Lib\View;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <h1 class="font-display text-3xl text-ink-100">Products</h1>

    <div class="flex flex-wrap gap-2">
        <form method="get" action="/admin/products" class="flex gap-2">
            <label class="sr-only-focusable" for="q">Search</label>
            <input class="field w-48 text-sm" type="search" id="q" name="q" placeholder="Name"
                   value="<?= Fmt::e($search) ?>">
            <button class="btn btn-secondary btn-sm" type="submit">Search</button>
        </form>

        <a href="/admin/products/new" class="btn btn-primary btn-sm">Add product</a>
    </div>
</div>

<nav class="mt-4 flex flex-wrap gap-1" aria-label="Product filter">
    <?php foreach (['all', 'listed', 'hidden'] as $value): ?>
        <a href="/admin/products?status=<?= Fmt::e($value) ?>"
           class="rounded-md px-2.5 py-1.5 text-sm <?= $status === $value ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:text-ink-100' ?>">
            <?= Fmt::e($value) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="card mt-4">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr><th scope="col"></th><th scope="col">Name</th><th scope="col">Category</th>
                <th scope="col">Price</th><th scope="col">Deliverable</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php $s = (string) $item['status']; ?>
                <tr>
                    <td class="w-12">
                        <img src="<?= Fmt::e(View::productMedia($item['preview_path'] ?? null)) ?>" alt=""
                             class="h-10 w-10 rounded object-cover" width="40" height="40" loading="lazy">
                    </td>
                    <td class="max-w-[16rem] truncate">
                        <a href="/admin/products/<?= (int) $item['id'] ?>" class="text-ink-100 hover:text-ember-500">
                            <?= Fmt::e((string) $item['name']) ?>
                        </a>
                    </td>
                    <td class="text-ink-400"><?= Fmt::e((string) ($item['category_name'] ?? Fmt::EM_DASH)) ?></td>
                    <td class="price"><?= Fmt::e(Fmt::money((int) $item['price_minor'])) ?></td>
                    <td>
                        <?php if (($item['deliverable_path'] ?? null) !== null): ?>
                            <span class="badge badge-ok">Attached</span>
                        <?php else: ?>
                            <span class="badge badge-failed">Missing</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $s === 'listed' ? 'badge-listed' : 'badge-muted' ?>"><?= Fmt::e($s) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="6" class="py-12 text-center text-ink-500">Nothing here yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
