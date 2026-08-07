<?php
declare(strict_types=1);
use App\Auth;
?>
<div class="card p-7">
    <?php if (($expired ?? false) === true): ?>
        <h1 class="font-display text-3xl text-ink-100">That link has expired</h1>
        <p class="mt-2 text-sm text-ink-400">
            Confirmation links last 24 hours and can only be used once. Enter your address
            below and we will send a fresh one.
        </p>
    <?php elseif (($required ?? false) === true): ?>
        <h1 class="font-display text-3xl text-ink-100">Confirm your email first</h1>
        <p class="mt-2 text-sm text-ink-400">
            Adding funds and buying both need a confirmed address. Check your inbox, or
            request another link below.
        </p>
    <?php else: ?>
        <h1 class="font-display text-3xl text-ink-100">Check your inbox</h1>
        <p class="mt-2 text-sm text-ink-400">
            If that address needs confirming, a link is on its way. It expires in 24 hours.
            Check spam before requesting another.
        </p>
    <?php endif; ?>

    <form method="post" action="/verify-email/resend" class="mt-6 space-y-4">
        <?= Auth::csrfField() ?>

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" type="email" id="email" name="email" required
                   autocomplete="username" autocapitalize="off" spellcheck="false">
        </div>

        <button type="submit" class="btn btn-secondary w-full">Send another link</button>
    </form>

    <p class="mt-6 border-t border-ink-800 pt-5 text-center text-sm">
        <a href="/login" class="text-ember-500 hover:underline">Back to sign in</a>
    </p>
</div>
