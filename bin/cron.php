<?php

declare(strict_types=1);

/**
 * Scheduled maintenance (CLI).
 *
 *   php bin/cron.php
 *
 * Runs exactly the same tasks as GET /cron/run?token=... - both call
 * App\Lib\Maintenance::run(). Use this form where the host gives you a
 * shell command in its cron panel; it does not need the web server to be
 * up, which is when you most want reconciliation to still happen.
 *
 * Exits non-zero if any task failed, so a monitoring cron can notice.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Lib\Maintenance;

$results = Maintenance::run();
$duration = $results['duration_ms'] ?? 0;
unset($results['duration_ms']);

$failed = 0;

foreach ($results as $task => $result) {
    if (is_string($result) && str_starts_with($result, 'error:')) {
        $failed++;
        fwrite(STDERR, sprintf("  %-26s %s\n", $task, $result));
        continue;
    }

    printf(
        "  %-26s %s\n",
        $task,
        is_array($result) ? json_encode($result, JSON_UNESCAPED_SLASHES) : (string) $result
    );
}

printf("\n  completed in %dms%s\n", $duration, $failed > 0 ? ", {$failed} task(s) failed" : '');

exit($failed > 0 ? 1 : 0);
