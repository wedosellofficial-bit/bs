<?php
declare(strict_types=1);
use App\Lib\Config;
use App\Lib\Fmt;

$faqs = [
    ['How do I pay?', 'Send BTC to the deposit address shown on your wallet page. It is a single permanent address, the same one for every customer - not a fresh one generated per top-up.'],
    ['When is my deposit credited?', 'By hand, after an admin checks your transaction on a block explorer and confirms it has settled. This is not automatic, so it will not appear the moment your transaction confirms - allow up to a day, and contact support with your transaction id if it has been longer.'],
    ['What if I send the wrong amount?', 'Whatever amount arrives is what gets credited, converted at the rate on the day it is reviewed. There is no quote to over- or under-pay against - just send what you want to add.'],
    ['Which wallet addresses can receive an inscription?', 'Taproot addresses only, starting bc1p. Use the Ordinals receive address from Xverse, Leather, Unisat or a similar ordinals-aware wallet. Do not use an exchange deposit address: exchanges do not track inscriptions and yours would very likely be lost.'],
    ['How long does a transfer take?', 'Transfers are sent by hand, normally within one business day. You can follow the transaction on a block explorer from your order page as soon as it is broadcast.'],
    ['Can I cancel or get a refund?', 'Before the inscription is sent, yes - contact support and we will refund your balance. Once it has been transferred on-chain it belongs to you and cannot be reversed by anyone, including us.'],
    ['Can I withdraw my balance as BTC?', 'Not automatically. Store balance is for buying inscriptions. If you have a balance you want back, contact support.'],
    ['Do you take custody of my wallet?', 'No. We never ask for a seed phrase, a private key, or a wallet connection. The only thing we need is a receive address.'],
];
?>
<div class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">FAQ</h1>

    <div class="mt-8 divide-y divide-ink-800 border-y border-ink-800">
        <?php foreach ($faqs as [$question, $answer]): ?>
            <details class="group py-4">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                    <h2 class="text-base font-medium text-ink-100"><?= Fmt::e($question) ?></h2>
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"
                         class="shrink-0 text-ink-500 transition-transform group-open:rotate-180">
                        <path d="M3.5 5.5 7 9l3.5-3.5" stroke="currentColor" stroke-width="1.5"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </summary>
                <p class="mt-3 pr-8 leading-relaxed text-ink-400"><?= Fmt::e($answer) ?></p>
            </details>
        <?php endforeach; ?>
    </div>

    <p class="mt-10 text-sm text-ink-500">
        Something not covered here? Reach us at
        <span class="ident text-ink-300"><?= Fmt::e(Config::string('mail.from_addr')) ?></span>.
    </p>
</div>
