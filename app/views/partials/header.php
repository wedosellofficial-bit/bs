<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $currentUser */

use App\Auth;
use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\View;
use App\Wallet;

$user = $currentUser ?? null;
$balance = $user !== null ? Wallet::balance((int) $user['id']) : null;

$nav = [
    ['/collection', 'Collection'],
    ['/about', 'About'],
    ['/faq', 'FAQ'],
];
?>
<header class="sticky top-0 z-40 border-b border-ink-800 bg-ink-950/90 backdrop-blur">
    <div class="mx-auto flex h-16 w-full max-w-7xl items-center gap-4 px-4 sm:px-6 lg:px-8">

        <a href="/" class="flex items-baseline gap-2 shrink-0" aria-label="<?= Fmt::e(Config::string('app.name')) ?> home">
            <span class="font-display text-2xl tracking-tight text-ink-100">Billions</span>
            <span class="hidden font-mono text-[0.65rem] uppercase tracking-[0.2em] text-ember-500 sm:inline">Store</span>
        </a>

        <nav class="ml-4 hidden items-center gap-1 md:flex" aria-label="Main">
            <?php foreach ($nav as [$href, $label]): ?>
                <a href="<?= Fmt::e($href) ?>"
                   class="rounded-md px-3 py-2 text-sm transition-colors <?= View::isActive($href) ? 'bg-ink-850 text-ink-100' : 'text-ink-400 hover:text-ink-100' ?>"
                   <?= View::isActive($href) ? 'aria-current="page"' : '' ?>>
                    <?= Fmt::e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ml-auto flex items-center gap-2">
            <?php if ($user === null): ?>
                <a href="/login" class="btn btn-ghost btn-sm">Sign in</a>
                <a href="/register" class="btn btn-primary btn-sm">Create account</a>
            <?php else: ?>
                <a href="/account/wallet"
                   class="hidden items-center gap-2 rounded-lg border border-ink-800 bg-ink-900 px-3 py-1.5 transition-colors hover:border-ink-700 sm:flex">
                    <span class="text-[0.65rem] uppercase tracking-wider text-ink-500">Balance</span>
                    <span class="price text-sm text-ink-100"><?= Fmt::e(Fmt::money($balance ?? 0)) ?></span>
                </a>

                <?php if (Auth::isAdmin()): ?>
                    <a href="/admin" class="btn btn-ghost btn-sm hidden lg:inline-flex">Admin</a>
                <?php endif; ?>

                <?php
                // A details/summary menu rather than a JS dropdown: it is
                // keyboard accessible and closes on Escape without any
                // script at all.
                ?>
                <details class="relative" data-menu>
                    <summary class="btn btn-secondary btn-sm list-none cursor-pointer">
                        <span class="max-w-[9rem] truncate">
                            <?= Fmt::e($user['display_name'] ?: explode('@', (string) $user['email'])[0]) ?>
                        </span>
                        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" fill="none">
                            <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </summary>

                    <div class="card-raised absolute right-0 top-full z-50 mt-2 w-56 overflow-hidden p-1">
                        <?php
                        $accountNav = [
                            ['/account', 'Dashboard'],
                            ['/account/wallet', 'Wallet'],
                            ['/account/nfts', 'My inscriptions'],
                            ['/account/orders', 'Orders'],
                            ['/account/settings', 'Settings'],
                        ];
                        foreach ($accountNav as [$href, $label]):
                            ?>
                            <a href="<?= Fmt::e($href) ?>"
                               class="block rounded-md px-3 py-2 text-sm text-ink-300 transition-colors hover:bg-ink-800 hover:text-ink-100">
                                <?= Fmt::e($label) ?>
                            </a>
                        <?php endforeach; ?>

                        <?php if (Auth::isAdmin()): ?>
                            <a href="/admin"
                               class="block rounded-md px-3 py-2 text-sm text-ember-400 transition-colors hover:bg-ink-800 lg:hidden">
                                Admin
                            </a>
                        <?php endif; ?>

                        <form method="post" action="/logout" class="mt-1 border-t border-ink-800 pt-1">
                            <?= Auth::csrfField() ?>
                            <button type="submit"
                                    class="w-full rounded-md px-3 py-2 text-left text-sm text-ink-400 transition-colors hover:bg-ink-800 hover:text-ink-100">
                                Sign out
                            </button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </div>

    <?php // Mobile nav row, below the bar rather than behind a hamburger. ?>
    <nav class="flex gap-1 overflow-x-auto border-t border-ink-800 px-4 py-2 md:hidden" aria-label="Main, mobile">
        <?php foreach ($nav as [$href, $label]): ?>
            <a href="<?= Fmt::e($href) ?>"
               class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm <?= View::isActive($href) ? 'bg-ink-850 text-ink-100' : 'text-ink-400' ?>">
                <?= Fmt::e($label) ?>
            </a>
        <?php endforeach; ?>
        <?php if ($user !== null): ?>
            <a href="/account" class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm <?= View::isActive('/account') ? 'bg-ink-850 text-ink-100' : 'text-ink-400' ?>">Account</a>
        <?php endif; ?>
    </nav>
</header>
