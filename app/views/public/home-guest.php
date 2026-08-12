<?php

declare(strict_types=1);

/**
 * Registration-first landing page for anyone not signed in. Deliberately
 * carries no catalog data at all - no featured items, no collections, no
 * stats - since the whole point is that browsing the catalog itself
 * requires an account. See HomeController::index() and the catalog gate
 * in App\Lib\Router.
 */

use App\Lib\Fmt;
?>

<section class="border-b border-ink-800">
    <div class="mx-auto w-full max-w-5xl px-4 py-24 text-center sm:px-6 lg:px-8 lg:py-32">
        <p class="font-mono text-xs uppercase tracking-[0.28em] text-ember-500">Billions Store</p>

        <h1 class="mx-auto mt-6 max-w-3xl font-display text-5xl leading-[1.05] text-ink-100 sm:text-6xl lg:text-7xl">
            Create your account<br class="hidden sm:block">
            to enter Billions Store.
        </h1>

        <p class="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-ink-400">
            A premium marketplace for digital art and Bitcoin Ordinals. Registration is free and
            takes a minute &mdash; it's what unlocks the catalog, not a payment.
        </p>

        <div class="mt-10 flex flex-wrap items-center justify-center gap-3">
            <a href="/register" class="btn btn-primary px-8 py-3.5 text-base">Create your account</a>
            <a href="/login" class="btn btn-secondary px-8 py-3.5 text-base">Already have an account? Sign in</a>
        </div>
    </div>
</section>

<section class="mx-auto w-full max-w-5xl px-4 py-16 sm:px-6 lg:px-8">
    <h2 class="text-center font-display text-2xl text-ink-100">What's inside</h2>

    <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ([
            ['Digital Art', 'Original pieces and design files, delivered instantly on purchase.'],
            ['NFT Collections', 'Bitcoin Ordinals inscriptions, held in our project wallet until they sell.'],
            ['Ordinals', 'Provenance and traits on every inscription, transferred to you by hand.'],
            ['Exclusive Products', "Digital goods you won't find anywhere else on the store."],
        ] as [$heading, $body]): ?>
            <div class="card p-5">
                <h3 class="font-display text-lg text-ink-100"><?= Fmt::e($heading) ?></h3>
                <p class="mt-1.5 text-sm leading-relaxed text-ink-400"><?= Fmt::e($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="mx-auto mt-10 max-w-xl text-center text-sm leading-relaxed text-ink-500">
        Once you're signed in, you can browse the full catalog, search, filter by category or
        collection, and view every listing in detail.
    </p>
</section>

<section class="border-t border-ink-800 bg-ink-900">
    <div class="mx-auto w-full max-w-3xl px-4 py-16 text-center sm:px-6 lg:px-8">
        <h2 class="font-display text-2xl text-ink-100">Getting started</h2>

        <ol class="mx-auto mt-8 grid max-w-2xl gap-8 text-left sm:grid-cols-3">
            <?php
            $steps = [
                ['Register', 'Create a free account and verify your email.'],
                ['Browse', 'Sign in to explore the full catalog - digital art, Ordinals, and collections.'],
                ['Buy when ready', 'Fund your wallet whenever you want to buy. It becomes ordinary, spendable balance - never a fee.'],
            ];
            foreach ($steps as $index => [$heading, $body]):
                ?>
                <li>
                    <span class="price text-sm text-ember-500"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <h3 class="mt-2 font-display text-lg text-ink-100"><?= Fmt::e($heading) ?></h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-ink-400"><?= Fmt::e($body) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>

        <a href="/register" class="btn btn-primary mt-10 px-8 py-3.5 text-base">Create your account</a>
    </div>
</section>
