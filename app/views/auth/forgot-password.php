<?php
declare(strict_types=1);
use App\Auth;
?>
<div class="card p-7">
    <h1 class="font-display text-3xl text-ink-100">Reset your password</h1>
    <p class="mt-1 text-sm text-ink-400">
        We will email you a link. It expires in an hour and works once.
    </p>

    <form method="post" action="/forgot-password" class="mt-6 space-y-4">
        <?= Auth::csrfField() ?>

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" type="email" id="email" name="email" required autofocus
                   autocomplete="username" autocapitalize="off" spellcheck="false">
        </div>

        <button type="submit" class="btn btn-primary w-full">Send reset link</button>
    </form>

    <p class="mt-6 border-t border-ink-800 pt-5 text-center text-sm">
        <a href="/login" class="text-ink-500 hover:text-ink-200">Back to sign in</a>
    </p>
</div>
