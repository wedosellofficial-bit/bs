<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Read-only accessors for the current HTTP request.
 *
 * Every input read by the application goes through here, so that
 * "where does this value come from" has one answer.
 */
final class Request
{
    private static ?string $rawBody = null;

    public static function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Browsers can only send GET/POST. Forms that need DELETE/PATCH
        // post a `_method` field; only honour it on a POST so a GET link
        // can never trigger a destructive route.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Request path, without query string, normalised to no trailing slash. */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = '/' . trim(rawurldecode($path), '/');

        return $path;
    }

    /** Raw request body. Read once and cached - php://input is a stream. */
    public static function rawBody(): string
    {
        if (self::$rawBody === null) {
            self::$rawBody = (string) file_get_contents('php://input');
        }

        return self::$rawBody;
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return (string) ($_SERVER[$key] ?? '');
    }

    public static function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    public static function isAjax(): bool
    {
        return strtolower(self::header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower(self::header('Accept')), 'application/json');
    }

    /**
     * Client IP.
     *
     * REMOTE_ADDR only, unless a proxy header is explicitly named in
     * config. This matters: the rate limiter keys on this value, and a
     * client who can choose their own key is not rate limited at all.
     */
    public static function clientIp(): string
    {
        $header = Config::string('session.ip_header');

        if ($header !== '') {
            $raw = self::header($header);
            if ($raw !== '') {
                // A forwarded-for chain appends each hop; the leftmost entry
                // is what the closest trusted proxy saw.
                $first = trim(explode(',', $raw)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public static function clientIpOrNull(): ?string
    {
        return PHP_SAPI === 'cli' ? null : self::clientIp();
    }

    /** A GET parameter as a trimmed string. */
    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? null;

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** @return list<string> A repeated GET parameter (`?trait[]=a&trait[]=b`). */
    public static function queryList(string $key): array
    {
        $v = $_GET[$key] ?? null;
        if (is_string($v)) {
            // Also accept the comma-joined form the filter UI writes to the
            // URL, so shared links stay short and readable.
            $v = explode(',', $v);
        }
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function queryInt(string $key, ?int $default = null): ?int
    {
        $v = self::query($key);

        return $v === '' || !is_numeric($v) ? $default : (int) $v;
    }

    /** A POST field as a trimmed string. */
    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? null;

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function postInt(string $key, int $default = 0): int
    {
        $v = self::post($key);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function postBool(string $key): bool
    {
        return in_array(strtolower(self::post($key)), ['1', 'true', 'on', 'yes'], true);
    }

    /** The URL to return to after login, restricted to local paths. */
    public static function safeRedirect(string $candidate, string $fallback = '/account'): string
    {
        if ($candidate === '' || $candidate[0] !== '/' || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        // Reject anything with a scheme or control characters smuggled in.
        if (preg_match('#[\x00-\x1f\\\\]|^/\.\.#', $candidate) === 1) {
            return $fallback;
        }

        return $candidate;
    }
}
