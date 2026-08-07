<?php
declare(strict_types=1);
use App\Lib\Config;
use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">About</h1>

    <div class="mt-8 space-y-6 leading-relaxed text-ink-300">
        <p>
            <?= Fmt::e(Config::string('app.name')) ?> is a small, curated storefront for Bitcoin
            Ordinals. We buy inscriptions, hold them in a project wallet, and sell them on at a
            fixed price in <?= Fmt::e(Config::string('ledger.currency', 'USD')) ?>.
        </p>

        <h2 class="pt-4 font-display text-2xl text-ink-100">Custody</h2>
        <p>
            Every inscription listed here is already in our wallet. There is no minting, no
            pre-sale, and nothing is listed that we do not hold. When you buy, we transfer the
            inscription to the taproot address you give us.
        </p>

        <h2 class="pt-4 font-display text-2xl text-ink-100">Why transfers are manual</h2>
        <p>
            Sending an inscription needs a signing key. Automating it would mean keeping that key
            on the web server, and a server compromise would then empty the entire collection in a
            single transaction. So we do not keep a key here at all: an admin sends each transfer
            from a wallet held elsewhere and records the transaction id against your order.
        </p>
        <p>
            The cost is that transfers are not instant &mdash; normally within one business day.
            We think that is the right trade for something that cannot be undone.
        </p>

        <h2 class="pt-4 font-display text-2xl text-ink-100">Why taproot only</h2>
        <p>
            An inscription lives on one specific satoshi. Wallets that do not track ordinals treat
            that satoshi as ordinary change, and can spend it as a network fee &mdash; destroying
            the inscription. Every ordinals-aware wallet receives to a taproot address, so
            requiring one is the closest check we can make that your wallet will not lose what we
            send it.
        </p>

        <h2 class="pt-4 font-display text-2xl text-ink-100">Balances</h2>
        <p>
            You top up in BTC and spend a store balance. Your balance is the sum of an append-only
            ledger &mdash; every credit and debit is a permanent line you can read on your
            statement. Nothing is ever edited or deleted; a correction is a new entry sitting next
            to the thing it corrects.
        </p>
    </div>
</div>
