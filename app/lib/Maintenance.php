<?php

declare(strict_types=1);

namespace App\Lib;

use App\Database;
use App\Nft;
use App\Payments;
use App\Wallet;
use Throwable;

/**
 * Scheduled maintenance tasks.
 *
 * Lives in its own class so it has two entry points that share one
 * implementation: `/cron/run?token=...` for Hostinger plans where the
 * cron panel can only fetch a URL, and `php bin/cron.php` where a shell
 * command is available. The CLI path does not depend on the web server
 * being up, which is exactly when you want reconciliation to still run.
 *
 * Every task is idempotent and safe to run more often than scheduled.
 * There are no long-running workers here by design - shared hosting kills
 * background processes, and a queue worker that silently dies is worse
 * than no worker at all.
 */
final class Maintenance
{
    /**
     * Run every task. Each is isolated: one throwing must not stop the
     * rest, or a failing reconciliation would also prevent expired
     * deposit quotes from being cleaned up.
     *
     * @return array<string,mixed> Task name => result or error string.
     */
    public static function run(): array
    {
        $started = microtime(true);
        $results = [];

        foreach (self::tasks() as $name => $task) {
            try {
                $results[$name] = $task();
            } catch (Throwable $e) {
                $results[$name] = 'error: ' . $e->getMessage();
                Logger::write('cron.task_failed', $e->getMessage(), ['task' => $name]);
            }
        }

        $results['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        Logger::write('cron.run', 'Scheduled maintenance completed', $results);

        return $results;
    }

    /** @return array<string,callable():mixed> */
    private static function tasks(): array
    {
        return [
            // Quotes whose lock window passed with no payment. Only touches
            // rows still `pending` - a payment that arrived late is handled
            // by charge:delayed and must not be stomped on here.
            'expired_quotes' => static fn (): int => Payments::expireStaleQuotes(),

            // Transfer claims an admin opened and walked away from.
            'released_claims' => static fn (): int => Nft::releaseStaleClaims(),

            // Rate limiter rows outside the longest window.
            'pruned_rate_limits' => static fn (): int => RateLimiter::prune(),

            // Expired single-use tokens.
            'pruned_tokens' => static fn (): int => Database::run(
                'DELETE FROM user_tokens WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY)'
            )->rowCount(),

            // Webhook bodies past their retention window. The event rows
            // stay - they are the idempotency keys - but the payloads they
            // carry do not need to live forever.
            'pruned_webhook_payloads' => static fn (): int => Database::run(
                'UPDATE webhook_events SET payload = NULL
                  WHERE payload IS NOT NULL
                    AND received_at < (UTC_TIMESTAMP() - INTERVAL 90 DAY)'
            )->rowCount(),

            // Nightly ledger reconciliation: every cached balance against
            // SUM(wallet_entries). Repairs the cache and records drift, so
            // a discrepancy that appeared once is still on the record
            // tomorrow.
            'reconciliation' => static function (): array {
                Database::run('INSERT INTO reconciliation_runs (started_at) VALUES (UTC_TIMESTAMP())');
                $runId = Database::lastInsertId();

                $result = Wallet::reconcile(true);

                $notes = $result['drift'] === [] && $result['negative'] === []
                    ? null
                    : json_encode(['drift' => $result['drift'], 'negative' => $result['negative']]);

                Database::run(
                    'UPDATE reconciliation_runs
                        SET finished_at = UTC_TIMESTAMP(), users_checked = ?, drift_count = ?,
                            negative_balances = ?, notes = ?
                      WHERE id = ?',
                    [
                        $result['checked'],
                        count($result['drift']),
                        count($result['negative']),
                        $notes === false ? null : $notes,
                        $runId,
                    ]
                );

                return [
                    'checked'  => $result['checked'],
                    'drift'    => count($result['drift']),
                    'negative' => count($result['negative']),
                ];
            },
        ];
    }
}
