<?php
declare(strict_types=1);
use App\Auth;
use App\Lib\Fmt;
?>
<div class="card p-7">
    <h1 class="font-display text-3xl text-ink-100">Choose a new password</h1>

    <form method="post" action="/reset-password" class="mt-6 space-y-4">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="token" value="<?= Fmt::e($token ?? '') ?>">

        <div>
            <label class="label" for="password">New password</label>
            <input class="field" type="password" id="password" name="password" required autofocus
                   autocomplete="new-password" minlength="12">
            <p class="hint mt-1.5">At least 12 characters.</p>
        </div>

        <div>
            <label class="label" for="password_confirm">Confirm it</label>
            <input class="field" type="password" id="password_confirm" name="password_confirm" required
                   autocomplete="new-password" minlength="12">
        </div>

        <button type="submit" class="btn btn-primary w-full">Set new password</button>
    </form>

    <p class="hint mt-5 border-t border-ink-800 pt-5">
        Changing your password signs out every device, including this one.
    </p>
</div>
