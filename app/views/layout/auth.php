<?php

declare(strict_types=1);

/**
 * Narrow layout for the credential screens. No account nav, no balance -
 * nothing that implies a session the visitor does not have yet.
 *
 * @var string $content
 * @var list<array{type:string,message:string}> $flashes
 */

use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\Request;
use App\Lib\View;

$storeName = Config::string('app.name', 'Billions Store');

// Registration and login are the two screens a visitor with no session at
// all can reach - no marketplace nav, no footer links, nothing but the
// logo and the form, so the credential flow feels isolated from the
// marketplace rather than like one more page on the site. The other
// screens sharing this layout (forgot/reset password, 2FA, verify-email)
// keep the ordinary logo-left header and legal footer links, since only
// registration and login are named in the brief.
$isMinimalAuthPage = in_array(Request::path(), ['/login', '/register'], true);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex">
    <title><?= Fmt::e(($title ?? 'Sign in') . " \u{00B7} " . $storeName) ?></title>
    <link rel="stylesheet" href="<?= Fmt::e(View::asset('css/app.css')) ?>">
    <link rel="icon" href="<?= Fmt::e(View::asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" as="font" type="font/woff2" href="<?= Fmt::e(View::asset('fonts/inter-400.woff2')) ?>" crossorigin>
</head>
<body class="flex min-h-screen flex-col">

<header class="sticky top-0 z-40 border-b border-ink-800 bg-ink-950/85 backdrop-blur-xl">
    <div class="mx-auto flex h-16 w-full max-w-7xl items-center px-4 sm:px-6 lg:px-8 <?= $isMinimalAuthPage ? 'justify-center' : '' ?>">
        <a href="/" class="flex items-baseline gap-2">
            <span class="font-display text-2xl text-ink-100">Billions</span>
            <span class="font-mono text-[0.65rem] uppercase tracking-[0.2em] text-ember-500">Store</span>
        </a>
    </div>
</header>

<main class="flex flex-1 items-start justify-center px-4 py-12 sm:py-16">
    <div class="w-full max-w-md">
        <?php if (($flashes ?? []) !== []): ?>
            <div class="mb-6 flex flex-col gap-3">
                <?php foreach ($flashes as $flash): ?>
                    <?= View::partial('partials/flash', $flash) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </div>
</main>

<?php if (!$isMinimalAuthPage): ?>
    <footer class="border-t border-ink-800 py-6">
        <div class="mx-auto flex w-full max-w-7xl flex-wrap gap-x-5 gap-y-1 px-4 text-xs text-ink-600 sm:px-6 lg:px-8">
            <a href="/terms" class="hover:text-ink-300">Terms</a>
            <a href="/privacy" class="hover:text-ink-300">Privacy</a>
            <a href="/faq" class="hover:text-ink-300">FAQ</a>
        </div>
    </footer>
<?php endif; ?>

<script src="<?= Fmt::e(View::asset('js/app.js')) ?>" defer></script>
<?php if (($needsQr ?? false) === true): ?>
    <script src="<?= Fmt::e(View::asset('js/qrcode.min.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
