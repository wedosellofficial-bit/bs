<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Response helpers. Terminating responses exit() deliberately - a
 * redirect that returns and lets the caller keep executing is how a
 * "you must be logged in" guard ends up rendering the page anyway.
 */
final class Response
{
    public static function redirect(string $to, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $to, true, $status);
        }

        exit;
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, private');
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param array<string,mixed> $extra */
    public static function jsonError(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    public static function noContent(): never
    {
        if (!headers_sent()) {
            http_response_code(204);
        }

        exit;
    }

    /**
     * Stream a file from outside the web root through PHP.
     *
     * NFT media is stored in storage/nft and served here so that an
     * uploaded file can never be executed by Apache, and so that
     * unreleased inventory can be access-checked before a byte is sent.
     */
    public static function file(string $absolutePath, string $mime, string $etag, bool $public = true): never
    {
        if (!is_file($absolutePath)) {
            http_response_code(404);
            exit;
        }

        $quoted = '"' . $etag . '"';

        if (trim(Request::header('If-None-Match')) === $quoted) {
            http_response_code(304);
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($absolutePath));
            header('ETag: ' . $quoted);
            header('X-Content-Type-Options: nosniff');
            // Media is immutable once written (the filename is content
            // derived), so it can be cached hard.
            header($public
                ? 'Cache-Control: public, max-age=31536000, immutable'
                : 'Cache-Control: private, max-age=60');
            // Belt and braces against a browser being talked into treating
            // an image response as a document.
            header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"');
        }

        readfile($absolutePath);
        exit;
    }
}
