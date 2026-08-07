<?php
declare(strict_types=1);
use App\Lib\Config;
use App\Lib\Fmt;
$store = Config::string('app.name');
?>
<div class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">Terms of sale</h1>
    <p class="mt-2 text-sm text-ink-500">Last updated <?= Fmt::e(gmdate('j F Y')) ?>.</p>

    <div class="mt-8 space-y-6 leading-relaxed text-ink-300">
        <p class="rounded-lg border border-amber-400/25 bg-amber-900/20 p-4 text-sm text-ink-200">
            This is a template. Have a lawyer in your jurisdiction review it before you take real
            money. It is not legal advice and it is not complete.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">1. What you are buying</h2>
        <p>
            You are buying a specific Bitcoin Ordinals inscription, identified by its inscription
            id, which <?= Fmt::e($store) ?> holds at the time of sale. You are not buying
            copyright, trademark, or any licence to the underlying artwork unless stated
            separately in the item description.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">2. Store balance</h2>
        <p>
            Funds added to your account are a prepaid balance for purchases on this site. Balance
            is not a deposit, is not interest-bearing, and is not a claim on any specific bitcoin.
            Balance is denominated in <?= Fmt::e(Config::string('ledger.currency', 'USD')) ?>.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">3. Deposits</h2>
        <p>
            Deposits are credited after the confirmations stated at the time of the deposit. The
            exchange rate applied is the one quoted when your deposit address was generated, valid
            for the stated window. Payments arriving after that window, or for less than the
            quoted amount, are held for manual review and may be credited at a different rate or
            returned.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">4. Transfers are final</h2>
        <p>
            You are responsible for the payout address you supply. Bitcoin transactions cannot be
            reversed. An inscription sent to an address you do not control, or to a wallet that
            does not track ordinals, is not recoverable by us or by anyone else, and is not
            refundable.
        </p>
        <p>
            We require a taproot address for this reason. Supplying an address and confirming it
            at checkout is your acceptance of that risk.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">5. Refunds</h2>
        <p>
            Before an inscription is transferred, we may refund a purchase to your store balance at
            our discretion, for example if we can no longer deliver the item. After transfer, sales
            are final.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">6. Accounts</h2>
        <p>
            You must confirm your email address before adding funds or buying. You are responsible
            for keeping your credentials secure; we strongly recommend enabling two-step
            verification. We may suspend an account we reasonably believe is being used
            fraudulently.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">7. No investment advice</h2>
        <p>
            Nothing on this site is investment advice. Digital collectibles are volatile and may
            become worthless. Only spend what you can afford to lose.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">8. Liability</h2>
        <p>
            To the extent permitted by law, our total liability for any claim relating to a
            purchase is limited to the amount you paid for the item in question.
        </p>
    </div>
</div>
