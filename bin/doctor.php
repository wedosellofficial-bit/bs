<?php

declare(strict_types=1);

/**
 * Preflight check.
 *
 *   php bin/doctor.php
 *
 * Answers "is this environment actually able to run the store" before you
 * find out one page at a time. Run it locally after setup, and again on
 * the server after uploading - most Hostinger deploy problems are a
 * missing extension, an unwritable storage directory, or a .env that was
 * never filled in, and all three show up here.
 *
 * Exit code 0 = ready, 1 = something is broken.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Deliberately does NOT require bootstrap.php up front. Half the point of
// this script is diagnosing a config that stops bootstrap from loading.
$root = dirname(__DIR__);

$pass = 0;
$warn = 0;
$fail = 0;

function report(string $state, string $label, string $detail = ''): void
{
    global $pass, $warn, $fail;

    $mark = match ($state) {
        'ok'   => "\033[32m  ok  \033[0m",
        'warn' => "\033[33m warn \033[0m",
        default => "\033[31m FAIL \033[0m",
    };

    match ($state) {
        'ok'    => $pass++,
        'warn'  => $warn++,
        default => $fail++,
    };

    printf("%s %-38s %s\n", $mark, $label, $detail);
}

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

echo "\n\033[1mBillions Store - preflight check\033[0m\n";

//---------------------------------------------------------------------
section('PHP');

PHP_VERSION_ID >= 80200
    ? report('ok', 'PHP version', PHP_VERSION)
    : report('fail', 'PHP version', PHP_VERSION . ' - needs 8.2 or newer');

$required = [
    'pdo_mysql' => 'database access',
    'gd'        => 'image processing for NFT uploads',
    'curl'      => 'talking to the payment provider',
    'mbstring'  => 'UTF-8 handling',
    'openssl'   => 'HTTPS and SMTP',
    'sodium'    => 'encrypting 2FA secrets at rest',
    'json'      => 'webhook parsing',
    'fileinfo'  => 'upload validation',
];

foreach ($required as $extension => $why) {
    extension_loaded($extension)
        ? report('ok', "ext: {$extension}")
        : report('fail', "ext: {$extension}", "missing - needed for {$why}");
}

if (extension_loaded('gd')) {
    $info = gd_info();
    ($info['WebP Support'] ?? false)
        ? report('ok', 'gd: WebP support')
        : report('warn', 'gd: WebP support', 'absent - uploads fall back to JPEG');
}

//---------------------------------------------------------------------
section('Layout');

// This tree is deployed as one unit - index.php, app/, bin/, storage/
// and resources/ all sit at the same level, because some hosting deploy
// tools (Hostinger's "deploy from GitHub" among them) clone a repository
// straight into the document root with no option to keep part of it
// outside. The boundary is enforced entirely by .htaccess: each of the
// four non-public directories must carry its own `Require all denied`,
// and losing that file quietly is the single most damaging deploy
// mistake this check can catch.

is_file($root . '/index.php')
    ? report('ok', 'front controller present')
    : report('fail', 'front controller present', 'index.php missing at the repo root');

is_file($root . '/.htaccess')
    ? report('ok', 'root .htaccess present')
    : report('fail', 'root .htaccess present', 'rewrites and deny rules missing');

foreach (['app', 'bin', 'storage', 'resources'] as $dir) {
    $htaccess = $root . '/' . $dir . '/.htaccess';

    if (!is_file($htaccess)) {
        report('fail', "{$dir}/.htaccess present", 'missing - this directory is currently web-reachable');
        continue;
    }

    str_contains((string) file_get_contents($htaccess), 'Require all denied')
        ? report('ok', "{$dir}/.htaccess denies access")
        : report('fail', "{$dir}/.htaccess denies access", 'file exists but has no deny directive');
}

// .env itself is checked properly in the Configuration section below
// (existence, permissions); it belongs at this same level, alongside
// index.php, not inside a separate public_html/.

// Only index.php should be executable PHP at the root - anything else
// there is either a leftover installer or a misplaced upload.
$strayPhp = array_values(array_filter(
    glob($root . '/*.php') ?: [],
    static fn (string $p): bool => basename($p) !== 'index.php'
));
$strayPhp === []
    ? report('ok', 'no stray .php at the root')
    : report('warn', 'no stray .php at the root', implode(', ', array_map('basename', $strayPhp)));

//---------------------------------------------------------------------
section('Compiled assets');

foreach ([
    'assets/css/app.css'      => 'stylesheet',
    'assets/js/app.js'        => 'interface script',
    'assets/js/qrcode.min.js' => 'QR renderer',
] as $path => $label) {
    $full = $root . '/' . $path;
    is_file($full) && filesize($full) > 0
        ? report('ok', $label, number_format(filesize($full) / 1024, 1) . ' kB')
        : report('fail', $label, "{$path} missing - run: npm run build");
}

$fonts = glob($root . '/assets/fonts/*.woff2') ?: [];
count($fonts) >= 7
    ? report('ok', 'self-hosted fonts', count($fonts) . ' files')
    : report('warn', 'self-hosted fonts', count($fonts) . ' of 7 - run: npm run fonts');

//---------------------------------------------------------------------
section('Storage');

foreach ([
    'storage', 'storage/nft', 'storage/nft/preview',
    'storage/product', 'storage/product/preview', 'storage/products',
    'storage/logs', 'storage/tmp',
] as $dir) {
    $full = $root . '/' . $dir;

    if (!is_dir($full)) {
        report('fail', $dir . '/', 'missing - create it');
        continue;
    }

    is_writable($full)
        ? report('ok', $dir . '/', 'writable')
        : report('fail', $dir . '/', 'not writable by PHP - chmod 755');
}

//---------------------------------------------------------------------
section('Configuration');

$envPath = $root . '/.env';

if (!is_file($envPath)) {
    report('fail', '.env exists', 'copy .env.example to .env and fill it in');
    echo "\n\033[31mStopping here - nothing else can be checked without .env.\033[0m\n\n";
    exit(1);
}

report('ok', '.env exists');

if (DIRECTORY_SEPARATOR === '/') {
    $mode = substr(sprintf('%o', fileperms($envPath)), -3);
    $mode === '600'
        ? report('ok', '.env permissions', '600')
        : report('warn', '.env permissions', $mode . ' - should be 600');
}

// Loading the app is itself a test: bootstrap fails loudly on a broken config.
require $root . '/app/bootstrap.php';

use App\Database;
use App\Lib\Config;
use App\Lib\Migrator;
use App\Ordinals;

$appEnv = Config::string('app.env');
report($appEnv === 'production' ? 'ok' : 'warn', 'APP_ENV', $appEnv
    . ($appEnv === 'production' ? '' : ' - errors are shown in the browser'));

$appKey = Config::string('app.key');
strlen($appKey) >= 32
    ? report('ok', 'APP_KEY', strlen($appKey) . ' bytes')
    : report('fail', 'APP_KEY', 'missing or too short - php -r "echo base64_encode(random_bytes(32));"');

$appUrl = Config::string('app.url');
if ($appUrl === '') {
    report('fail', 'APP_URL', 'not set - email links will be broken');
} elseif ($appEnv === 'production' && !str_starts_with($appUrl, 'https://')) {
    report('warn', 'APP_URL', $appUrl . ' - should be https in production');
} else {
    report('ok', 'APP_URL', $appUrl);
}

Config::string('cron.token') !== ''
    ? report('ok', 'CRON_TOKEN', 'set')
    : report('fail', 'CRON_TOKEN', 'not set - cron and the migrate endpoint are disabled');

// The live deposit path: one operator-held address shown on the wallet
// page. Getting this wrong misdirects every customer deposit, so it is
// checked for basic structural validity, not just presence - though
// that only catches a typo, not the wrong-but-valid address.
$depositAddress = Config::string('manual_deposit.btc_address');

if ($depositAddress === '') {
    report($appEnv === 'production' ? 'fail' : 'warn', 'Manual deposit address',
        'MANUAL_BTC_ADDRESS not set - the wallet page cannot show anything to send to');
} elseif (!Ordinals::isValidBitcoinAddress($depositAddress)) {
    report('fail', 'Manual deposit address',
        'MANUAL_BTC_ADDRESS does not look like a valid Bitcoin address - check it character by character');
} else {
    report('ok', 'Manual deposit address', 'set and structurally valid');
}

$transport = Config::string('mail.transport');
if ($transport === 'log') {
    report($appEnv === 'production' ? 'fail' : 'ok', 'Mail transport',
        'log - messages go to storage/logs/mail/' . ($appEnv === 'production' ? ' (wrong for production)' : ''));
} elseif (Config::string('mail.host') === '' || Config::string('mail.user') === '') {
    report('warn', 'Mail transport', 'smtp but host/user incomplete');
} else {
    report('ok', 'Mail transport', 'smtp via ' . Config::string('mail.host'));
}

Config::bool('chain.require_taproot', true)
    ? report('ok', 'Taproot payouts required', 'yes')
    : report('warn', 'Taproot payouts required', 'DISABLED - inscriptions can be sent to wallets that lose them');

// Membership gate mode. 'all' is a full paywall (this store's chosen
// default) - worth a loud confirmation, not a silent pass, since it is
// the difference between an open storefront and a closed one.
$gate = Config::string('membership.gate', 'all');
match ($gate) {
    'all'      => report('ok', 'MEMBERSHIP_GATE', 'all - full paywall, nothing browsable until membership'),
    'purchase' => report('ok', 'MEMBERSHIP_GATE', 'purchase - browsing open, buying requires membership'),
    'off'      => report('ok', 'MEMBERSHIP_GATE', 'off - membership is a pure optional upgrade'),
    default    => report('warn', 'MEMBERSHIP_GATE', "unrecognised value '{$gate}' - Router falls back to 'all'"),
};

//---------------------------------------------------------------------
section('Database');

if (!Database::isAvailable()) {
    report('fail', 'connection', 'cannot connect - check DB_HOST, DB_NAME, DB_USER, DB_PASS');
} else {
    report('ok', 'connection', Config::string('db.name') . ' on ' . Config::string('db.host'));

    try {
        $version = (string) Database::scalar('SELECT VERSION()', [], '');
        report('ok', 'server version', $version);
    } catch (Throwable) {
        report('warn', 'server version', 'could not read');
    }

    try {
        $status = (new Migrator())->status();
        $pending = array_filter($status, static fn (array $r): bool => $r['status'] === 'pending');
        $drifted = array_filter($status, static fn (array $r): bool => str_contains($r['status'], 'CHANGED'));

        if ($status === []) {
            report('fail', 'migrations', 'no migration files found');
        } elseif ($pending !== []) {
            report('fail', 'migrations', count($pending) . ' pending - run: php bin/migrate.php');
        } else {
            report('ok', 'migrations', count($status) . ' applied');
        }

        if ($drifted !== []) {
            report('warn', 'migration checksums', count($drifted) . ' file(s) changed after being applied');
        }
    } catch (Throwable $e) {
        report('fail', 'migrations', $e->getMessage());
    }

    // A store with no admin cannot be operated.
    try {
        $admins = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'", [], 0);
        $admins > 0
            ? report('ok', 'admin accounts', (string) $admins)
            : report('warn', 'admin accounts', "none - register, then: UPDATE users SET role='admin' WHERE email='...'");

        $listed = (int) Database::scalar("SELECT COUNT(*) FROM nfts WHERE status = 'listed'", [], 0);
        report($listed > 0 ? 'ok' : 'warn', 'listed inscriptions', (string) $listed);

        $listedProducts = (int) Database::scalar("SELECT COUNT(*) FROM products WHERE status = 'listed'", [], 0);
        report($listedProducts > 0 ? 'ok' : 'warn', 'listed products', (string) $listedProducts);

        // A listed product with no deliverable would let a purchase
        // succeed and leave the buyer with nothing to download -
        // ProductOrders::purchase() already refuses this at the money
        // layer, but it is worth surfacing before anyone hits it live.
        $missingDeliverable = (int) Database::scalar(
            "SELECT COUNT(*) FROM products WHERE status = 'listed' AND deliverable_path IS NULL",
            [],
            0
        );
        $missingDeliverable === 0
            ? report('ok', 'products missing a deliverable', '0')
            : report('fail', 'products missing a deliverable', "{$missingDeliverable} listed with no file attached");
    } catch (Throwable) {
        // Tables not created yet; the migration check above already said so.
    }
}

//---------------------------------------------------------------------
printf(
    "\n\033[1mResult\033[0m  %d ok, %d warning%s, %d failure%s\n",
    $pass,
    $warn,
    $warn === 1 ? '' : 's',
    $fail,
    $fail === 1 ? '' : 's'
);

if ($fail > 0) {
    echo "\n\033[31mNot ready. Fix the failures above.\033[0m\n\n";
    exit(1);
}

echo $warn > 0
    ? "\n\033[33mReady, with warnings worth reading.\033[0m\n\n"
    : "\n\033[32mReady.\033[0m\n\n";

exit(0);
