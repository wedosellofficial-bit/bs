<?php

declare(strict_types=1);

/**
 * Guest signup nudge. A native <dialog>, not a hand-rolled modal: opened
 * with showModal(), the browser traps focus inside it, closes it on Esc,
 * and returns focus to whatever triggered it - all without any JS here
 * beyond deciding *when* to open it. See the "Signup popup" section of
 * assets/js/app.js for the scroll/exit-intent trigger and the
 * once-per-session sessionStorage flag.
 */

use App\AccountActivation;
use App\Lib\Fmt;

$minActivation = Fmt::money(AccountActivation::minActivationMinor());
?>
<dialog id="signup-popup" class="signup-popup" aria-labelledby="signup-popup-title">
    <button type="button" class="signup-popup-close" data-popup-close aria-label="Close">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <path d="M2 2 12 12M12 2 2 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
    </button>

    <div class="signup-popup-accent" aria-hidden="true"></div>

    <h2 id="signup-popup-title" class="font-display text-3xl leading-tight text-ink-100">
        Join Billions &mdash; <?= Fmt::e($minActivation) ?> minimum deposit to start
    </h2>

    <p class="mt-3 text-sm leading-relaxed text-ink-400">
        Create a free account to browse the full catalog. When you're ready to buy, fund your
        wallet with at least <?= Fmt::e($minActivation) ?> in BTC &mdash; that's your own spendable
        store balance, not a fee, and it activates your account the moment it's credited.
    </p>

    <div class="mt-6 flex flex-col gap-2.5 sm:flex-row">
        <a href="/register" class="btn btn-primary flex-1 justify-center">Create your account</a>
        <button type="button" class="btn btn-ghost" data-popup-close>Maybe later</button>
    </div>
</dialog>
