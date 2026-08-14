<?php

declare(strict_types=1);

/**
 * Wallet: balance, the store's deposit address, and recent activity.
 *
 * Deposits are manual. There is one BTC address, held by the operator,
 * shown here with a QR code. A customer sends BTC to it and waits for an
 * admin to credit their balance after checking the deposit on a block
 * explorer - there is no automatic crediting, no per-user address, and
 * no confirmation counter on this page, because nothing here is watching
 * the chain. The credit itself, when it happens, is a normal ledger
 * entry and shows up in the activity list below like any other.
 *
 * @var int $balance
 * @var array{credits:int,debits:int,entries:int} $totals
 * @var list<array<string,mixed>> $statement
 * @var string $depositAddress
 * @var bool $isActive
 * @var int $minActivationMinor
 */

use App\Lib\Fmt;

$secondExampleMinor = $minActivationMinor * 2;
?>

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

    <?php if (!$isActive): ?>
        <div class="mb-8">
            <p class="text-xs uppercase tracking-[0.14em] text-ember-500">Activate your marketplace access</p>
            <h1 class="mt-2 font-display text-3xl text-ink-100 sm:text-4xl">Fund your wallet</h1>
            <p class="mt-3 max-w-2xl text-ink-400">
                Your account has been created successfully. To enter the Billions Store marketplace &mdash;
                Shop, Latest, Collections and every product page &mdash; fund your wallet with at least
                <span class="price text-ink-100"><?= Fmt::e(Fmt::money($minActivationMinor)) ?></span>.
            </p>

            <div class="flash flash-info mt-4">
                <div>
                    <p class="font-medium">
                        <?= Fmt::e(Fmt::money($minActivationMinor)) ?> is your wallet balance &mdash; not an activation fee.
                    </p>
                    <p class="mt-1 text-sm">
                        It remains fully yours to spend on anything in the marketplace. Deposit
                        <?= Fmt::e(Fmt::money($minActivationMinor)) ?> and you have
                        <?= Fmt::e(Fmt::money($minActivationMinor)) ?> available to spend. Deposit
                        <?= Fmt::e(Fmt::money($secondExampleMinor)) ?> and you have
                        <?= Fmt::e(Fmt::money($secondExampleMinor)) ?> available to spend &mdash; none of it is held back
                        or consumed by activation.
                    </p>
                    <p class="mt-3 text-sm">
                        Marketplace access is activated automatically once your wallet balance reaches
                        <?= Fmt::e(Fmt::money($minActivationMinor)) ?> or more. You currently have
                        <?= Fmt::e(Fmt::money($balance)) ?>; <?= Fmt::e(Fmt::money(max(0, $minActivationMinor - $balance))) ?>
                        more to go.
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php // ---------- Balance header ---------- ?>
    <header class="mb-8">
        <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Store balance</p>
        <div class="mt-1 flex flex-wrap items-end gap-x-5 gap-y-2">
            <span class="price text-5xl leading-none text-ink-100 sm:text-6xl">
                <?= Fmt::e(Fmt::money($balance)) ?>
            </span>
            <div class="flex gap-5 pb-1 text-sm">
                <span class="text-ink-500">
                    In <span class="price text-mint-400"><?= Fmt::e(Fmt::money($totals['credits'])) ?></span>
                </span>
                <span class="text-ink-500">
                    Out <span class="price text-ink-300"><?= Fmt::e(Fmt::money($totals['debits'])) ?></span>
                </span>
            </div>
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">

        <div class="min-w-0 space-y-6">

            <?php // ---------- Deposit address ---------- ?>
            <section class="card ambient-glow p-6">
                <h2 class="font-display text-2xl text-ink-100">Add funds</h2>
                <p class="mt-1 text-sm text-ink-400">
                    Send BTC to the address below. Balance is credited by hand, not
                    automatically &mdash; see how that works underneath.
                </p>

                <?php if ($depositAddress === ''): ?>
                    <div class="flash flash-error mt-5">
                        <p>
                            No deposit address is configured yet. If you run this store,
                            set <span class="ident">MANUAL_BTC_ADDRESS</span> in <span class="ident">.env</span>.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-6 flex flex-col gap-6 sm:flex-row sm:items-start">
                        <div class="shrink-0 self-center rounded-lg bg-white p-3 sm:self-start">
                            <?php
                            // BIP-21: the address itself is not percent-encoded, only query
                            // parameters would be - there are none here, since this is an
                            // open-ended address rather than a fixed-amount request.
                            ?>
                            <canvas id="deposit-qr"
                                    width="200" height="200"
                                    data-qr="bitcoin:<?= Fmt::e($depositAddress) ?>"
                                    aria-label="QR code containing the deposit address"
                                    role="img"></canvas>
                            <noscript>
                                <p class="w-[200px] p-4 text-center text-xs text-ink-950">
                                    Enable JavaScript to render the QR code, or copy the address below.
                                </p>
                            </noscript>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Deposit address</p>
                            <div class="mt-2">
                                <button type="button"
                                        class="ident-copy price block w-full break-all rounded-lg border border-ink-800 bg-ink-850 px-4 py-3 text-left text-sm text-ink-100 sm:text-base"
                                        data-copy="<?= Fmt::e($depositAddress) ?>"
                                        aria-label="Copy deposit address">
                                    <span aria-hidden="true"><?= Fmt::e($depositAddress) ?></span>
                                </button>
                            </div>

                            <ul class="mt-4 space-y-1.5 text-sm text-ink-400">
                                <li class="flex gap-2">
                                    <span class="text-ink-600">&bull;</span>
                                    This address is permanent and shared &mdash; the same one every
                                    time, for every customer.
                                </li>
                                <li class="flex gap-2">
                                    <span class="text-ink-600">&bull;</span>
                                    Send only BTC here. Anything else sent to this address cannot
                                    be recovered.
                                </li>
                                <li class="flex gap-2">
                                    <span class="text-ink-600">&bull;</span>
                                    The Bitcoin network fee is paid by your wallet when you send,
                                    to miners, not to us.
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php // ---------- How manual crediting works ---------- ?>
                <div class="mt-6 border-t border-ink-800 pt-5">
                    <h3 class="text-sm font-semibold text-ink-100">
                        <?= $isActive ? 'How this gets credited' : 'How activation works' ?>
                    </h3>
                    <ol class="mt-3 space-y-2.5 text-sm text-ink-400">
                        <li class="flex gap-2.5">
                            <span class="price shrink-0 text-ember-500">1</span>
                            <span>Copy the deposit address above into your own BTC wallet.</span>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="price shrink-0 text-ember-500">2</span>
                            <span>
                                Send a BTC amount equivalent to at least
                                <?= Fmt::e(Fmt::money($minActivationMinor)) ?><?= $isActive ? '' : ' to activate' ?>.
                            </span>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="price shrink-0 text-ember-500">3</span>
                            <span>
                                Wait for it to confirm on the network &mdash; a block explorer
                                like <span class="ident">mempool.space</span> will show it.
                            </span>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="price shrink-0 text-ember-500">4</span>
                            <span>
                                An admin reviews the deposit and credits your balance by hand.
                                This is not automatic, so it will not appear the instant your
                                transaction confirms &mdash; if it has been longer than a day,
                                contact support with your transaction id.
                            </span>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="price shrink-0 text-ember-500">5</span>
                            <span>
                                Once your wallet balance reaches <?= Fmt::e(Fmt::money($minActivationMinor)) ?> or
                                more, marketplace access activates automatically &mdash; no further step needed.
                            </span>
                        </li>
                    </ol>
                    <p class="hint mt-4">
                        Once credited, it appears on your statement below exactly like any
                        other balance change, with whatever note the admin attached.
                    </p>
                </div>
            </section>
        </div>

        <?php // ---------- Recent activity ---------- ?>
        <aside class="space-y-6">
            <section class="card">
                <div class="flex items-center justify-between border-b border-ink-800 px-4 py-3">
                    <h2 class="text-sm font-semibold text-ink-100">Recent activity</h2>
                    <a href="/account/wallet/statement" class="text-xs text-ember-500 hover:underline">Full statement</a>
                </div>

                <?php if ($statement === []): ?>
                    <p class="px-4 py-8 text-center text-sm text-ink-500">Nothing yet.</p>
                <?php else: ?>
                    <ul class="divide-y divide-ink-800">
                        <?php foreach ($statement as $entry): ?>
                            <?php $amount = (int) $entry['amount_minor']; ?>
                            <li class="flex items-start justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-ink-200">
                                        <?= Fmt::e((string) ($entry['memo'] ?? ucfirst((string) $entry['type']))) ?>
                                    </p>
                                    <p class="text-xs text-ink-600">
                                        <?= Fmt::e(Fmt::relative((string) $entry['created_at'])) ?>
                                    </p>
                                </div>
                                <span class="price shrink-0 text-sm <?= $amount >= 0 ? 'text-mint-400' : 'text-ink-300' ?>">
                                    <?= Fmt::e(Fmt::moneySigned($amount)) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>
