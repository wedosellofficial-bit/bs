<?php

declare(strict_types=1);

use App\Auth;
use App\Lib\Config;
use App\Lib\Fmt;

$year = gmdate('Y');
?>
<footer class="mt-20 border-t border-ink-800 bg-ink-950">
    <div class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="flex items-baseline gap-2">
                    <span class="font-display text-xl text-ink-100">Billions</span>
                    <span class="font-mono text-[0.6rem] uppercase tracking-[0.2em] text-ember-500">Store</span>
                </div>
                <p class="mt-3 max-w-xs text-sm leading-relaxed text-ink-500">
                    Curated Bitcoin Ordinals. Inscriptions are held in a project wallet
                    and transferred to your taproot address after purchase.
                </p>
            </div>

            <div>
                <h2 class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-500">Store</h2>
                <ul class="mt-4 space-y-2 text-sm">
                    <?php if (Auth::check()): ?>
                        <li><a href="/collection" class="text-ink-300 transition-colors hover:text-ink-100">Collection</a></li>
                    <?php endif; ?>
                    <li><a href="/about" class="text-ink-300 transition-colors hover:text-ink-100">About</a></li>
                    <li><a href="/faq" class="text-ink-300 transition-colors hover:text-ink-100">FAQ</a></li>
                </ul>
            </div>

            <div>
                <h2 class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-500">Legal</h2>
                <ul class="mt-4 space-y-2 text-sm">
                    <li><a href="/terms" class="text-ink-300 transition-colors hover:text-ink-100">Terms of sale</a></li>
                    <li><a href="/privacy" class="text-ink-300 transition-colors hover:text-ink-100">Privacy</a></li>
                </ul>
            </div>

            <div>
                <h2 class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-500">Chain</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Network</dt>
                        <dd class="ident"><?= Fmt::e(Config::string('chain.label', 'Bitcoin Ordinals')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Top-ups</dt>
                        <dd class="ident"><?= Fmt::e(Config::string('payments.asset', 'BTC')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Payouts</dt>
                        <dd class="ident">taproot only</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-2 border-t border-ink-800 pt-6 text-xs text-ink-600 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; <?= Fmt::e($year) ?> <?= Fmt::e(Config::string('app.name')) ?>. All sales are final once an inscription is transferred.</p>
            <p class="font-mono">Prices in <?= Fmt::e(Config::string('ledger.currency', 'USD')) ?>. Not investment advice.</p>
        </div>
    </div>
</footer>
