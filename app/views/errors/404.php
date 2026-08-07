<?php
declare(strict_types=1);
use App\Lib\Fmt;
?>
<div class="mx-auto flex w-full max-w-xl flex-col items-center px-4 py-24 text-center">
    <p class="font-mono text-sm tracking-[0.3em] text-ember-500">404</p>
    <h1 class="mt-4 font-display text-4xl text-ink-100"><?= Fmt::e($title ?? 'Page not found') ?></h1>
    <p class="mt-3 text-ink-400"><?= Fmt::e($message ?? 'That address does not lead anywhere.') ?></p>
    <div class="mt-8 flex gap-3">
        <a href="/collection" class="btn btn-primary">Browse the collection</a>
        <a href="/" class="btn btn-secondary">Home</a>
    </div>
</div>
