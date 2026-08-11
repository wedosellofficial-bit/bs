<?php
declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $tags
 */

use App\Auth;
use App\Lib\Fmt;
?>
<h1 class="font-display text-3xl text-ink-100">Categories &amp; tags</h1>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">Categories</h2>
        <p class="mt-1 text-xs text-ink-500">
            Powers the Collections browser. Flag a category members-only to lock it to non-members with a
            "join to access" prompt.
        </p>

        <ul class="mt-4 divide-y divide-ink-800">
            <?php foreach ($categories as $category): ?>
                <li class="flex items-center justify-between gap-3 py-2.5">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-ink-100"><?= Fmt::e((string) $category['name']) ?></p>
                        <p class="text-xs text-ink-500">
                            <?= (int) $category['item_count'] ?> product(s)
                            <?= ((bool) $category['is_members_only']) ? ' &middot; members-only' : '' ?>
                        </p>
                    </div>
                    <form method="post" action="/admin/products/categories/<?= (int) $category['id'] ?>/delete"
                          onsubmit="return confirm('Delete this category? Its products become uncategorised.');">
                        <?= Auth::csrfField() ?>
                        <button class="btn btn-ghost btn-sm text-rose-400" type="submit">Delete</button>
                    </form>
                </li>
            <?php endforeach; ?>
            <?php if ($categories === []): ?>
                <li class="py-6 text-center text-sm text-ink-500">No categories yet.</li>
            <?php endif; ?>
        </ul>

        <form method="post" action="/admin/products/categories" class="mt-5 space-y-2 border-t border-ink-800 pt-4">
            <?= Auth::csrfField() ?>
            <label class="sr-only-focusable" for="cat-name">Name</label>
            <input class="field text-sm" type="text" id="cat-name" name="name" required maxlength="120"
                   placeholder="Category name">
            <label class="check-row">
                <input type="checkbox" name="is_members_only" value="1">
                <span>Members-only</span>
            </label>
            <button class="btn btn-secondary btn-sm w-full" type="submit">Add category</button>
        </form>
    </section>

    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">Tags</h2>
        <p class="mt-1 text-xs text-ink-500">
            Created automatically from the product form's tags field. Delete an unused one here to tidy the list.
        </p>

        <ul class="mt-4 divide-y divide-ink-800">
            <?php foreach ($tags as $tag): ?>
                <li class="flex items-center justify-between gap-3 py-2.5">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-ink-100"><?= Fmt::e((string) $tag['name']) ?></p>
                        <p class="text-xs text-ink-500"><?= (int) $tag['item_count'] ?> product(s)</p>
                    </div>
                    <form method="post" action="/admin/products/tags/<?= (int) $tag['id'] ?>/delete"
                          onsubmit="return confirm('Delete this tag?');">
                        <?= Auth::csrfField() ?>
                        <button class="btn btn-ghost btn-sm text-rose-400" type="submit">Delete</button>
                    </form>
                </li>
            <?php endforeach; ?>
            <?php if ($tags === []): ?>
                <li class="py-6 text-center text-sm text-ink-500">No tags yet.</li>
            <?php endif; ?>
        </ul>
    </section>
</div>
