<?php
declare(strict_types=1);

/**
 * @var bool $enabled
 * @var string $secret @var string $secretDisplay @var string $otpauth
 */

use App\Auth;
use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 lg:px-8">

    <a href="/account/settings" class="text-sm text-ink-500 hover:text-ink-200">&larr; Settings</a>
    <h1 class="mt-4 font-display text-4xl text-ink-100">Two-step verification</h1>

    <?php if ($enabled): ?>
        <div class="card mt-6 p-6">
            <span class="badge badge-ok">Enabled</span>
            <p class="mt-4 text-sm text-ink-400">
                Your account asks for a code from your authenticator app at every sign-in.
            </p>

            <form method="post" action="/account/settings/2fa/disable" class="mt-6 border-t border-ink-800 pt-5">
                <?= Auth::csrfField() ?>
                <label class="label" for="current_password">Enter your password to turn it off</label>
                <div class="flex flex-wrap gap-3">
                    <input class="field flex-1" type="password" id="current_password" name="current_password"
                           required autocomplete="current-password">
                    <button class="btn btn-danger shrink-0" type="submit">Turn off</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="card mt-6 p-6">
            <ol class="space-y-6">
                <li>
                    <p class="text-sm font-medium text-ink-100">1. Scan this with your authenticator app</p>
                    <p class="mt-1 text-sm text-ink-500">Google Authenticator, 1Password, Aegis, Raivo &mdash; any of them.</p>

                    <div class="mt-4 inline-block rounded-lg bg-white p-3">
                        <?php // Rendered locally: the secret must never go to a QR image service. ?>
                        <canvas id="totp-qr" width="180" height="180"
                                data-qr="<?= Fmt::e($otpauth) ?>"
                                role="img" aria-label="QR code for two-step verification setup"></canvas>
                    </div>
                </li>

                <li>
                    <p class="text-sm font-medium text-ink-100">2. Or type the key in by hand</p>
                    <button type="button" class="ident ident-copy mt-2 text-base"
                            data-copy="<?= Fmt::e($secret) ?>"
                            aria-label="Copy setup key">
                        <span aria-hidden="true"><?= Fmt::e($secretDisplay) ?></span>
                    </button>
                </li>

                <li>
                    <p class="text-sm font-medium text-ink-100">3. Enter the current code to confirm</p>

                    <form method="post" action="/account/settings/2fa/enable" class="mt-3 flex flex-wrap gap-3">
                        <?= Auth::csrfField() ?>
                        <label class="sr-only-focusable" for="code">Six-digit code</label>
                        <input class="field field-mono w-40 text-center text-xl tracking-[0.3em]"
                               type="text" id="code" name="code" required
                               inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000"
                               autocomplete="one-time-code">
                        <button class="btn btn-primary shrink-0" type="submit">Turn on</button>
                    </form>
                </li>
            </ol>
        </div>
    <?php endif; ?>
</div>
