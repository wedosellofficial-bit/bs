<?php

declare(strict_types=1);

/**
 * Purchase confirmation.
 *
 * Shows the balance before and after, and asks for the payout address
 * every time - even when one is saved. An inscription transfer cannot be
 * undone, so the address is re-typed or re-confirmed at the moment of
 * sale rather than inherited from a setting changed months ago.
 *
 * @var array<string,mixed> $nft
 * @var int $balance
 * @var int $price
 * @var int $balanceAfter
 * @var bool $canAfford
 * @var int $shortfall
 * @var string|null $savedAddress
 * @var list<array{trait_type:string,value:string}> $attributes
 */

use App\Auth;
use App\Lib\Fmt;
use App\Lib\View;
?>

<div class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

    <a href="/nft/<?= (int) $nft['id'] ?>" class="inline-flex items-center gap-1.5 text-sm text-ink-500 transition-colors hover:text-ink-200">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <path d="M8.5 3.5 5 7l3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Back to item
    </a>

    <h1 class="mt-4 font-display text-4xl text-ink-100">Confirm purchase</h1>

    <div class="mt-8 grid gap-6 md:grid-cols-[13rem_1fr]">

        <?php // ---------- Item ---------- ?>
        <div>
            <div class="card ambient-glow overflow-hidden">
                <div class="art-frame">
                    <img src="<?= Fmt::e(View::media($nft['preview_path'] ?? null)) ?>"
                         alt="<?= Fmt::e((string) $nft['name']) ?>" width="640" height="640">
                </div>
            </div>

            <h2 class="mt-3 font-display text-xl leading-tight text-ink-100">
                <?= Fmt::e((string) $nft['name']) ?>
            </h2>

            <div class="mt-2 space-y-1 text-xs">
                <?php if (($nft['inscription_number'] ?? null) !== null): ?>
                    <p class="text-ink-500">
                        Inscription <span class="ident">#<?= Fmt::e(number_format((int) $nft['inscription_number'])) ?></span>
                    </p>
                <?php endif; ?>
                <div class="text-ink-500">
                    <?= View::partial('partials/ident', [
                        'value' => (string) $nft['token_id'],
                        'head'  => 6,
                        'tail'  => 6,
                        'label' => 'inscription id',
                    ]) ?>
                </div>
            </div>
        </div>

        <?php // ---------- Confirmation ---------- ?>
        <div class="min-w-0 space-y-5">

            <?php // --- balance maths, stated plainly --- ?>
            <section class="card-raised p-5">
                <h2 class="text-sm font-semibold text-ink-100">What this costs</h2>

                <dl class="mt-4 space-y-3">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-sm text-ink-400">Balance now</dt>
                        <dd class="price text-lg text-ink-200"><?= Fmt::e(Fmt::money($balance)) ?></dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-sm text-ink-400">This item</dt>
                        <dd class="price text-lg text-rose-400">&minus;<?= Fmt::e(Fmt::money($price)) ?></dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 border-t border-ink-800 pt-3">
                        <dt class="text-sm font-medium text-ink-200">Balance after</dt>
                        <dd class="price text-2xl <?= $canAfford ? 'text-ink-100' : 'text-rose-400' ?>">
                            <?= Fmt::e(Fmt::money($balanceAfter)) ?>
                        </dd>
                    </div>
                </dl>
            </section>

            <?php if (!$canAfford): ?>
                <?php // --- can't afford --- ?>
                <div class="flash flash-error">
                    <div>
                        <p class="font-medium">You need <?= Fmt::e(Fmt::money($shortfall)) ?> more.</p>
                        <p class="mt-1 text-sm opacity-90">
                            Top up your balance, then come back &mdash; this item stays listed until someone buys it.
                        </p>
                    </div>
                </div>

                <a href="/account/wallet" class="btn btn-primary w-full">Add funds</a>

            <?php else: ?>
                <?php // --- payout address + buy --- ?>
                <form method="post" action="/buy/<?= (int) $nft['id'] ?>" class="space-y-5" data-confirm-purchase>
                    <?= Auth::csrfField() ?>

                    <section class="card p-5">
                        <label class="label" for="payout_address">Send the inscription to</label>

                        <input type="text" id="payout_address" name="payout_address"
                               class="field field-mono"
                               value="<?= Fmt::e((string) ($savedAddress ?? '')) ?>"
                               placeholder="bc1p..."
                               spellcheck="false" autocapitalize="off" autocorrect="off"
                               required aria-describedby="payout-help">

                        <p id="payout-help" class="hint mt-2">
                            A <strong class="text-ink-300">taproot</strong> address, starting
                            <span class="ident text-ember-400">bc1p</span>. Use the Ordinals
                            receive address from Xverse, Leather, Unisat or similar &mdash; not an
                            exchange deposit address, and not a bc1q address.
                        </p>

                        <?php if ($savedAddress !== null && $savedAddress !== ''): ?>
                            <p class="hint mt-2 text-ink-600">
                                Filled in from your saved address. Check it against your wallet before continuing.
                            </p>
                        <?php endif; ?>

                        <label class="check-row mt-4 items-start">
                            <input type="checkbox" name="remember_address" value="1" class="mt-0.5">
                            <span class="text-ink-400">Save this address for next time</span>
                        </label>
                    </section>

                    <?php // --- irreversibility acknowledgement --- ?>
                    <section class="card border-amber-400/25 bg-amber-900/20 p-5">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" name="confirm_address" value="1" required
                                   class="mt-1 accent-ember-500" style="width:1rem;height:1rem">
                            <span class="text-sm leading-relaxed text-ink-200">
                                I have checked this address character by character against my wallet.
                                I understand that once the inscription is sent it cannot be recovered,
                                and that an address I do not control means the item is lost.
                            </span>
                        </label>
                    </section>

                    <button type="submit" class="btn btn-primary w-full text-base">
                        Buy for <?= Fmt::e(Fmt::money($price)) ?>
                    </button>

                    <p class="text-center text-xs text-ink-600">
                        Your balance is debited immediately and the transfer is queued.
                        Transfers are sent by hand, normally within one business day.
                    </p>
                </form>
            <?php endif; ?>

            <?php if ($attributes !== []): ?>
                <details class="card p-5">
                    <summary class="cursor-pointer text-sm font-medium text-ink-200">Traits</summary>
                    <dl class="mt-4 grid grid-cols-2 gap-3">
                        <?php foreach ($attributes as $attribute): ?>
                            <div class="rounded-md border border-ink-800 bg-ink-850 px-3 py-2">
                                <dt class="text-[0.65rem] uppercase tracking-wider text-ink-600">
                                    <?= Fmt::e($attribute['trait_type']) ?>
                                </dt>
                                <dd class="mt-0.5 truncate text-sm text-ink-200">
                                    <?= Fmt::e($attribute['value']) ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </details>
            <?php endif; ?>
        </div>
    </div>
</div>
