<?php

declare(strict_types=1);

/**
 * Ledger reconciliation.
 *
 *   php bin/reconcile.php            report drift, repair the cache
 *   php bin/reconcile.php --check    report only, change nothing
 *   php bin/reconcile.php --rebuild  rebuild every cached balance
 *
 * The same work runs nightly through /cron/run. This script exists for
 * the moment you actually need it: someone says a balance looks wrong and
 * you want an answer now, from a terminal, without waiting for cron.
 *
 * Exit code is non-zero when drift or a negative balance is found, so it
 * can be wired into a monitor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Lib\Fmt;
use App\Wallet;

$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);
$rebuild = in_array('--rebuild', $args, true);

if (!Database::isAvailable()) {
    fwrite(STDERR, "Cannot connect to the database.\n");
    exit(2);
}

echo "Reconciling ledger against cached balances\n\n";

$result = Wallet::reconcile(!$checkOnly);

printf("  Users checked      %d\n", $result['checked']);
printf("  Cache drift        %d\n", count($result['drift']));
printf("  Negative balances  %d\n", count($result['negative']));

if ($result['drift'] !== []) {
    echo "\n  Drift detected\n";
    printf("  %-8s %14s %14s %12s\n", 'USER', 'CACHED', 'LEDGER', 'DELTA');

    foreach ($result['drift'] as $row) {
        printf(
            "  %-8d %14s %14s %12s\n",
            $row['user_id'],
            Fmt::money($row['cached_balance']),
            Fmt::money($row['ledger_balance']),
            Fmt::moneySigned($row['delta'])
        );
    }

    echo $checkOnly
        ? "\n  Run without --check to repair the cache.\n"
        : "\n  Cache repaired. The ledger was already correct - only the cache was stale.\n";
}

if ($result['negative'] !== []) {
    // This one matters more than drift. A cached balance can be stale
    // harmlessly; a negative ledger balance means money left an account
    // that did not have it, which should be impossible.
    echo "\n  NEGATIVE BALANCES - investigate before taking more orders\n";

    foreach ($result['negative'] as $row) {
        printf("  user %-8d %s\n", $row['user_id'], Fmt::money($row['balance_minor']));
    }
}

if ($rebuild) {
    echo "\n  Rebuilding every cached balance...\n";

    $userIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        Database::all('SELECT id FROM users')
    );

    foreach ($userIds as $userId) {
        Wallet::rebuildCache($userId);
    }

    printf("  Rebuilt %d balances.\n", count($userIds));
}

if ($result['drift'] === [] && $result['negative'] === []) {
    echo "\n  Clean - every cached balance matches its ledger.\n";
}

exit($result['drift'] === [] && $result['negative'] === [] ? 0 : 1);
