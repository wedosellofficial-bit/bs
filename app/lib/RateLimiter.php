<?php

declare(strict_types=1);

namespace App\Lib;

use App\Database;
use Throwable;

/**
 * Sliding-window rate limiter backed by `rate_limit_hits`.
 *
 * A row per attempt rather than a counter with a window-start column. A
 * fixed window resets wholesale at the boundary, so an attacker who
 * syncs to it gets a full allowance every window - twice the intended
 * rate, delivered in two bursts. Counting rows within the last N seconds
 * has no boundary to exploit.
 *
 * Subjects are hashed with a key derived from APP_KEY before storage, so
 * the table cannot be read as a list of email addresses that tried to log
 * in.
 */
final class RateLimiter
{
    /** Bucket name => [max attempts, window in seconds]. */
    public const LIMITS = [
        // Requirement: login is 5 per 15 minutes per IP+email.
        'login'          => [5, 900],
        // A wider net on the IP alone, to blunt spraying across many
        // accounts from one source.
        'login_ip'       => [30, 900],
        'register'       => [5, 3600],
        'password_reset' => [5, 3600],
        'verify_resend'  => [5, 3600],
        // Address generation calls the provider's API and costs money in
        // rate-limit budget, so it is tighter than the others.
        'topup_address'  => [6, 3600],
        'purchase'       => [20, 3600],
        'membership_join' => [5, 3600],
        'twofa'          => [10, 900],
        'contact'        => [5, 3600],
    ];

    /**
     * Record an attempt and report whether the caller is now over the
     * limit.
     *
     * Counts first, then records, so the Nth attempt is allowed and the
     * (N+1)th is not.
     */
    public static function attempt(string $bucket, string $subject): bool
    {
        [$max, $window] = self::limitFor($bucket);

        if (self::countRecent($bucket, $subject, $window) >= $max) {
            return false;
        }

        self::record($bucket, $subject);

        return true;
    }

    /** Check without consuming an attempt. */
    public static function isBlocked(string $bucket, string $subject): bool
    {
        [$max, $window] = self::limitFor($bucket);

        return self::countRecent($bucket, $subject, $window) >= $max;
    }

    public static function remaining(string $bucket, string $subject): int
    {
        [$max, $window] = self::limitFor($bucket);

        return max(0, $max - self::countRecent($bucket, $subject, $window));
    }

    /** Seconds until the oldest attempt in the window falls out of it. */
    public static function retryAfter(string $bucket, string $subject): int
    {
        [, $window] = self::limitFor($bucket);

        $oldest = Database::scalar(
            'SELECT MIN(created_at) FROM rate_limit_hits
              WHERE bucket = ? AND subject_hash = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL ? SECOND)',
            [$bucket, self::hash($bucket, $subject), $window]
        );

        if (!is_string($oldest)) {
            return 0;
        }

        $expiresAt = strtotime($oldest . ' UTC') + $window;

        return max(0, $expiresAt - time());
    }

    /**
     * Clear a subject's attempts. Called after a successful login so a
     * user who mistyped their password four times is not left one attempt
     * from lockout for the next 15 minutes.
     */
    public static function clear(string $bucket, string $subject): void
    {
        try {
            Database::run(
                'DELETE FROM rate_limit_hits WHERE bucket = ? AND subject_hash = ?',
                [$bucket, self::hash($bucket, $subject)]
            );
        } catch (Throwable $e) {
            Logger::write('ratelimit.clear_failed', $e->getMessage(), ['bucket' => $bucket]);
        }
    }

    /** Drop rows older than the longest window. Called by cron. */
    public static function prune(): int
    {
        $longest = max(array_column(self::LIMITS, 1));

        $stmt = Database::run(
            'DELETE FROM rate_limit_hits WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)',
            [$longest * 2]
        );

        return $stmt->rowCount();
    }

    /** A human sentence for the "try again later" message. */
    public static function waitMessage(string $bucket, string $subject): string
    {
        $seconds = self::retryAfter($bucket, $subject);

        if ($seconds <= 0) {
            return 'Too many attempts. Try again shortly.';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes <= 1
            ? 'Too many attempts. Try again in about a minute.'
            : "Too many attempts. Try again in about {$minutes} minutes.";
    }

    private static function countRecent(string $bucket, string $subject, int $window): int
    {
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM rate_limit_hits
                  WHERE bucket = ? AND subject_hash = ?
                    AND created_at > (UTC_TIMESTAMP() - INTERVAL ? SECOND)',
                [$bucket, self::hash($bucket, $subject), $window],
                0
            );
        } catch (Throwable $e) {
            // If the limiter itself is broken, fail closed for anything
            // that guards a credential and open for the rest would be
            // inconsistent - so fail closed everywhere by reporting the
            // limit as reached. A login page that is briefly unavailable
            // beats an unlimited password oracle.
            Logger::write('ratelimit.count_failed', $e->getMessage(), ['bucket' => $bucket]);

            return PHP_INT_MAX;
        }
    }

    private static function record(string $bucket, string $subject): void
    {
        try {
            Database::run(
                'INSERT INTO rate_limit_hits (bucket, subject_hash, created_at)
                 VALUES (?, ?, UTC_TIMESTAMP())',
                [$bucket, self::hash($bucket, $subject)]
            );
        } catch (Throwable $e) {
            Logger::write('ratelimit.record_failed', $e->getMessage(), ['bucket' => $bucket]);
        }
    }

    /**
     * Keyed hash of the subject. The bucket is mixed in so the same email
     * produces different hashes in the login and reset buckets, which
     * stops the table from being cross-referenced against itself.
     */
    private static function hash(string $bucket, string $subject): string
    {
        $key = Config::string('app.key');

        return hash_hmac('sha256', $bucket . "\0" . strtolower($subject), $key === '' ? 'ratelimit' : $key);
    }

    /** @return array{0:int,1:int} */
    private static function limitFor(string $bucket): array
    {
        return self::LIMITS[$bucket] ?? [10, 900];
    }
}
