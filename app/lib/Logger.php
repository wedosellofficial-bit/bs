<?php

declare(strict_types=1);

namespace App\Lib;

use App\Database;
use Throwable;

/**
 * Structured logging with two sinks:
 *
 *  - a daily JSON-lines file in storage/logs (always written)
 *  - the `audit_log` table (for events with an actor or a subject worth
 *    querying later: ledger writes, admin actions, auth failures)
 *
 * Everything passes through redact() first. The rule is not "avoid
 * logging secrets when you remember to" - it is that the logger itself
 * refuses to write them, because the one place a secret always leaks is
 * the debug line someone added at 2am and forgot.
 */
final class Logger
{
    /**
     * Context keys whose values are replaced wholesale. Matched as
     * substrings, case-insensitively, so `coinbase_api_key` and
     * `webhookSecret` are both caught.
     */
    private const REDACT_KEYS = [
        'password', 'passwd', 'pass', 'secret', 'token', 'api_key', 'apikey',
        'signature', 'sig', 'private', 'privkey', 'mnemonic', 'seed_phrase',
        'authorization', 'cookie', 'csrf', 'twofa_secret', 'totp',
    ];

    /**
     * Context keys holding on-chain identifiers. These are truncated
     * rather than removed - you need enough to correlate with an
     * explorer, not enough to make the log itself a list of your
     * customers' wallets.
     */
    private const TRUNCATE_KEYS = [
        'address', 'addr', 'wallet', 'txid', 'tx_hash', 'inscription_id', 'payout',
    ];

    private static bool $dbSinkAvailable = true;

    /**
     * @param array<string,mixed> $context
     */
    public static function write(string $event, string $message, array $context = []): void
    {
        $line = [
            'ts'      => gmdate('c'),
            'event'   => $event,
            'message' => $message,
            'ip'      => Request::clientIpOrNull(),
            'context' => self::redact($context),
        ];

        $dir = Config::string('app.storage', BASE_PATH . '/storage') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $json . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * An event worth keeping in the database: it has an actor, a subject,
     * or money attached to it. Also written to the file log.
     *
     * @param array<string,mixed> $context
     */
    public static function audit(
        string $event,
        string $message,
        ?int $actorUserId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = [],
    ): void {
        self::write($event, $message, $context + [
            'actor_user_id' => $actorUserId,
            'subject'       => $subjectType !== null ? $subjectType . '#' . (string) $subjectId : null,
        ]);

        if (!self::$dbSinkAvailable) {
            return;
        }

        try {
            $payload = json_encode(self::redact($context), JSON_UNESCAPED_SLASHES);

            Database::pdo()->prepare(
                'INSERT INTO audit_log
                    (actor_user_id, event, message, subject_type, subject_id, ip_address, user_agent, context, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            )->execute([
                $actorUserId,
                $event,
                mb_substr($message, 0, 500),
                $subjectType,
                $subjectId,
                Request::clientIpOrNull(),
                mb_substr(Request::userAgent(), 0, 255),
                $payload === false ? null : $payload,
            ]);
        } catch (Throwable $e) {
            // The audit table being unreachable must not take down a
            // purchase. Fall back to the file log and stop retrying for
            // the remainder of the request.
            self::$dbSinkAvailable = false;
            self::write('logger.db_sink_failed', $e->getMessage());
        }
    }

    public static function exception(Throwable $e, string $reference): void
    {
        self::write('exception', $e->getMessage(), [
            'reference' => $reference,
            'class'     => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            // First few frames only. A full trace of a payment handler can
            // contain the request body, and the request body can contain
            // an API response.
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);

            if (self::keyMatches($lower, self::REDACT_KEYS)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::redact($value);
                continue;
            }

            if (is_string($value) && self::keyMatches($lower, self::TRUNCATE_KEYS)) {
                $out[$key] = Fmt::truncateMiddle($value, 6, 4);
                continue;
            }

            $out[$key] = is_scalar($value) || $value === null ? $value : '[' . get_debug_type($value) . ']';
        }

        return $out;
    }

    /** @param list<string> $needles */
    private static function keyMatches(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
