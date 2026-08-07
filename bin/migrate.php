<?php

declare(strict_types=1);

/**
 * Migration runner (CLI).
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list migrations and their state
 *   php bin/migrate.php --dry-run  show what would be applied
 *
 * On a Hostinger plan without SSH, use the web equivalent instead:
 *   https://your-domain/cron/migrate?token=CRON_TOKEN
 * See README.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Lib\Migrator;

$args = array_slice($argv, 1);
$migrator = new Migrator();

if (in_array('--status', $args, true)) {
    printf("%-44s %-22s %s\n", 'MIGRATION', 'APPLIED AT', 'STATUS');
    printf("%s\n", str_repeat('-', 84));

    foreach ($migrator->status() as $row) {
        printf(
            "%-44s %-22s %s\n",
            $row['migration'],
            $row['applied_at'] ?? '-',
            $row['status']
        );
    }

    exit(0);
}

$dryRun = in_array('--dry-run', $args, true);
$result = $migrator->migrate($dryRun);

foreach ($result['applied'] as $name) {
    echo "  applied  {$name}\n";
}
foreach ($result['skipped'] as $name) {
    echo "  ok       {$name}\n";
}
foreach ($result['drifted'] as $name) {
    // Not fatal, but it means the file on disk no longer describes the
    // schema that is actually deployed. Write a new migration instead of
    // editing an applied one.
    echo "  DRIFT    {$name} - file changed after it was applied\n";
}
foreach ($result['errors'] as $error) {
    fwrite(STDERR, "  FAILED   {$error}\n");
}

if ($result['applied'] === [] && $result['errors'] === []) {
    echo "\nNothing to do - schema is up to date.\n";
} elseif ($result['errors'] === []) {
    printf("\n%d migration(s) applied.\n", count($result['applied']));
}

exit($result['errors'] === [] ? 0 : 1);
