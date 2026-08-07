<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * Read-only configuration holder with dot-path lookup.
 *
 * Loaded once from app/config.php during bootstrap. There is no setter:
 * configuration that can be mutated at runtime is configuration you
 * cannot reason about when reading a webhook handler.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];

    private static bool $loaded = false;

    /** @param array<string,mixed> $data */
    public static function load(array $data): void
    {
        if (self::$loaded) {
            throw new RuntimeException('Config is already loaded.');
        }

        self::$data = $data;
        self::$loaded = true;
    }

    /**
     * Fetch by dot path, e.g. Config::get('db.host').
     */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::$data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public static function string(string $path, string $default = ''): string
    {
        $v = self::get($path, $default);

        return is_scalar($v) ? (string) $v : $default;
    }

    public static function int(string $path, int $default = 0): int
    {
        $v = self::get($path, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $path, bool $default = false): bool
    {
        $v = self::get($path, $default);

        return is_bool($v) ? $v : $default;
    }

    /**
     * Fetch a value that the application cannot run without. Fails loudly
     * at the point of use rather than producing a subtly broken request -
     * an empty webhook secret would otherwise make signature checks
     * compare against HMAC-of-empty-key and pass for a crafted body.
     */
    public static function required(string $path): string
    {
        $v = self::string($path);
        if ($v === '') {
            throw new RuntimeException("Missing required configuration: {$path}. Check .env.");
        }

        return $v;
    }
}
