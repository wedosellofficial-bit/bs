<?php
declare(strict_types=1);

/**
 * @var array<string,mixed>|null $item
 * @var list<array<string,mixed>> $tags
 * @var list<array<string,mixed>> $categories
 * @var int|null $orderCount
 */

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;

$isEdit = $item !== null;
$action = $isEdit ? '/admin/products/' . (int) $item['id'] : '/admin/products';
$hasOrders = $isEdit && ($orderCount ?? 0) > 0;
?>
<a href="/admin/products" class="text-sm text-ink-500 hover:text-ink-200">&larr; Products</a>

<h1 class="mt-4 font-display text-3xl text-ink-100">
    <?= $isEdit ? 'Edit product' : 'Add product' ?>
</h1>

<?php if ($hasOrders): ?>
    <div class="flash flash-info mt-4">
        <p>This product has <?= (int) $orderCount ?> order(s) against it. Its deliverable can still be replaced,
            but existing buyers already have their download.</p>
    </div>
<?php endif; ?>

<form method="post" action="<?= Fmt::e($action) ?>" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5">
    <?= Auth::csrfField() ?>

    <section class="card space-y-4 p-5">
        <div>
            <label class="label" for="name">Name</label>
            <input class="field" type="text" id="name" name="name" required maxlength="160"
                   value="<?= Fmt::e((string) ($item['name'] ?? '')) ?>">
        </div>

        <div>
            <label class="label" for="description">Description</label>
            <textarea class="field" id="description" name="description" rows="4"><?= Fmt::e((string) ($item['description'] ?? '')) ?></textarea>
        </div>

        <div>
            <label class="label" for="inscription_id">Inscription id (optional)</label>
            <div class="flex gap-2">
                <input class="field field-mono" type="text" id="inscription_id" name="inscription_id"
                       spellcheck="false" placeholder="6fb976ab…2799i0"
                       value="<?= Fmt::e((string) ($item['inscription_id'] ?? '')) ?>">
                <button type="button" class="btn btn-secondary shrink-0" data-generate-inscription="inscription_id">
                    Generate
                </button>
            </div>
            <p class="hint mt-1.5">
                Display metadata only - a sample placeholder, not a real on-chain inscription. The Generate
                button fills in a structurally valid but fake <span class="ident">&lt;64-hex&gt;i0</span> value;
                label it as a sample to buyers if you keep it. Leave empty if this product has none.
            </p>
        </div>
    </section>

    <section class="card space-y-4 p-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="price">Price</label>
                <input class="field field-mono" type="text" id="price" name="price" required inputmode="decimal"
                       value="<?= $isEdit ? Fmt::e(Fmt::money((int) $item['price_minor'], false)) : '' ?>">
            </div>

            <div>
                <label class="label" for="status">Status</label>
                <select class="field" id="status" name="status">
                    <?php foreach (['listed', 'hidden'] as $option): ?>
                        <option value="<?= Fmt::e($option) ?>" <?= ($item['status'] ?? 'listed') === $option ? 'selected' : '' ?>>
                            <?= Fmt::e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <label class="label" for="category_id">Category</label>
            <select class="field" id="category_id" name="category_id">
                <option value="0">None</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"
                        <?= (int) ($item['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= Fmt::e((string) $category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint mt-1.5">Manage categories on the
                <a href="/admin/products/categories" class="underline">Categories &amp; tags</a> page.</p>
        </div>

        <div>
            <label class="label" for="tags">Tags</label>
            <input class="field" type="text" id="tags" name="tags" placeholder="comma, separated, tags"
                   value="<?= Fmt::e(implode(', ', array_map(static fn (array $t): string => (string) $t['name'], $tags))) ?>">
            <p class="hint mt-1.5">Comma-separated. New tags are created automatically.</p>
        </div>
    </section>

    <section class="card space-y-4 p-5">
        <div>
            <label class="label" for="image">Preview image</label>

            <?php if ($isEdit && ($item['preview_path'] ?? null) !== null): ?>
                <img src="<?= Fmt::e(View::productMedia($item['preview_path'])) ?>" alt=""
                     class="mb-3 h-28 w-28 rounded object-cover" width="112" height="112">
            <?php endif; ?>

            <input class="field" type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp"
                   <?= $isEdit ? '' : 'required' ?>>
            <p class="hint mt-1.5">
                JPEG, PNG, GIF or WebP, up to 16&nbsp;MB. Re-encoded to strip metadata and stored outside the web root.
                <?= $isEdit ? 'Leave empty to keep the current image.' : '' ?>
            </p>
        </div>

        <div>
            <label class="label" for="deliverable">Deliverable file</label>
            <input class="field" type="file" id="deliverable" name="deliverable"
                   <?= $isEdit ? '' : 'required' ?>>
            <p class="hint mt-1.5">
                The file the buyer actually receives - image, PDF, PSD or ZIP, up to 100&nbsp;MB. Never linked
                directly; served only through the authenticated download flow after purchase.
                <?php if ($isEdit && ($item['deliverable_original_name'] ?? null) !== null): ?>
                    Current file: <span class="ident"><?= Fmt::e((string) $item['deliverable_original_name']) ?></span>.
                    Leave empty to keep it.
                <?php endif; ?>
            </p>
        </div>
    </section>

    <div class="flex flex-wrap gap-3">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Add product' ?></button>
        <a href="/admin/products" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<?php if ($isEdit && !$hasOrders): ?>
    <form method="post" action="/admin/products/<?= (int) $item['id'] ?>/delete"
          class="mt-8 max-w-2xl border-t border-ink-800 pt-6"
          onsubmit="return confirm('Delete this product? This cannot be undone.');">
        <?= Auth::csrfField() ?>
        <button class="btn btn-danger btn-sm" type="submit">Delete product</button>
        <p class="hint mt-2">Only possible while no order references it.</p>
    </form>
<?php endif; ?>
