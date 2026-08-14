<?php

declare(strict_types=1);

/**
 * Landing page for a signed-in account that has not yet reached the
 * activation threshold. Same principle as public/home-guest.php - zero
 * catalog data, because a featured-NFT preview here would leak exactly
 * what Router::marketplaceGateApplies() exists to withhold from an
 * unfunded account on every other catalog route. See HomeController::index().
 */

use App\AccountActivation;
use App\Lib\Fmt;

$minActivation = AccountActivation::minActivationMinor();
?>

<section class="relative flex min-h-[calc(100vh-4rem)] items-center overflow-hidden">
    <div class="relative mx-auto w-full max-w-xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="card ambient-glow px-8 py-14 text-center sm:px-14 sm:py-16">
            <p class="font-mono text-xs uppercase tracking-[0.32em] text-ember-500">
                Account pending
            </p>

            <h1 class="mx-auto mt-6 max-w-md font-display text-5xl leading-[0.98] text-ink-100 sm:text-6xl">
                Fund your wallet<br>to enter.
            </h1>

            <p class="mx-auto mt-7 max-w-sm text-base leading-relaxed text-ink-400">
                You're signed in. Add at least <span class="price text-ink-100"><?= Fmt::e(Fmt::money($minActivation)) ?></span>
                to your wallet and the marketplace &mdash; Shop, Latest, Collections, every listing
                &mdash; opens up automatically. It's not a fee: the deposit stays your ordinary,
                spendable balance.
            </p>

            <div class="mt-9 flex flex-col items-center gap-4">
                <a href="/account/wallet" class="btn btn-primary w-full max-w-xs px-10 py-4 text-base sm:w-auto">
                    Fund your wallet
                </a>
                <a href="/account" class="text-sm text-ink-500 transition-colors hover:text-ink-200">
                    Go to your dashboard
                </a>
            </div>
        </div>
    </div>
</section>
