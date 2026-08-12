<?php

declare(strict_types=1);

/**
 * The gateway. Anyone not signed in lands here instead of the real
 * storefront home - no featured items, no collections, no stats, no
 * catalog data of any kind. See HomeController::index() and the catalog
 * gate in App\Lib\Router.
 *
 * Deliberately not styled like the catalog: no `.card` tiles (that
 * component is what product/NFT cards use elsewhere, so reusing it here
 * would echo catalog UI even with no real data inside it), no grid of
 * "products", nothing that reads as a browsing surface. This is the
 * entrance, not a preview of what's behind it.
 */

use App\Lib\Fmt;
?>

<section class="relative overflow-hidden border-b border-ink-800">
    <?php // A quiet radial glow behind the headline - depth without a photo, a video, or catalog art. ?>
    <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-ember-900/20 via-transparent to-transparent"></div>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-ember-700/60 to-transparent"></div>

    <div class="relative mx-auto w-full max-w-3xl px-4 py-28 text-center sm:px-6 lg:px-8 lg:py-36">
        <p class="font-mono text-xs uppercase tracking-[0.32em] text-ember-500">
            Digital Art &middot; NFTs &middot; Ordinals
        </p>

        <h1 class="mx-auto mt-7 max-w-2xl font-display text-6xl leading-[0.98] text-ink-100 sm:text-7xl lg:text-8xl">
            Enter<br>Billions Store.
        </h1>

        <p class="mx-auto mt-8 max-w-md text-base leading-relaxed text-ink-400">
            An exclusive marketplace for digital art and Bitcoin Ordinals. Create your account to
            step inside &mdash; registration is free and takes a minute.
        </p>

        <div class="mt-10 flex flex-col items-center gap-4">
            <a href="/register" class="btn btn-primary px-10 py-4 text-base">Create your account</a>
            <a href="/login" class="text-sm text-ink-500 transition-colors hover:text-ink-200">
                Already have an account? <span class="text-ember-500">Sign in</span>
            </a>
        </div>
    </div>
</section>

<section class="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
    <div class="grid gap-8 sm:grid-cols-3">
        <?php foreach ([
            ['Digital Art', 'Original pieces and design files, delivered instantly on purchase.'],
            ['Ordinals', 'Bitcoin Ordinals inscriptions, held in our project wallet until they sell.'],
            ['Exclusive', "Collections and products you won't find anywhere else."],
        ] as [$heading, $body]): ?>
            <div class="border-t border-ink-800 pt-5 text-center sm:text-left">
                <h2 class="font-display text-xl text-ink-100"><?= Fmt::e($heading) ?></h2>
                <p class="mt-1.5 text-sm leading-relaxed text-ink-500"><?= Fmt::e($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="mx-auto mt-12 max-w-lg text-center text-sm leading-relaxed text-ink-500">
        Once you're signed in, the full catalog opens up &mdash; browse, search, filter by category
        or collection, and view every listing in detail.
    </p>
</section>

<section class="border-t border-ink-800 bg-ink-900">
    <div class="mx-auto w-full max-w-2xl px-4 py-16 text-center sm:px-6 lg:px-8">
        <p class="font-mono text-xs uppercase tracking-[0.28em] text-ember-500">Getting started</p>

        <ol class="mx-auto mt-8 grid gap-8 text-left sm:grid-cols-3">
            <?php
            $steps = [
                ['Register', 'Create a free account and verify your email.'],
                ['Enter', 'Sign in to unlock the full catalog - digital art, Ordinals, and collections.'],
                ['Buy when ready', 'Fund your wallet whenever you choose. It becomes ordinary, spendable balance - never a fee.'],
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

        <a href="/register" class="btn btn-primary mt-12 px-10 py-4 text-base">Create your account</a>
    </div>
</section>
