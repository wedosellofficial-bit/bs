<?php
declare(strict_types=1);
use App\AccountActivation;
use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;
$old = $old ?? [];
$minActivation = AccountActivation::minActivationMinor();
?>
<div class="card p-7">
    <h1 class="font-display text-3xl text-ink-100">Create an account</h1>
    <p class="mt-1 text-sm text-ink-400">
        You will need a confirmed email address before you can add funds or buy.
    </p>

    <p class="mt-3 rounded-lg border border-ink-800 bg-ink-900 p-3 text-sm text-ink-300">
        Creating an account is free and unlocks the full catalog. To buy, fund your account with at
        least <span class="price text-ink-100"><?= Fmt::e(Fmt::money($minActivation)) ?></span> in BTC - this is
        not a fee: it becomes your ordinary store balance, spendable on anything, and appears on your statement
        like any other deposit.
    </p>

    <form method="post" action="/register" class="mt-6 space-y-4">
        <?= Auth::csrfField() ?>

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" type="email" id="email" name="email" required
                   value="<?= Fmt::e($old['email'] ?? '') ?>"
                   autocomplete="username" autocapitalize="off" spellcheck="false" autofocus>
        </div>

        <div>
            <label class="label" for="display_name">Display name <span class="text-ink-600">(optional)</span></label>
            <input class="field" type="text" id="display_name" name="display_name" maxlength="60"
                   value="<?= Fmt::e($old['display_name'] ?? '') ?>" autocomplete="nickname">
        </div>

        <div>
            <label class="label" for="password">Password</label>
            <div class="relative">
                <input class="field pr-11" type="password" id="password" name="password" required
                       autocomplete="new-password" minlength="12">
                <?= View::partial('partials/password-toggle', ['for' => 'password']) ?>
            </div>
            <p class="hint mt-1.5">
                At least 12 characters. Length beats symbols &mdash; a few unrelated words
                is stronger than P@ssw0rd and easier to remember.
            </p>
        </div>

        <label class="check-row items-start">
            <input type="checkbox" name="accept_terms" value="1" required class="mt-1">
            <span class="text-ink-400">
                I accept the <a href="/terms" class="text-ember-500 hover:underline" target="_blank">terms of sale</a>
                and understand that inscription transfers are irreversible.
            </span>
        </label>

        <button type="submit" class="btn btn-primary w-full">Create account</button>
    </form>

    <p class="mt-6 border-t border-ink-800 pt-5 text-center text-sm text-ink-400">
        Already have one? <a href="/login" class="text-ember-500 hover:underline">Sign in</a>
    </p>
</div>
