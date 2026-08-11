<?php

declare(strict_types=1);

/**
 * Admin layout.
 *
 * The sidebar is presentation only. Every route it links to calls
 * Auth::requireAdmin() for itself - hiding a link is not access control.
 *
 * @var string $content
 */

use App\Auth;
use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\View;

$sections = [
    ['/admin', 'Overview'],
    ['/admin/transfers', 'Transfer queue'],
    ['/admin/orders', 'Orders'],
    ['/admin/inventory', 'Inventory'],
    ['/admin/products', 'Products'],
    ['/admin/products/categories', 'Categories & tags'],
    ['/admin/product-orders', 'Product orders'],
    // Manual BTC deposits are credited from a user's own page (Users ->
    // select a user -> Manual adjustment), not a separate Deposits
    // screen - see AdminUserController::manualCredit().
    ['/admin/users', 'Users'],
    ['/admin/announcements', 'Announcements'],
];
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Fmt::e(($title ?? 'Admin') . " \u{00B7} Admin \u{00B7} " . Config::string('app.name')) ?></title>
    <link rel="stylesheet" href="<?= Fmt::e(View::asset('css/app.css')) ?>">
    <link rel="icon" href="<?= Fmt::e(View::asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="min-h-screen">

<a class="skip-link" href="#main">Skip to content</a>

<div class="flex min-h-screen flex-col lg:flex-row">

    <aside class="border-b border-ink-800 bg-ink-900 lg:w-60 lg:shrink-0 lg:border-b-0 lg:border-r">
        <div class="flex h-16 items-center gap-2 px-5">
            <a href="/" class="font-display text-xl text-ink-100">Billions</a>
            <span class="badge badge-listed">Admin</span>
        </div>

        <nav class="flex gap-1 overflow-x-auto px-3 pb-3 lg:flex-col lg:overflow-visible" aria-label="Admin sections">
            <?php foreach ($sections as [$href, $label]): ?>
                <?php $active = $href === '/admin' ? View::isActive('/admin') && rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') === '/admin' : View::isActive($href); ?>
                <a href="<?= Fmt::e($href) ?>"
                   class="whitespace-nowrap rounded-md px-3 py-2 text-sm transition-colors <?= $active ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:bg-ink-850 hover:text-ink-100' ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>>
                    <?= Fmt::e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="mt-auto hidden border-t border-ink-800 p-3 lg:block">
            <a href="/" class="block rounded-md px-3 py-2 text-sm text-ink-500 hover:text-ink-200">&larr; Back to store</a>
            <form method="post" action="/logout">
                <?= Auth::csrfField() ?>
                <button class="w-full rounded-md px-3 py-2 text-left text-sm text-ink-500 hover:text-ink-200">Sign out</button>
            </form>
        </div>
    </aside>

    <main id="main" class="min-w-0 flex-1 px-4 py-8 sm:px-6 lg:px-8">
        <?php if (($flashes ?? []) !== []): ?>
            <div class="mb-6 flex flex-col gap-3">
                <?php foreach ($flashes as $flash): ?>
                    <?= View::partial('partials/flash', $flash) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </main>
</div>

<script src="<?= Fmt::e(View::asset('js/app.js')) ?>" defer></script>
</body>
</html>
