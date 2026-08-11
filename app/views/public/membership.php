<?php

declare(strict_types=1);

/**
 * @var int $feeMinor
 * @var bool $isMember
 * @var int|null $balance
 */

use App\Auth;
use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-2xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">Billions Membership</h1>

    <?php if ($isMember): ?>
        <p class="mt-4 leading-relaxed text-ink-300">
            You are a member. Member pricing and members-only collections are already unlocked on your account.
        </p>
        <a href="/shop" class="btn btn-primary mt-6">Browse the shop</a>
    <?php else: ?>
        <p class="mt-4 leading-relaxed text-ink-300">
            A one-time upgrade that unlocks member pricing on eligible products and access to members-only
            collections.
        </p>

        <div class="card-raised mt-7 p-6">
            <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Membership fee</p>
            <p class="price mt-1 text-4xl text-ember-500">
                <?= $feeMinor > 0 ? Fmt::e(Fmt::money($feeMinor)) : 'Free' ?>
            </p>
            <p class="mt-1 text-sm text-ink-500">One-time. Paid from your store balance, like any purchase.</p>

            <?php if (Auth::check()): ?>
                <?php if ($balance !== null && $balance < $feeMinor): ?>
                    <p class="hint mt-4">
                        Your balance is <?= Fmt::e(Fmt::money($balance)) ?>. Top up
                        <a href="/account/wallet" class="text-ember-500 hover:underline">your wallet</a> first.
                    </p>
                <?php endif; ?>

                <form method="post" action="/membership/join" class="mt-5">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-primary w-full text-base">Join Billions Membership</button>
                </form>
            <?php else: ?>
                <a href="/login?next=%2Fmembership" class="btn btn-primary mt-5 w-full text-base">Sign in to join</a>
            <?php endif; ?>
        </div>

        <h2 class="mt-8 font-display text-2xl text-ink-100">What you get</h2>
        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-ink-300">
            <li>Member pricing on products that offer it.</li>
            <li>Access to collections flagged members-only.</li>
        </ul>
    <?php endif; ?>
</div>
