<?php
declare(strict_types=1);
use App\Auth;
?>
<div class="card p-7">
    <h1 class="font-display text-3xl text-ink-100">Two-step verification</h1>
    <p class="mt-1 text-sm text-ink-400">
        Enter the 6-digit code from your authenticator app.
    </p>

    <form method="post" action="/login/2fa" class="mt-6 space-y-4">
        <?= Auth::csrfField() ?>

        <div>
            <label class="label" for="code">Code</label>
            <input class="field field-mono text-center text-2xl tracking-[0.4em]"
                   type="text" id="code" name="code" required autofocus
                   inputmode="numeric" pattern="[0-9]*" maxlength="11"
                   autocomplete="one-time-code" placeholder="000000">
            <p class="hint mt-1.5">
                Codes change every 30 seconds. You can also use one of your recovery codes.
            </p>
        </div>

        <button type="submit" class="btn btn-primary w-full">Verify</button>
    </form>

    <p class="mt-6 border-t border-ink-800 pt-5 text-center text-sm">
        <a href="/login" class="text-ink-500 hover:text-ink-200">Start over</a>
    </p>
</div>
