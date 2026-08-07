<?php

declare(strict_types=1);

namespace App\Lib;

use App\Database;
use RuntimeException;
use Throwable;

/**
 * Migration runner.
 *
 * The logic lives in a class rather than in the CLI script because
 * Hostinger's cheaper plans have no shell access, so the same runner has
 * to be reachable two ways: `php bin/migrate.php` where a shell exists,
 * and the token-protected `/cron/migrate` endpoint where it does not.
 *
 * Applied migrations are recorded with a checksum. If a file changes
 * after it was applied, the runner reports it instead of quietly ignoring
 * it - a migration edited in place is a schema that no longer matches
 * what the repository says it is.
 */
final class Migrator
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? Config::string('app.migrations', APP_PATH . '/migrations');
    }

    /**
     * Apply every pending migration.
     *
     * @return array{applied:list<string>,skipped:list<string>,drifted:list<string>,errors:list<string>}
     */
    public function migrate(bool $dryRun = false): array
    {
        $this->ensureTable();

        $applied = $this->appliedChecksums();
        $result = ['applied' => [], 'skipped' => [], 'drifted' => [], 'errors' => []];

        foreach ($this->files() as $path) {
            $name = basename($path);
            $sql = (string) file_get_contents($path);
            $checksum = hash('sha256', $sql);

            if (isset($applied[$name])) {
                if (!hash_equals($applied[$name], $checksum)) {
                    $result['drifted'][] = $name;
                } else {
                    $result['skipped'][] = $name;
                }
                continue;
            }

            if ($dryRun) {
                $result['applied'][] = $name . ' (dry run)';
                continue;
            }

            try {
                $this->apply($name, $sql, $checksum);
                $result['applied'][] = $name;
            } catch (Throwable $e) {
                // Stop at the first failure. Continuing would apply later
                // migrations against a schema that is missing the thing
                // they depend on, turning one clear error into five
                // confusing ones.
                $result['errors'][] = $name . ': ' . $e->getMessage();
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<array{migration:string,applied_at:string|null,status:string}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedRows();
        $out = [];

        foreach ($this->files() as $path) {
            $name = basename($path);
            $checksum = hash('sha256', (string) file_get_contents($path));
            $row = $applied[$name] ?? null;

            $out[] = [
                'migration'  => $name,
                'applied_at' => $row['applied_at'] ?? null,
                'status'     => match (true) {
                    $row === null => 'pending',
                    !hash_equals((string) $row['checksum'], $checksum) => 'CHANGED SINCE APPLIED',
                    default => 'applied',
                },
            ];
        }

        return $out;
    }

    /**
     * Split a migration file into individual statements.
     *
     * PDO's exec() cannot reliably run a multi-statement string with
     * prepare-emulation off, so the file is split here. The parser tracks
     * string and comment state, because a semicolon inside a column
     * comment (this schema has several) is not a statement boundary.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                // `-- ` and `#` line comments, and `/* */` blocks.
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
                if ($char === ';') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    continue;
                }
            }

            // Quote state. Backslash escapes are honoured inside quoted
            // strings so `'it\'s'` does not end the string early.
            if ($char === '\\' && ($inSingle || $inDouble)) {
                $current .= $char . $next;
                $i++;
                continue;
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function apply(string $name, string $sql, string $checksum): void
    {
        $pdo = Database::pdo();
        $statements = self::splitStatements($sql);

        if ($statements === []) {
            throw new RuntimeException('Migration contains no statements.');
        }

        // MySQL cannot roll back DDL, so wrapping this in a transaction
        // would give false comfort. Instead each statement runs on its own
        // and the recording row is written only after all of them
        // succeeded - a half-applied migration therefore stays "pending"
        // and is visible in --status rather than being marked done.
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        Database::run(
            'INSERT INTO migrations (migration, checksum, applied_at) VALUES (?, ?, UTC_TIMESTAMP())',
            [$name, $checksum]
        );
    }

    /** @return list<string> Absolute paths, in lexicographic (= numeric prefix) order. */
    private function files(): array
    {
        $files = glob($this->dir . '/*.sql');
        if ($files === false) {
            throw new RuntimeException("Cannot read migrations directory: {$this->dir}");
        }

        sort($files, SORT_STRING);

        return array_values($files);
    }

    /** @return array<string,string> migration name => checksum */
    private function appliedChecksums(): array
    {
        $out = [];
        foreach (Database::all('SELECT migration, checksum FROM migrations') as $row) {
            $out[(string) $row['migration']] = (string) $row['checksum'];
        }

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function appliedRows(): array
    {
        $out = [];
        foreach (Database::all('SELECT migration, checksum, applied_at FROM migrations') as $row) {
            $out[(string) $row['migration']] = $row;
        }

        return $out;
    }

    private function ensureTable(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration  VARCHAR(190) NOT NULL,
                checksum   CHAR(64)     NOT NULL,
                applied_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_migrations_name (migration)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
    }
}
