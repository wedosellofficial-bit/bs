<?php
declare(strict_types=1);
use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;
?>
<div class="card ambient-glow p-7">
    <h1 class="font-display text-3xl text-ink-100">Sign in</h1>
    <p class="mt-1 text-sm text-ink-400">Welcome back.</p>

    <form method="post" action="/login" class="mt-6 space-y-4">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="next" value="<?= Fmt::e($next ?? '') ?>">

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" type="email" id="email" name="email" required
                   autocomplete="username" autocapitalize="off" spellcheck="false" autofocus>
        </div>

        <div>
            <div class="mb-1.5 flex items-baseline justify-between">
                <label class="label mb-0" for="password">Password</label>
                <a href="/forgot-password" class="text-xs text-ember-500 hover:underline">Forgot it?</a>
            </div>
            <div class="relative">
                <input class="field pr-11" type="password" id="password" name="password" required
                       autocomplete="current-password">
                <?= View::partial('partials/password-toggle', ['for' => 'password']) ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-full">Sign in</button>
    </form>

    <p class="mt-6 border-t border-ink-800 pt-5 text-center text-sm text-ink-400">
        No account? <a href="/register" class="text-ember-500 hover:underline">Create one</a>
    </p>
</div>
