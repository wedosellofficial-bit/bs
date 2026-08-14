<?php

declare(strict_types=1);

/**
 * The gateway. Anyone not signed in lands here instead of the real
 * storefront home - no featured items, no collections, no stats, no
 * catalog data of any kind. See HomeController::index() and the catalog
 * gate in App\Lib\Router.
 *
 * Deliberately just header / one create-account panel / footer - no
 * value-prop grid, no "getting started" steps, nothing that turns the
 * gateway into a second scrollable landing page. The panel itself is
 * `.card.ambient-glow`: the same glass surface + slowly rotating gradient
 * ring used on the register/login form and the signup popup, not a
 * catalog `.card` (no product/NFT tiles use ambient-glow, so this stays
 * visually distinct from a browsing surface).
 */
?>

<section class="relative flex min-h-[calc(100vh-4rem)] items-center overflow-hidden">
    <div class="relative mx-auto w-full max-w-xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="card ambient-glow px-8 py-14 text-center sm:px-14 sm:py-16">
            <p class="font-mono text-xs uppercase tracking-[0.32em] text-ember-500">
                Digital Art &middot; NFTs &middot; Ordinals
            </p>

            <h1 class="mx-auto mt-6 max-w-md font-display text-5xl leading-[0.98] text-ink-100 sm:text-6xl">
                Enter<br>Billions Store.
            </h1>

            <p class="mx-auto mt-7 max-w-sm text-base leading-relaxed text-ink-400">
                An exclusive marketplace for digital art and Bitcoin Ordinals. Create your account to
                step inside &mdash; registration is free and takes a minute.
            </p>

            <div class="mt-9 flex flex-col items-center gap-4">
                <a href="/register" class="btn btn-primary w-full max-w-xs px-10 py-4 text-base sm:w-auto">
                    Create your account
                </a>
                <a href="/login" class="text-sm text-ink-500 transition-colors hover:text-ink-200">
                    Already have an account? <span class="text-ember-500">Sign in</span>
                </a>
            </div>
        </div>
    </div>
</section>
