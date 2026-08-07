<?php
declare(strict_types=1);

/** @var list<string> $codes */

use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-xl px-4 py-8 sm:px-6 lg:px-8">

    <h1 class="font-display text-4xl text-ink-100">Save your recovery codes</h1>
    <p class="mt-2 text-ink-400">
        Two-step verification is on. These codes are the only way back into your account if you
        lose your phone.
    </p>

    <div class="card-raised mt-6 p-6">
        <p class="text-sm text-ink-400">
            Each code works once. Print them, or put them in a password manager &mdash; not in
            the same app that generates your codes.
        </p>

        <ul class="mt-5 grid grid-cols-2 gap-2">
            <?php foreach ($codes as $code): ?>
                <li class="ident rounded-md border border-ink-800 bg-ink-950 px-3 py-2 text-center text-base text-ink-100">
                    <?= Fmt::e($code) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <button type="button" class="btn btn-secondary mt-5 w-full"
                data-copy="<?= Fmt::e(implode("\n", $codes)) ?>">
            Copy all codes
        </button>
    </div>

    <div class="flash flash-error mt-6">
        <p>
            This is the only time these are shown. Once you leave this page they cannot be
            retrieved &mdash; only replaced by turning two-step verification off and on again.
        </p>
    </div>

    <a href="/account/settings" class="btn btn-primary mt-6 w-full">I have saved them</a>
</div>
