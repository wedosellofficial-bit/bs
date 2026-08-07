<?php

declare(strict_types=1);

/**
 * The send-payment screen.
 *
 * The QR is drawn into a <canvas> by the locally hosted qrcode.js. No
 * third-party QR image URL: that would send the user's deposit address
 * to someone else's access log, along with the referrer identifying this
 * store.
 *
 * @var array<string,mixed> $deposit
 * @var int $balance
 * @var string $explorerAddressUrl
 * @var string $explorerTxUrl
 * @var int $networkFee
 */

use App\Controllers\WalletController;
use App\Lib\Fmt;
use App\Lib\View;

// $needsQr is set by the controller (not here): template locals do not
// cross into the layout's scope, so the flag has to travel in the view
// data. It makes the layout load qrcode.min.js, which only this page and
// the 2FA enrolment screen need.

$address = (string) ($deposit['address'] ?? '');
$amountCrypto = (string) ($deposit['amount_crypto'] ?? '0');
$asset = (string) $deposit['asset'];
$status = (string) $deposit['status'];
$isOpen = $status === 'pending';

// BIP-21 URI. Wallets that scan this pre-fill both the address and the
// exact amount, which removes the most common cause of an underpayment.
$paymentUri = 'bitcoin:' . $address . '?amount=' . rawurlencode($amountCrypto);

$expiresAt = (string) ($deposit['quote_expires_at'] ?? '');
$expiresTs = $expiresAt !== '' ? strtotime($expiresAt . ' UTC') : 0;
?>

<div class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

    <a href="/account/wallet" class="inline-flex items-center gap-1.5 text-sm text-ink-500 transition-colors hover:text-ink-200">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <path d="M8.5 3.5 5 7l3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Wallet
    </a>

    <header class="mt-4 mb-8">
        <h1 class="font-display text-4xl text-ink-100">Send <?= Fmt::e($asset) ?></h1>
        <p class="mt-2 text-ink-400">
            Send exactly the amount below to the address below. Anything else needs manual review.
        </p>
    </header>

    <div class="grid gap-6 md:grid-cols-[auto_1fr]">

        <?php // ---------- QR ---------- ?>
        <div class="card-raised flex flex-col items-center p-5">
            <div class="rounded-lg bg-white p-3">
                <canvas id="deposit-qr"
                        width="220" height="220"
                        data-qr="<?= Fmt::e($paymentUri) ?>"
                        aria-label="QR code containing the payment address and amount"
                        role="img"></canvas>
                <noscript>
                    <p class="w-[220px] p-4 text-center text-xs text-ink-950">
                        Enable JavaScript to render the QR code, or copy the address below.
                    </p>
                </noscript>
            </div>
            <p class="mt-3 max-w-[220px] text-center text-xs text-ink-500">
                Scan with an ordinals-aware wallet. The amount is included.
            </p>
        </div>

        <?php // ---------- Details ---------- ?>
        <div class="min-w-0 space-y-5">

            <?php // --- amount --- ?>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Send exactly</p>
                <div class="mt-1.5 flex flex-wrap items-baseline gap-3">
                    <button type="button"
                            class="ident-copy price text-3xl text-ink-100"
                            data-copy="<?= Fmt::e($amountCrypto) ?>"
                            aria-label="Copy amount: <?= Fmt::e($amountCrypto) ?> <?= Fmt::e($asset) ?>">
                        <?= Fmt::e($amountCrypto) ?>
                        <span class="text-lg text-ink-500"><?= Fmt::e($asset) ?></span>
                    </button>
                </div>
                <p class="mt-1 text-sm text-ink-400">
                    for <span class="price text-ink-200"><?= Fmt::e(Fmt::money((int) $deposit['amount_minor_requested'])) ?></span>
                    of store balance
                </p>
            </div>

            <?php // --- address --- ?>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-[0.14em] text-ink-500">To this address</p>

                <div class="mt-2">
                    <?= View::partial('partials/ident', [
                        'value' => $address,
                        'head'  => 12,
                        'tail'  => 10,
                        'label' => 'deposit address',
                        'href'  => $explorerAddressUrl,
                    ]) ?>
                </div>

                <p class="hint mt-2">
                    Issued for this top-up only. Do not reuse it for a later deposit &mdash;
                    funds sent to an expired address need manual recovery.
                </p>
            </div>

            <?php // --- quote window --- ?>
            <div class="card p-5">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink-500">Rate locked at</dt>
                        <dd class="price mt-0.5 text-sm text-ink-200">
                            <?php if (($deposit['quoted_rate'] ?? null) !== null): ?>
                                1 <?= Fmt::e($asset) ?> =
                                <?= Fmt::e(Fmt::money((int) round(((float) $deposit['quoted_rate']) * 100))) ?>
                            <?php else: ?>
                                <?= Fmt::e(Fmt::EM_DASH) ?>
                            <?php endif; ?>
                        </dd>
                        <dd class="mt-0.5 text-xs text-ink-600">
                            quoted <?= Fmt::e(Fmt::dateTime((string) ($deposit['quoted_at'] ?? ''))) ?>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink-500">Quote valid until</dt>
                        <dd class="price mt-0.5 text-sm <?= $isOpen ? 'text-ink-200' : 'text-ink-500' ?>"
                            data-countdown="<?= (int) $expiresTs ?>">
                            <?= Fmt::e(Fmt::dateTime($expiresAt)) ?>
                        </dd>
                        <dd class="mt-0.5 text-xs text-ink-600">
                            After this, send nothing &mdash; start a new top-up instead.
                        </dd>
                    </div>
                </dl>
            </div>

            <?php // --- fees, stated before sending --- ?>
            <div class="card p-5">
                <h2 class="text-sm font-semibold text-ink-100">Before you send</h2>
                <ul class="mt-3 space-y-2 text-sm text-ink-400">
                    <li class="flex justify-between gap-4">
                        <span>Credited after</span>
                        <span class="price text-ink-200"><?= (int) $deposit['required_confirmations'] ?> confirmations</span>
                    </li>
                    <li class="flex justify-between gap-4">
                        <span>Store processing fee</span>
                        <span class="price text-ink-200">
                            <?php $bps = \App\Lib\Config::int('payments.fee_bps', 0); ?>
                            <?= $bps > 0 ? Fmt::e(number_format($bps / 100, 2)) . '%' : 'None' ?>
                        </span>
                    </li>
                    <li class="flex justify-between gap-4">
                        <span>Bitcoin network fee</span>
                        <span class="text-ink-200">Paid by your wallet, to miners</span>
                    </li>
                </ul>
                <p class="hint mt-3">
                    Send the exact amount. An underpayment is held for manual review rather
                    than credited at a stale rate, and that takes longer than getting it right first time.
                </p>
            </div>
        </div>
    </div>

    <?php // ---------- Live status ---------- ?>
    <section class="card mt-6 p-5"
             data-deposit-status
             data-deposit-id="<?= (int) $deposit['id'] ?>"
             data-final="<?= $isOpen ? 'false' : 'true' ?>">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-ink-100">Status</h2>
                <p class="mt-1 flex items-center gap-2">
                    <span class="badge <?= $status === 'credited' ? 'badge-ok' : ($status === 'pending' ? 'badge-pending' : 'badge-muted') ?>"
                          data-status-badge>
                        <?= Fmt::e(WalletController::statusLabel($status)) ?>
                    </span>
                    <span class="price text-sm text-ink-400" data-status-confirmations>
                        <?php if ($status !== 'credited'): ?>
                            <?= (int) $deposit['confirmations'] ?> / <?= (int) $deposit['required_confirmations'] ?> confirmations
                        <?php endif; ?>
                    </span>
                </p>
            </div>

            <div class="text-right">
                <p class="text-xs uppercase tracking-wider text-ink-500">Balance</p>
                <p class="price text-xl text-ink-100" data-status-balance><?= Fmt::e(Fmt::money($balance)) ?></p>
            </div>
        </div>

        <div class="mt-4 border-t border-ink-800 pt-4 text-sm" data-status-tx>
            <?php if (($deposit['txid'] ?? '') !== ''): ?>
                <span class="text-ink-500">Transaction </span>
                <?= View::partial('partials/ident', [
                    'value' => (string) $deposit['txid'],
                    'head'  => 10,
                    'tail'  => 8,
                    'label' => 'transaction id',
                    'href'  => $explorerTxUrl,
                ]) ?>
            <?php else: ?>
                <span class="text-ink-500">
                    Waiting for the transaction to appear on the network. This page updates itself
                    &mdash; you do not need to refresh.
                </span>
            <?php endif; ?>
        </div>
    </section>
</div>
