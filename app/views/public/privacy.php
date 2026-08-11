<?php
declare(strict_types=1);
use App\Lib\Config;
use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">Privacy</h1>
    <p class="mt-2 text-sm text-ink-500">Last updated <?= Fmt::e(gmdate('j F Y')) ?>.</p>

    <div class="mt-8 space-y-6 leading-relaxed text-ink-300">
        <p class="rounded-lg border border-amber-400/25 bg-amber-900/20 p-4 text-sm text-ink-200">
            Template text. Review it against the law that applies to you before publishing.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">What we store</h2>
        <ul class="list-disc space-y-1.5 pl-5">
            <li>Your email address, and a display name if you set one.</li>
            <li>A hash of your password. We never store the password itself.</li>
            <li>Your ledger: every credit and debit on your balance, including deposits credited manually and whatever note the admin who credited it attached.</li>
            <li>Payout addresses you supply, and the transaction ids of transfers to them.</li>
            <li>Sign-in times and the IP address of your most recent sign-in.</li>
        </ul>

        <h2 class="pt-2 font-display text-2xl text-ink-100">What we deliberately do not do</h2>
        <ul class="list-disc space-y-1.5 pl-5">
            <li>No third-party analytics, no advertising pixels, no tracking scripts.</li>
            <li>
                No third-party requests from pages that display an address, including our own
                deposit address and any payout address you supply. Fonts, scripts and QR codes are
                all served from this domain rather than a third-party service.
            </li>
            <li>Full addresses are truncated in our own application logs.</li>
            <li>We never ask for a seed phrase or private key, and never will.</li>
        </ul>

        <h2 class="pt-2 font-display text-2xl text-ink-100">Cookies</h2>
        <p>
            One cookie, for your login session. It is httponly, secure, and SameSite=Lax. There are
            no advertising or analytics cookies, so there is no consent banner to dismiss.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">On-chain data</h2>
        <p>
            Bitcoin transactions are public and permanent. Once we send an inscription to your
            address, that transfer is visible to anyone, forever, and neither of us can remove it.
        </p>

        <h2 class="pt-2 font-display text-2xl text-ink-100">Retention and requests</h2>
        <p>
            Financial records &mdash; your ledger, orders and deposits &mdash; are kept as long as
            we are required to keep them. To request a copy of your data or ask about deletion,
            contact <span class="ident text-ink-200"><?= Fmt::e(Config::string('mail.from_addr')) ?></span>.
        </p>
    </div>
</div>
