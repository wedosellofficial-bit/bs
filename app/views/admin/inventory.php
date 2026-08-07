<?php
declare(strict_types=1);

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <h1 class="font-display text-3xl text-ink-100">Inventory</h1>

    <div class="flex flex-wrap gap-2">
        <form method="get" action="/admin/inventory" class="flex gap-2">
            <label class="sr-only-focusable" for="q">Search</label>
            <input class="field w-48 text-sm" type="search" id="q" name="q" placeholder="Name or id"
                   value="<?= Fmt::e($search) ?>">
            <button class="btn btn-secondary btn-sm" type="submit">Search</button>
        </form>

        <form method="post" action="/admin/inventory/rarity">
            <?= Auth::csrfField() ?>
            <button class="btn btn-secondary btn-sm" type="submit"
                    title="Recompute rarity scores from trait frequency">Recompute rarity</button>
        </form>

        <a href="/admin/inventory/new" class="btn btn-primary btn-sm">Add inscription</a>
    </div>
</div>

<nav class="mt-4 flex flex-wrap gap-1" aria-label="Inventory filter">
    <?php foreach (['all', 'listed', 'reserved', 'sold', 'transferred'] as $value): ?>
        <a href="/admin/inventory?status=<?= Fmt::e($value) ?>"
           class="rounded-md px-2.5 py-1.5 text-sm <?= $status === $value ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:text-ink-100' ?>">
            <?= Fmt::e($value) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="card mt-4">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr><th scope="col"></th><th scope="col">Name</th><th scope="col">Inscription</th>
                <th scope="col">Collection</th><th scope="col">Price</th><th scope="col">Rarity</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php $s = (string) $item['status']; ?>
                <tr>
                    <td class="w-12">
                        <img src="<?= Fmt::e(View::media($item['preview_path'] ?? null)) ?>" alt=""
                             class="h-10 w-10 rounded object-cover" width="40" height="40" loading="lazy">
                    </td>
                    <td class="max-w-[16rem] truncate">
                        <a href="/admin/inventory/<?= (int) $item['id'] ?>" class="text-ink-100 hover:text-ember-500">
                            <?= Fmt::e((string) $item['name']) ?>
                        </a>
                    </td>
                    <td><?= View::partial('partials/ident', [
                            'value' => (string) $item['token_id'], 'head' => 6, 'tail' => 5,
                            'label' => 'inscription id',
                        ]) ?></td>
                    <td class="text-ink-400"><?= Fmt::e((string) ($item['collection_name'] ?? Fmt::EM_DASH)) ?></td>
                    <td class="price"><?= Fmt::e(Fmt::money((int) $item['price_minor'])) ?></td>
                    <td class="price text-ink-400"><?= Fmt::e(number_format(((int) $item['rarity_score']) / 100, 1)) ?></td>
                    <td>
                        <span class="badge <?= $s === 'listed' ? 'badge-listed' : ($s === 'transferred' ? 'badge-ok' : 'badge-muted') ?>">
                            <?= Fmt::e($s) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="7" class="py-12 text-center text-ink-500">Nothing here yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
