<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * Deliverable-file intake for digital-art products.
 *
 * Unlike ImageStore, a deliverable cannot be decoded and re-encoded -
 * that would corrupt the actual art/design file a buyer is paying for.
 * What is still enforced:
 *
 *  1. The type is decided by magic bytes, never the filename extension or
 *     the browser-supplied MIME type - both attacker controlled.
 *
 *  2. Only an explicit allowlist of art/design formats is accepted. This
 *     is what stands in for re-encoding: a `.php` renamed to `.png` still
 *     fails here because its first bytes are not a PNG signature, and
 *     nothing outside the allowlist - script formats included - can pass
 *     no matter what it is renamed to.
 *
 *  3. It is stored OUTSIDE the web root (storage/products) under a
 *     generated name with no extension tied to content, and served only
 *     by DownloadController. Apache cannot reach it even if every check
 *     above were somehow defeated.
 *
 * SVG is deliberately not in the allowlist, for the same reason
 * ImageStore rejects it: it is a document format that can carry script.
 */
final class DeliverableStore
{
    private const MAX_BYTES = 100 * 1024 * 1024;

    /** Magic byte signatures accepted, mapped to a canonical MIME type. */
    private const SIGNATURES = [
        "\xFF\xD8\xFF"        => 'image/jpeg',
        "\x89PNG\r\n\x1a\n"   => 'image/png',
        'GIF87a'              => 'image/gif',
        'GIF89a'              => 'image/gif',
        '%PDF'                => 'application/pdf',
        '8BPS'                => 'image/vnd.adobe.photoshop',
        "PK\x03\x04"          => 'application/zip',
        // WEBP is "RIFF????WEBP" - the size field in the middle varies,
        // so it is checked separately in detectType().
    ];

    /**
     * Validate and store an uploaded deliverable.
     *
     * @param array{tmp_name:string,size:int,error:int,name:string} $file A $_FILES entry.
     * @return array{path:string,mime:string,size:int,original_name:string}
     */
    public static function storeUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmp = (string) $file['tmp_name'];

        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('That upload could not be verified.');
        }

        $size = (int) $file['size'];
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Deliverable files must be 100 MB or smaller.');
        }
        if ($size < 1) {
            throw new RuntimeException('That file is empty.');
        }

        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }
        $header = (string) fread($handle, 16);
        fclose($handle);

        $mime = self::detectType($header);
        if ($mime === null) {
            throw new RuntimeException(
                'That file type is not accepted. Deliverables must be an image (JPEG/PNG/GIF/WebP), '
                . 'a PDF, a Photoshop document, or a ZIP archive. Renaming a file does not change its type.'
            );
        }

        $dir = Config::string('app.storage', BASE_PATH . '/storage') . '/products';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Storage directory is not writable: products');
        }

        $filename = bin2hex(random_bytes(16)) . '.bin';
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('The uploaded file could not be saved.');
        }
        @chmod($destination, 0640);

        return [
            'path'          => $filename,
            'mime'          => $mime,
            'size'          => $size,
            'original_name' => self::safeOriginalName((string) $file['name']),
        ];
    }

    private static function detectType(string $header): ?string
    {
        foreach (self::SIGNATURES as $magic => $mime) {
            if (str_starts_with($header, $magic)) {
                return $mime;
            }
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * A filename safe to put in a Content-Disposition header and safe to
     * show back to the buyer: no path component, no control characters,
     * bounded length.
     */
    private static function safeOriginalName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F"]/', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'download' : mb_substr($name, 0, 180);
    }

    /**
     * Resolve a stored filename to an absolute path. basename() strips
     * any directory component, so a crafted name cannot escape the
     * products directory.
     */
    public static function absolutePath(string $filename): ?string
    {
        $filename = basename($filename);

        if (preg_match('/^[0-9a-f]{32}\.bin$/', $filename) !== 1) {
            return null;
        }

        $path = Config::string('app.storage', BASE_PATH . '/storage') . '/products/' . $filename;

        return is_file($path) ? $path : null;
    }

    public static function delete(?string $filename): void
    {
        if ($filename === null || $filename === '') {
            return;
        }

        $path = self::absolutePath($filename);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server accepts.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default               => 'The upload failed.',
        };
    }
}
