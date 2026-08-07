<?php

declare(strict_types=1);

/**
 * Seed the database with sample data for local development.
 *
 *   php bin/seed.php               add sample data (idempotent)
 *   php bin/seed.php --fresh       delete existing sample data first
 *
 * Generates its own artwork with GD - deterministic pixel sigils derived
 * from each inscription id - so there are no binary image files in the
 * repository and every run produces the same collection.
 *
 * Refuses to run when APP_ENV=production. Seeding a live store with fake
 * inscriptions and a known admin password is not a mistake you get to
 * make twice.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Database;
use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\ImageStore;
use App\Nft;
use App\Wallet;

if (Config::string('app.env') === 'production') {
    fwrite(STDERR, "Refusing to seed: APP_ENV is production.\n");
    exit(1);
}

if (!Database::isAvailable()) {
    fwrite(STDERR, "Cannot connect to the database. Check .env, then run bin/migrate.php first.\n");
    exit(1);
}

$fresh = in_array('--fresh', array_slice($argv, 1), true);

echo "Seeding " . Config::string('app.name') . "\n\n";

//---------------------------------------------------------------------
// Optional reset
//---------------------------------------------------------------------

if ($fresh) {
    echo "  Removing existing sample data...\n";

    // Order matters: children before parents, because the foreign keys
    // are RESTRICT where a financial record is involved.
    Database::pdo()->exec('DELETE FROM transfer_queue');
    Database::pdo()->exec('DELETE FROM orders');
    Database::pdo()->exec('DELETE FROM nft_attributes');
    Database::pdo()->exec('DELETE FROM nfts');
    Database::pdo()->exec('DELETE FROM collections');
    Database::pdo()->exec('DELETE FROM wallet_entries');
    Database::pdo()->exec('DELETE FROM wallet_balance_cache');
    Database::pdo()->exec('DELETE FROM deposits');
    Database::pdo()->exec("DELETE FROM users WHERE email LIKE '%@example.com'");
}

//---------------------------------------------------------------------
// Users
//---------------------------------------------------------------------

/**
 * Create (or find) a user. Returns the id.
 */
function seedUser(string $email, string $password, string $role, ?string $displayName = null): int
{
    $existing = Database::first('SELECT id FROM users WHERE email = ?', [$email]);

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    Database::run(
        'INSERT INTO users (email, password_hash, display_name, role, status, email_verified_at, created_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        [$email, Auth::hashPassword($password), $displayName, $role, 'active']
    );

    return Database::lastInsertId();
}

$adminEmail = Config::string('seed.admin_email', 'admin@example.com');
$adminPassword = Config::string('seed.admin_password');

if ($adminPassword === '') {
    // A known default would be one deploy away from being a live admin
    // account with a published password.
    $adminPassword = bin2hex(random_bytes(9));
    echo "  SEED_ADMIN_PASSWORD was not set; generated one for this run.\n";
}

$adminId = seedUser($adminEmail, $adminPassword, 'admin', 'Store admin');
$buyerId = seedUser('buyer@example.com', 'correct-horse-battery-staple', 'user', 'Test Buyer');

echo "  Admin  {$adminEmail}\n";
echo "  Buyer  buyer@example.com / correct-horse-battery-staple\n";

//---------------------------------------------------------------------
// Balance for the test buyer
//---------------------------------------------------------------------

if (Wallet::balance($buyerId) === 0) {
    Wallet::withUserLock($buyerId, static function () use ($buyerId, $adminId): void {
        Wallet::credit(
            $buyerId,
            250_000, // $2,500.00
            Wallet::TYPE_ADJUSTMENT,
            'manual',
            1,
            'Opening balance for local testing',
            $adminId
        );
    });

    echo '  Credited ' . Fmt::money(250_000) . " to the test buyer\n";
}

//---------------------------------------------------------------------
// Collections
//---------------------------------------------------------------------

/** @return array<string,int> slug => id */
function seedCollections(array $definitions): array
{
    $ids = [];

    foreach ($definitions as $order => [$slug, $name, $description]) {
        $existing = Database::first('SELECT id FROM collections WHERE slug = ?', [$slug]);

        if ($existing !== null) {
            $ids[$slug] = (int) $existing['id'];
            continue;
        }

        Database::run(
            'INSERT INTO collections (slug, name, description, sort_order, is_visible, created_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP())',
            [$slug, $name, $description, $order]
        );

        $ids[$slug] = Database::lastInsertId();
    }

    return $ids;
}

$collections = seedCollections([
    ['sigil-runes', 'Sigil Runes', 'Ninety-six generated sigils, each inscribed on a single sat in the first inscription epoch.'],
    ['ember-glyphs', 'Ember Glyphs', 'A smaller, warmer set. Higher trait variance and a heavier hand with the palette.'],
]);

echo '  ' . count($collections) . " collections\n";

//---------------------------------------------------------------------
// Artwork
//---------------------------------------------------------------------

/**
 * Draw a deterministic pixel sigil for an inscription.
 *
 * The pattern is derived from the inscription id, mirrored down the
 * vertical axis, so the same id always produces the same image and the
 * set looks like a coherent generative collection rather than noise.
 */
function drawSigil(string $inscriptionId, array $palette, string $outputPath): void
{
    $grid = 11;
    $cell = 48;
    $size = $grid * $cell;

    $image = imagecreatetruecolor($size, $size);

    $hex = static fn (string $rgb): array => [
        hexdec(substr($rgb, 0, 2)),
        hexdec(substr($rgb, 2, 2)),
        hexdec(substr($rgb, 4, 2)),
    ];

    [$br, $bg, $bb] = $hex($palette['background']);
    $background = imagecolorallocate($image, $br, $bg, $bb);
    imagefilledrectangle($image, 0, 0, $size, $size, $background);

    [$fr, $fg, $fb] = $hex($palette['ink']);
    $ink = imagecolorallocate($image, $fr, $fg, $fb);

    [$ar, $ag, $ab] = $hex($palette['accent']);
    $accent = imagecolorallocate($image, $ar, $ag, $ab);

    // 32 bytes of deterministic entropy, one byte per cell decision.
    $bytes = hash('sha256', $inscriptionId, true);
    $half = (int) ceil($grid / 2);

    for ($y = 0; $y < $grid; $y++) {
        for ($x = 0; $x < $half; $x++) {
            $byte = ord($bytes[($y * $half + $x) % strlen($bytes)]);

            if ($byte % 100 < 42) {
                continue;
            }

            $colour = $byte % 7 === 0 ? $accent : $ink;

            foreach ([$x, $grid - 1 - $x] as $mirroredX) {
                imagefilledrectangle(
                    $image,
                    $mirroredX * $cell,
                    $y * $cell,
                    ($mirroredX + 1) * $cell - 1,
                    ($y + 1) * $cell - 1,
                    $colour
                );
            }
        }
    }

    imagepng($image, $outputPath);
    imagedestroy($image);
}

//---------------------------------------------------------------------
// Inscriptions
//---------------------------------------------------------------------

$traitPools = [
    'Background' => ['Void', 'Ember', 'Ash', 'Rust', 'Bone', 'Cobalt'],
    'Form'       => ['Lattice', 'Spire', 'Knot', 'Halo', 'Cascade'],
    'Ink'        => ['Iron', 'Gold', 'Copper', 'Chalk'],
    'Epoch'      => ['First', 'Second', 'Third'],
    'Anomaly'    => ['None', 'None', 'None', 'None', 'Inverted', 'Doubled', 'Burnt'],
];

$palettes = [
    'Void'   => ['background' => '0b0b10', 'ink' => 'e8e8ef', 'accent' => 'f2a03d'],
    'Ember'  => ['background' => '2a1408', 'ink' => 'f7c489', 'accent' => 'f2555a'],
    'Ash'    => ['background' => '1a1a1f', 'ink' => 'a8a8b8', 'accent' => '7dd3c0'],
    'Rust'   => ['background' => '2b1410', 'ink' => 'd98b5f', 'accent' => 'e3b341'],
    'Bone'   => ['background' => '1f1d18', 'ink' => 'e6dfd0', 'accent' => 'd9821f'],
    'Cobalt' => ['background' => '0d1524', 'ink' => 'a9c6f0', 'accent' => '7dd3c0'],
];

$existingCount = (int) Database::scalar('SELECT COUNT(*) FROM nfts', [], 0);

if ($existingCount > 0) {
    echo "  {$existingCount} inscriptions already present - skipping generation.\n";
} else {
    $tempDir = Config::string('app.storage') . '/tmp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0750, true);
    }

    // Deterministic across runs, so a reseeded database looks the same.
    mt_srand(20260807);

    $plan = [
        ['sigil-runes', 42, 4_000, 60_000],
        ['ember-glyphs', 18, 25_000, 240_000],
    ];

    $created = 0;

    foreach ($plan as [$slug, $count, $minPrice, $maxPrice]) {
        for ($index = 0; $index < $count; $index++) {
            // A realistic-looking inscription id: 64 hex + i + index.
            $txid = bin2hex(pack('N*', mt_rand(), mt_rand(), mt_rand(), mt_rand(),
                                          mt_rand(), mt_rand(), mt_rand(), mt_rand()));
            $inscriptionId = $txid . 'i0';

            $attributes = [];
            foreach ($traitPools as $traitType => $values) {
                $attributes[] = [
                    'trait_type' => $traitType,
                    'value'      => $values[mt_rand(0, count($values) - 1)],
                ];
            }

            $backgroundTrait = $attributes[0]['value'];
            $palette = $palettes[$backgroundTrait] ?? $palettes['Void'];

            $tempFile = $tempDir . '/seed-' . $inscriptionId . '.png';
            drawSigil($inscriptionId, $palette, $tempFile);

            // Runs the real intake pipeline: magic-byte check, GD
            // re-encode, storage outside the web root.
            $stored = ImageStore::storeFromPath($tempFile);
            @unlink($tempFile);

            $number = 12_000_000 + mt_rand(0, 900_000);
            $price = mt_rand($minPrice, $maxPrice);
            // Round to a sensible-looking figure rather than $417.83.
            $price = (int) (round($price / 500) * 500);

            Database::run(
                'INSERT INTO nfts
                    (token_id, chain, inscription_number, sat_ordinal, collection_id, name, description,
                     image_path, preview_path, image_mime, image_width, image_height,
                     attributes, price_minor, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (UTC_TIMESTAMP() - INTERVAL ? MINUTE))',
                [
                    $inscriptionId,
                    'bitcoin-ordinals',
                    $number,
                    mt_rand(100_000_000, 1_900_000_000_000_000),
                    $collections[$slug],
                    ucfirst($attributes[1]['value']) . ' ' . ucfirst($attributes[0]['value']) . ' #' . ($index + 1),
                    'A generated sigil inscribed on a single satoshi. Part of the '
                        . ($slug === 'sigil-runes' ? 'Sigil Runes' : 'Ember Glyphs') . ' set.',
                    $stored['image_path'],
                    $stored['preview_path'],
                    $stored['mime'],
                    $stored['width'],
                    $stored['height'],
                    json_encode($attributes, JSON_UNESCAPED_SLASHES),
                    $price,
                    'listed',
                    mt_rand(0, 60 * 24 * 45),
                ]
            );

            Nft::syncAttributes(Database::lastInsertId());
            $created++;

            if ($created % 10 === 0) {
                echo "  ...{$created} inscriptions\n";
            }
        }
    }

    echo "  {$created} inscriptions generated\n";
}

//---------------------------------------------------------------------
// Rarity
//---------------------------------------------------------------------

$scored = Nft::recomputeRarity();
echo "  Rarity computed for {$scored} items\n";

//---------------------------------------------------------------------
// A sample announcement
//---------------------------------------------------------------------

if ((int) Database::scalar('SELECT COUNT(*) FROM announcements', [], 0) === 0) {
    Database::run(
        'INSERT INTO announcements (title, body, level, published_at, created_by, created_at)
         VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())',
        [
            'Transfers are sent by hand',
            "Every inscription is sent manually from the project wallet, normally within one "
            . "business day of purchase.\n\nThere is no signing key on the web server, which is "
            . "deliberate: a compromise here cannot move a single inscription.",
            'info',
            $adminId,
        ]
    );

    echo "  1 announcement\n";
}

//---------------------------------------------------------------------

echo "\nDone.\n\n";
echo "  Sign in at /login\n";
echo "    admin  {$adminEmail}  /  " . ($adminPassword === Config::string('seed.admin_password')
        ? '(from SEED_ADMIN_PASSWORD)'
        : $adminPassword) . "\n";
echo "    buyer  buyer@example.com  /  correct-horse-battery-staple\n\n";
echo "  Change the admin password after first sign-in, then blank SEED_ADMIN_PASSWORD in .env.\n";
