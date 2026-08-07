<?php
declare(strict_types=1);

/** @var array<string,mixed>|null $item @var list<array<string,mixed>> $collections */

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;

$isEdit = $item !== null;
$action = $isEdit ? '/admin/inventory/' . (int) $item['id'] : '/admin/inventory';
?>
<a href="/admin/inventory" class="text-sm text-ink-500 hover:text-ink-200">&larr; Inventory</a>

<h1 class="mt-4 font-display text-3xl text-ink-100">
    <?= $isEdit ? 'Edit inscription' : 'Add inscription' ?>
</h1>

<?php if ($isEdit && ($order ?? null) !== null): ?>
    <div class="flash flash-info mt-4">
        <p>
            Sold to <span class="ident"><?= Fmt::e((string) $order['email']) ?></span> on
            <a href="/admin/orders/<?= (int) $order['id'] ?>" class="underline">order #<?= (int) $order['id'] ?></a>.
            The price is part of that order and can no longer be changed.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= Fmt::e($action) ?>" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5">
    <?= Auth::csrfField() ?>

    <section class="card space-y-4 p-5">
        <div>
            <label class="label" for="token_id">Inscription id</label>
            <input class="field field-mono" type="text" id="token_id" name="token_id" required
                   spellcheck="false" placeholder="6fb976ab…2799i0"
                   value="<?= Fmt::e((string) ($item['token_id'] ?? '')) ?>">
            <p class="hint mt-1.5">The 64-character reveal txid, then <span class="ident">i</span>, then the index.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="inscription_number">Inscription number</label>
                <input class="field field-mono" type="text" id="inscription_number" name="inscription_number"
                       inputmode="numeric" value="<?= Fmt::e((string) ($item['inscription_number'] ?? '')) ?>">
            </div>
            <div>
                <label class="label" for="sat_ordinal">Sat ordinal</label>
                <input class="field field-mono" type="text" id="sat_ordinal" name="sat_ordinal"
                       inputmode="numeric" value="<?= Fmt::e((string) ($item['sat_ordinal'] ?? '')) ?>">
            </div>
        </div>

        <div>
            <label class="label" for="name">Name</label>
            <input class="field" type="text" id="name" name="name" required maxlength="160"
                   value="<?= Fmt::e((string) ($item['name'] ?? '')) ?>">
        </div>

        <div>
            <label class="label" for="description">Description</label>
            <textarea class="field" id="description" name="description" rows="4"><?= Fmt::e((string) ($item['description'] ?? '')) ?></textarea>
        </div>
    </section>

    <section class="card space-y-4 p-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="price">Price</label>
                <input class="field field-mono" type="text" id="price" name="price" required inputmode="decimal"
                       value="<?= $isEdit ? Fmt::e(Fmt::money((int) $item['price_minor'], false)) : '' ?>">
            </div>

            <div>
                <label class="label" for="status">Status</label>
                <select class="field" id="status" name="status">
                    <?php foreach (['listed', 'reserved', 'sold', 'transferred'] as $option): ?>
                        <option value="<?= Fmt::e($option) ?>" <?= ($item['status'] ?? 'listed') === $option ? 'selected' : '' ?>>
                            <?= Fmt::e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="label" for="collection_id">Collection</label>
                <select class="field" id="collection_id" name="collection_id">
                    <option value="0">None</option>
                    <?php foreach ($collections as $collection): ?>
                        <option value="<?= (int) $collection['id'] ?>"
                            <?= (int) ($item['collection_id'] ?? 0) === (int) $collection['id'] ? 'selected' : '' ?>>
                            <?= Fmt::e((string) $collection['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <label class="label" for="attributes">Attributes (JSON)</label>
            <textarea class="field field-mono" id="attributes" name="attributes" rows="6"
                      spellcheck="false"
                      placeholder='[{"trait_type":"Background","value":"Gold"}]'><?= Fmt::e((string) ($item['attributes'] ?? '')) ?></textarea>
            <p class="hint mt-1.5">
                Either a list of <span class="ident">{"trait_type","value"}</span> objects or a flat
                <span class="ident">{"Trait":"Value"}</span> map. Validated before saving, and
                mirrored into the filter index automatically.
            </p>
        </div>
    </section>

    <section class="card p-5">
        <label class="label" for="image">Image</label>

        <?php if ($isEdit && ($item['preview_path'] ?? null) !== null): ?>
            <img src="<?= Fmt::e(View::media($item['preview_path'])) ?>" alt=""
                 class="mb-3 h-28 w-28 rounded object-cover" width="112" height="112">
        <?php endif; ?>

        <input class="field" type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
        <p class="hint mt-1.5">
            JPEG, PNG, GIF or WebP, up to 16&nbsp;MB. Checked by magic bytes rather than by
            filename, re-encoded through GD to strip metadata, and stored outside the web root.
            <?= $isEdit ? 'Leave empty to keep the current image.' : '' ?>
        </p>
    </section>

    <div class="flex flex-wrap gap-3">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Add to catalogue' ?></button>
        <a href="/admin/inventory" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<?php if ($isEdit && ($order ?? null) === null): ?>
    <form method="post" action="/admin/inventory/<?= (int) $item['id'] ?>/delete"
          class="mt-8 max-w-2xl border-t border-ink-800 pt-6"
          onsubmit="return confirm('Delete this inscription from the catalogue? This cannot be undone.');">
        <?= Auth::csrfField() ?>
        <button class="btn btn-danger btn-sm" type="submit">Delete from catalogue</button>
        <p class="hint mt-2">Only possible while no order references it.</p>
    </form>
<?php endif; ?>
