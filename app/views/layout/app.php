<?php

declare(strict_types=1);

/**
 * Main layout.
 *
 * @var string $content
 * @var list<array{type:string,message:string}> $flashes
 * @var array<string,mixed>|null $currentUser
 * @var string|null $title
 */

use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\Request;
use App\Lib\View;

$storeName = Config::string('app.name', 'Billions Store');

// The signup popup is a nudge for guests on the handful of pages they
// can actually reach (the registration landing page and public info
// pages) - never for a signed-in visitor, and never on the auth pages
// themselves (which render through layout/auth.php, not this layout,
// but the exclusion is kept here too as a second line of defence) or
// legal/support pages, per the brief.
$signupPopupExcludedPaths = [
    '/login', '/register', '/logout',
    '/verify-email', '/verify-email/resend',
    '/forgot-password', '/reset-password', '/login/2fa',
    '/terms', '/privacy', '/faq', '/support',
];
$showSignupPopup = ($currentUser ?? null) === null
    && !in_array(Request::path(), $signupPopupExcludedPaths, true);
$pageTitle = ($title ?? null) !== null
    ? $title . " \u{00B7} " . $storeName
    : $storeName . " \u{00B7} Bitcoin Ordinals";
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= Fmt::e($pageTitle) ?></title>
    <meta name="description" content="<?= Fmt::e($metaDescription ?? 'A curated store for Bitcoin Ordinals inscriptions. Buy with store balance, topped up in BTC.') ?>">
    <meta name="theme-color" content="#08080a">
    <link rel="stylesheet" href="<?= Fmt::e(View::asset('css/app.css')) ?>">
    <link rel="icon" href="<?= Fmt::e(View::asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <?php // Preload the two faces used above the fold; the rest can swap in. ?>
    <link rel="preload" as="font" type="font/woff2" href="<?= Fmt::e(View::asset('fonts/inter-400.woff2')) ?>" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="<?= Fmt::e(View::asset('fonts/instrument-serif-400.woff2')) ?>" crossorigin>
</head>
<body class="min-h-screen flex flex-col">

<a class="skip-link" href="#main">Skip to content</a>

<?= View::partial('partials/header', ['currentUser' => $currentUser ?? null]) ?>

<main id="main" class="flex-1">
    <?php if (($flashes ?? []) !== []): ?>
        <div class="mx-auto w-full max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-3">
                <?php foreach ($flashes as $flash): ?>
                    <?= View::partial('partials/flash', $flash) ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?= $content ?>
</main>

<?= View::partial('partials/footer') ?>

<?php if ($showSignupPopup): ?>
    <?= View::partial('partials/signup-popup') ?>
<?php endif; ?>

<script src="<?= Fmt::e(View::asset('js/app.js')) ?>" defer></script>
<?php if (($needsQr ?? false) === true): ?>
    <script src="<?= Fmt::e(View::asset('js/qrcode.min.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
