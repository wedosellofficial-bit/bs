<?php
declare(strict_types=1);

/** @var array<string,mixed> $user @var bool $hasTwoFactor */

use App\Auth;
use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 lg:px-8">

    <h1 class="font-display text-4xl text-ink-100">Settings</h1>

    <div class="mt-8 space-y-6">

        <section class="card p-6">
            <h2 class="font-display text-xl text-ink-100">Account</h2>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-500">Email</dt>
                    <dd class="ident text-ink-200"><?= Fmt::e((string) $user['email']) ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-500">Confirmed</dt>
                    <dd>
                        <?php if ($user['email_verified_at'] !== null): ?>
                            <span class="badge badge-ok">Confirmed</span>
                        <?php else: ?>
                            <a href="/verify-email" class="badge badge-pending">Not confirmed</a>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-500">Member since</dt>
                    <dd class="text-ink-300"><?= Fmt::e(Fmt::date((string) $user['created_at'])) ?></dd>
                </div>
            </dl>

            <form method="post" action="/account/settings/profile" class="mt-5 border-t border-ink-800 pt-5">
                <?= Auth::csrfField() ?>
                <label class="label" for="display_name">Display name</label>
                <div class="flex gap-3">
                    <input class="field" type="text" id="display_name" name="display_name" maxlength="60"
                           value="<?= Fmt::e((string) ($user['display_name'] ?? '')) ?>">
                    <button class="btn btn-secondary shrink-0" type="submit">Save</button>
                </div>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="font-display text-xl text-ink-100">Default payout address</h2>
            <p class="mt-1 text-sm text-ink-400">
                Saved for convenience only. You will still be shown it and asked to confirm it on
                every purchase &mdash; a transfer cannot be undone, so it is never used silently.
            </p>

            <form method="post" action="/account/settings/payout-address" class="mt-4">
                <?= Auth::csrfField() ?>
                <label class="label sr-only-focusable" for="default_payout_address">Taproot address</label>
                <input class="field field-mono" type="text" id="default_payout_address"
                       name="default_payout_address" placeholder="bc1p..."
                       spellcheck="false" autocapitalize="off" autocorrect="off"
                       value="<?= Fmt::e((string) ($user['default_payout_address'] ?? '')) ?>">
                <p class="hint mt-1.5">Taproot only (bc1p…). Leave blank to remove.</p>
                <button class="btn btn-secondary mt-3" type="submit">Save address</button>
            </form>
        </section>

        <section class="card p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-display text-xl text-ink-100">Two-step verification</h2>
                    <p class="mt-1 text-sm text-ink-400">
                        A code from your phone in addition to your password.
                    </p>
                </div>
                <span class="badge <?= $hasTwoFactor ? 'badge-ok' : 'badge-muted' ?> shrink-0">
                    <?= $hasTwoFactor ? 'On' : 'Off' ?>
                </span>
            </div>

            <a href="/account/settings/2fa" class="btn <?= $hasTwoFactor ? 'btn-secondary' : 'btn-primary' ?> mt-4">
                <?= $hasTwoFactor ? 'Manage' : 'Turn on' ?>
            </a>
        </section>

        <section class="card p-6">
            <h2 class="font-display text-xl text-ink-100">Change password</h2>

            <form method="post" action="/account/settings/password" class="mt-4 space-y-4">
                <?= Auth::csrfField() ?>

                <div>
                    <label class="label" for="current_password">Current password</label>
                    <input class="field" type="password" id="current_password" name="current_password"
                           required autocomplete="current-password">
                </div>

                <div>
                    <label class="label" for="new_password">New password</label>
                    <input class="field" type="password" id="new_password" name="new_password"
                           required autocomplete="new-password" minlength="12">
                </div>

                <div>
                    <label class="label" for="new_password_confirm">Confirm new password</label>
                    <input class="field" type="password" id="new_password_confirm" name="new_password_confirm"
                           required autocomplete="new-password" minlength="12">
                </div>

                <button class="btn btn-secondary" type="submit">Update password</button>
            </form>
        </section>
    </div>
</div>
