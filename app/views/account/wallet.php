<?php

declare(strict_types=1);

/**
 * Wallet: balance, top-up, and deposit history.
 *
 * @var int $balance
 * @var array{credits:int,debits:int,entries:int} $totals
 * @var list<array<string,mixed>> $deposits
 * @var array<string,mixed>|null $activeDeposit
 * @var array{rate:?string,fetched_at:?string,source:string} $rate
 * @var list<array<string,mixed>> $statement
 * @var array{min:int,max:int} $limits
 * @var int $requiredConfirmations
 * @var int $feeBps
 * @var string $asset
 */

use App\Auth;
use App\Controllers\WalletController;
use App\Lib\Fmt;
use App\Lib\View;
use App\Ordinals;

$digits = 10 ** \App\Lib\Config::int('ledger.minor_digits', 2);
?>

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

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

            <?php // ---------- Active quote ---------- ?>
            <?php if ($activeDeposit !== null): ?>
                <div class="card border-ember-700 bg-ember-900/25 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 class="font-display text-xl text-ink-100">You have a deposit waiting</h2>
                            <p class="mt-1 text-sm text-ink-400">
                                <span class="price"><?= Fmt::e(Fmt::btc((string) $activeDeposit['amount_crypto'])) ?> <?= Fmt::e($asset) ?></span>
                                for <span class="price"><?= Fmt::e(Fmt::money((int) $activeDeposit['amount_minor_requested'])) ?></span>,
                                quote expires <?= Fmt::e(Fmt::relative((string) $activeDeposit['quote_expires_at'])) ?>.
                            </p>
                        </div>
                        <a href="/account/wallet/deposit/<?= (int) $activeDeposit['id'] ?>" class="btn btn-primary btn-sm">
                            Show address
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php // ---------- Top up ---------- ?>
            <section class="card p-6">
                <h2 class="font-display text-2xl text-ink-100">Add funds</h2>
                <p class="mt-1 text-sm text-ink-400">
                    Top up with <?= Fmt::e($asset) ?>. We generate a fresh address for every
                    request, so no two deposits share one.
                </p>

                <form method="post" action="/account/wallet/topup" class="mt-5">
                    <?= Auth::csrfField() ?>

                    <label class="label" for="topup-amount">Amount to add</label>

                    <div class="flex flex-wrap gap-3">
                        <div class="relative min-w-[10rem] flex-1">
                            <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-500">$</span>
                            <input type="text" inputmode="decimal" id="topup-amount" name="amount"
                                   class="field field-mono pl-7" placeholder="250.00" required
                                   data-topup-amount
                                   data-rate="<?= Fmt::e($rate['rate'] ?? '') ?>"
                                   aria-describedby="topup-conversion topup-limits">
                        </div>
                        <button type="submit" class="btn btn-primary">Generate address</button>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <?php foreach ([50, 100, 250, 500, 1000] as $preset): ?>
                            <button type="button" class="btn btn-secondary btn-sm" data-topup-preset="<?= (int) $preset ?>">
                                $<?= (int) $preset ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <p id="topup-limits" class="hint mt-3">
                        Between <?= Fmt::e(Fmt::money($limits['min'])) ?> and <?= Fmt::e(Fmt::money($limits['max'])) ?> per top-up.
                    </p>

                    <?php // Live estimate, updated client-side from the cached rate. ?>
                    <p id="topup-conversion" class="mt-1 text-sm text-ink-400" data-topup-conversion aria-live="polite"></p>
                </form>

                <?php // ---------- Rate disclosure ---------- ?>
                <div class="mt-6 border-t border-ink-800 pt-5">
                    <?php if ($rate['rate'] !== null): ?>
                        <dl class="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink-500">Indicative rate</dt>
                                <dd class="price mt-0.5 text-ink-200">
                                    1 <?= Fmt::e($asset) ?> = <?= Fmt::e(Fmt::money((int) round(((float) $rate['rate']) * $digits))) ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink-500">Fetched</dt>
                                <dd class="mt-0.5 text-ink-300">
                                    <?php // The real fetch time, not "now" - a rate stamped with the
                                          // current time is a rate nobody can audit. ?>
                                    <time datetime="<?= Fmt::e(Fmt::iso($rate['fetched_at'])) ?>">
                                        <?= Fmt::e(Fmt::relative($rate['fetched_at'])) ?>
                                    </time>
                                    <span class="text-ink-600">(<?= Fmt::e(Fmt::dateTime($rate['fetched_at'])) ?>)</span>
                                </dd>
                            </div>
                        </dl>

                        <p class="hint mt-3">
                            This figure is indicative. The binding rate is quoted on your deposit
                            address when you generate it, and is locked for
                            <?= Fmt::e((string) (int) round(\App\Lib\Config::int('payments.quote_lock', 3600) / 60)) ?> minutes
                            from that moment.
                        </p>
                    <?php else: ?>
                        <p class="hint">
                            Live rate unavailable right now. You will still get a firm,
                            time-limited quote when you generate a deposit address.
                        </p>
                    <?php endif; ?>

                    <ul class="mt-4 space-y-1.5 text-sm text-ink-400">
                        <li class="flex gap-2">
                            <span class="text-ink-600">&bull;</span>
                            Credited after <strong class="text-ink-200"><?= (int) $requiredConfirmations ?> confirmations</strong>.
                        </li>
                        <li class="flex gap-2">
                            <span class="text-ink-600">&bull;</span>
                            <?php if ($feeBps > 0): ?>
                                Processing fee <strong class="text-ink-200"><?= Fmt::e(number_format($feeBps / 100, 2)) ?>%</strong>,
                                deducted from the credited amount.
                            <?php else: ?>
                                <strong class="text-ink-200">No processing fee</strong> &mdash; you are credited the full converted value.
                            <?php endif; ?>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-ink-600">&bull;</span>
                            The Bitcoin network fee is paid by your wallet when you send, and goes to
                            miners rather than to us. Set it before you send &mdash; a low fee means a long wait.
                        </li>
                    </ul>
                </div>
            </section>

            <?php // ---------- Deposits table ---------- ?>
            <section class="card">
                <div class="flex items-center justify-between border-b border-ink-800 px-5 py-4">
                    <h2 class="font-display text-xl text-ink-100">Deposits</h2>
                </div>

                <?php if ($deposits === []): ?>
                    <p class="px-5 py-10 text-center text-sm text-ink-500">
                        No deposits yet. Your first top-up will appear here.
                    </p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Confirmations</th>
                                <th scope="col">Transaction</th>
                                <th scope="col">Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($deposits as $deposit): ?>
                                <?php
                                $status = (string) $deposit['status'];
                                $badge = match ($status) {
                                    'credited'  => 'badge-ok',
                                    'pending'   => 'badge-pending',
                                    'confirmed' => 'badge-pending',
                                    'underpaid' => 'badge-pending',
                                    default     => 'badge-muted',
                                };
                                $txid = is_string($deposit['txid'] ?? null) ? (string) $deposit['txid'] : '';
                                ?>
                                <tr>
                                    <td class="whitespace-nowrap">
                                        <a href="/account/wallet/deposit/<?= (int) $deposit['id'] ?>"
                                           class="text-ink-200 underline-offset-2 hover:text-ember-500 hover:underline">
                                            <?= Fmt::e(Fmt::date((string) $deposit['created_at'])) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="price text-ink-100">
                                            <?= Fmt::e(Fmt::money(
                                                $deposit['amount_minor_credited'] !== null
                                                    ? (int) $deposit['amount_minor_credited']
                                                    : (int) $deposit['amount_minor_requested']
                                            )) ?>
                                        </div>
                                        <div class="ident text-xs text-ink-600">
                                            <?= Fmt::e(Fmt::btc((string) ($deposit['amount_crypto'] ?? '0'))) ?> <?= Fmt::e((string) $deposit['asset']) ?>
                                        </div>
                                    </td>
                                    <td class="price text-ink-300">
                                        <?php if ($status === 'credited'): ?>
                                            <span class="text-mint-400">&check;</span>
                                        <?php else: ?>
                                            <?= (int) $deposit['confirmations'] ?> / <?= (int) $deposit['required_confirmations'] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= View::partial('partials/ident', [
                                            'value' => $txid,
                                            'head'  => 8,
                                            'tail'  => 6,
                                            'label' => 'transaction id',
                                            'href'  => $txid !== '' ? Ordinals::explorerTxUrl($txid) : '',
                                        ]) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= Fmt::e($badge) ?>">
                                            <?= Fmt::e(WalletController::statusLabel($status)) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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
