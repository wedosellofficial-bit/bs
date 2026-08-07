<?php

declare(strict_types=1);

namespace App;

use App\Lib\Config;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * PDO singleton plus the small query helpers the rest of the app uses.
 *
 * Two settings here are load-bearing rather than stylistic:
 *
 *  - ATTR_EMULATE_PREPARES => false. With emulation on, PDO interpolates
 *    parameters client-side, which reintroduces exactly the escaping
 *    problem prepared statements exist to remove. Off means the server
 *    parses the query and the values separately.
 *
 *  - ATTR_STRINGIFY_FETCHES => false. Money is compared and summed as
 *    integers all over this codebase; getting strings back from
 *    SUM(amount_minor) and relying on PHP's loose numeric handling is how
 *    a balance check becomes a string comparison.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Nesting depth for transaction(), which uses SAVEPOINTs when re-entered. */
    private static int $txDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::string('db.host', 'localhost'),
            Config::int('db.port', 3306),
            Config::required('db.name'),
            Config::string('db.charset', 'utf8mb4')
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                Config::string('db.user'),
                Config::string('db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    // Shared hosting kills idle connections aggressively;
                    // a persistent pool would hand us dead handles and,
                    // worse, could leak an open transaction between
                    // requests.
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );
        } catch (PDOException $e) {
            // Never let the DSN or credentials reach the browser.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        // STRICT_ALL_TABLES turns silent truncation into an error. A price
        // that quietly saturates at the column maximum is worse than a
        // failed insert.
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");

        return self::$pdo;
    }

    /** True when a connection can be opened. Used by the test runner. */
    public static function isAvailable(): bool
    {
        try {
            self::pdo()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<mixed>|array<string,mixed> $params
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param list<mixed>|array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed>|array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param list<mixed>|array<string,mixed> $params
     */
    public static function scalar(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run a closure inside a transaction, committing on return and rolling
     * back on any throw.
     *
     * Nested calls use SAVEPOINTs: MySQL has no nested transactions, and
     * a naive implementation that calls beginTransaction() twice commits
     * the outer scope early - so a purchase that internally calls a
     * ledger helper would commit the debit before the order row existed.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();

        if (self::$txDepth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . self::$txDepth);
        }

        $depth = self::$txDepth++;

        try {
            $result = $fn($pdo);

            if ($depth === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp_' . $depth);
            }

            self::$txDepth = $depth;

            return $result;
        } catch (Throwable $e) {
            self::$txDepth = $depth;

            try {
                if ($depth === 0) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $depth);
                }
            } catch (Throwable) {
                // The connection is already gone; the server will roll the
                // transaction back on its own. Surface the original error.
            }

            throw $e;
        }
    }

    /** True when the exception is a unique-constraint violation. */
    public static function isDuplicateKey(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        // SQLSTATE 23000 with driver code 1062 is MySQL/MariaDB's duplicate
        // entry. Checked explicitly because the webhook handler relies on
        // it: an insert losing a race is the correct, expected outcome of
        // two concurrent deliveries of the same charge.
        return $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** Used by the test suite to work against a scratch connection. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }
}
