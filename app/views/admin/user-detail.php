<?php
declare(strict_types=1);

use App\Auth;
use App\Lib\Fmt;
?>
<a href="/admin/users" class="text-sm text-ink-500 hover:text-ink-200">&larr; Users</a>

<div class="mt-4 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="ident font-display text-2xl text-ink-100"><?= Fmt::e((string) $user['email']) ?></h1>
        <p class="mt-1 text-sm text-ink-500">
            Joined <?= Fmt::e(Fmt::date((string) $user['created_at'])) ?>
            <?php if ($user['last_login_at'] !== null): ?>
                &middot; last seen <?= Fmt::e(Fmt::relative((string) $user['last_login_at'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="text-right">
        <p class="text-xs uppercase tracking-wider text-ink-500">Balance</p>
        <p class="price text-3xl text-ink-100"><?= Fmt::e(Fmt::money($balance)) ?></p>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">

    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">Account status</h2>
        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/status" class="mt-3 flex flex-wrap gap-2">
            <?= Auth::csrfField() ?>
            <label class="sr-only-focusable" for="status">Status</label>
            <select class="field w-auto text-sm" id="status" name="status">
                <?php foreach (['active', 'suspended', 'closed'] as $option): ?>
                    <option value="<?= Fmt::e($option) ?>" <?= $user['status'] === $option ? 'selected' : '' ?>>
                        <?= Fmt::e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-secondary btn-sm" type="submit">Update</button>
        </form>
    </section>

    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">Manual adjustment</h2>
        <p class="mt-1 text-xs text-ink-500">
            Written as a ledger entry attributed to you. There is no balance field to edit.
        </p>

        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/credit" class="mt-3 space-y-2">
            <?= Auth::csrfField() ?>

            <div class="flex gap-2">
                <label class="sr-only-focusable" for="direction">Direction</label>
                <select class="field w-auto text-sm" id="direction" name="direction">
                    <option value="credit">Credit</option>
                    <option value="debit">Debit</option>
                </select>

                <label class="sr-only-focusable" for="amount">Amount</label>
                <input class="field field-mono flex-1" type="text" id="amount" name="amount"
                       placeholder="25.00" inputmode="decimal" required>
            </div>

            <label class="sr-only-focusable" for="reason">Reason</label>
            <input class="field text-sm" type="text" id="reason" name="reason" required minlength="5"
                   placeholder="Reason (shown on their statement)">

            <button class="btn btn-secondary btn-sm w-full" type="submit">Apply adjustment</button>
        </form>
    </section>
</div>

<section class="card mt-6">
    <h2 class="border-b border-ink-800 px-5 py-3.5 text-sm font-semibold text-ink-100">Ledger</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Type</th><th scope="col" class="text-right">Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($statement as $entry): ?>
                <?php $amount = (int) $entry['amount_minor']; ?>
                <tr>
                    <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::dateTime((string) $entry['created_at'])) ?></td>
                    <td><?= Fmt::e((string) ($entry['memo'] ?? Fmt::EM_DASH)) ?></td>
                    <td><span class="badge badge-muted"><?= Fmt::e((string) $entry['type']) ?></span></td>
                    <td class="price text-right <?= $amount >= 0 ? 'text-mint-400' : 'text-ink-200' ?>">
                        <?= Fmt::e(Fmt::moneySigned($amount)) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($statement === []): ?>
                <tr><td colspan="4" class="py-8 text-center text-ink-500">No ledger entries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card mt-6">
    <h2 class="border-b border-ink-800 px-5 py-3.5 text-sm font-semibold text-ink-100">Activity</h2>
    <ul class="divide-y divide-ink-800">
        <?php foreach ($audit as $entry): ?>
            <li class="px-5 py-2.5">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="ident text-xs text-ember-500"><?= Fmt::e((string) $entry['event']) ?></span>
                    <span class="text-xs text-ink-600"><?= Fmt::e(Fmt::relative((string) $entry['created_at'])) ?></span>
                </div>
                <p class="mt-0.5 text-sm text-ink-300"><?= Fmt::e((string) $entry['message']) ?></p>
            </li>
        <?php endforeach; ?>
        <?php if ($audit === []): ?>
            <li class="px-5 py-8 text-center text-sm text-ink-500">No recorded activity.</li>
        <?php endif; ?>
    </ul>
</section>
