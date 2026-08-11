<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * Image intake for the admin NFT importer.
 *
 * The threat here is not "someone uploads a big file". It is that image
 * formats are containers: a valid JPEG can carry PHP in an EXIF comment,
 * an SVG is a document that can execute script, and a polyglot file can be
 * a GIF and a PHP script at once. So:
 *
 *  1. The type is decided by magic bytes, never by the filename extension
 *     and never by the browser-supplied MIME type. Both are attacker
 *     controlled.
 *
 *  2. The file is decoded and RE-ENCODED through GD. The bytes that get
 *     written are bytes GD produced from a pixel buffer, so metadata,
 *     trailing payloads and anything hidden in a comment block do not
 *     survive - the output is a new file that merely looks the same.
 *
 *  3. It is stored OUTSIDE the web root with a generated name, and served
 *     by a PHP handler. Even a file that defeated 1 and 2 could not be
 *     executed by Apache, because Apache cannot reach it.
 *
 * SVG is rejected outright. There is no way to re-encode it that removes
 * script while keeping it an SVG, and "sanitised SVG" is a recurring
 * source of stored XSS.
 */
final class ImageStore
{
    private const MAX_BYTES = 16 * 1024 * 1024;
    private const MAX_DIMENSION = 8000;

    /** Full-size render target on the long edge. */
    private const DISPLAY_MAX = 1600;

    /** Grid thumbnail target on the long edge. */
    private const PREVIEW_MAX = 640;

    /** Magic byte signatures we accept, mapped to a canonical type. */
    private const SIGNATURES = [
        "\xFF\xD8\xFF"                     => 'jpeg',
        "\x89PNG\r\n\x1a\n"                => 'png',
        'GIF87a'                           => 'gif',
        'GIF89a'                           => 'gif',
        // WEBP is "RIFF????WEBP"; the size field in the middle varies, so
        // it is checked separately in detectType().
    ];

    /**
     * Validate, re-encode and store an uploaded image.
     *
     * $collection picks the storage subdirectory (and the matching one
     * MediaController reads back from) - 'nft' for inventory images,
     * 'product' for product preview images. Defaulting to 'nft' keeps
     * every existing call site unchanged.
     *
     * @param array{tmp_name:string,size:int,error:int,name:string} $file A $_FILES entry.
     * @return array{image_path:string,preview_path:string,mime:string,width:int,height:int}
     */
    public static function storeUpload(array $file, string $collection = 'nft'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmp = (string) $file['tmp_name'];

        // Confirms the file really came from a PHP upload rather than being
        // a path an attacker talked the application into reading.
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('That upload could not be verified.');
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Images must be 16 MB or smaller.');
        }

        return self::storeFromPath($tmp, $collection);
    }

    /**
     * Same pipeline, for a file already on disk. Used by the seed script.
     *
     * @return array{image_path:string,preview_path:string,mime:string,width:int,height:int}
     */
    public static function storeFromPath(string $path, string $collection = 'nft'): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Image file is not readable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Image file could not be opened.');
        }

        $header = (string) fread($handle, 16);
        fclose($handle);

        $type = self::detectType($header);
        if ($type === null) {
            throw new RuntimeException(
                'That file is not a JPEG, PNG, GIF or WebP image. '
                . 'Renaming a file does not change its type, and SVG is not accepted.'
            );
        }

        // getimagesize() is a second opinion on the dimensions before we
        // hand anything to GD - a crafted header claiming 60000x60000
        // would otherwise try to allocate ~14 GB.
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('That image could not be read.');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new RuntimeException(
                sprintf('Image dimensions must be between 1 and %d pixels on each side.', self::MAX_DIMENSION)
            );
        }

        $source = self::decode($path, $type);
        if ($source === false) {
            throw new RuntimeException('That image could not be decoded.');
        }

        try {
            $baseName = bin2hex(random_bytes(16));

            $display = self::resample($source, self::DISPLAY_MAX);
            $preview = self::resample($source, self::PREVIEW_MAX);

            try {
                $imagePath = self::writeWebpOrJpeg($display, $baseName, $collection);
                $previewPath = self::writeWebpOrJpeg($preview, $baseName, $collection . '/preview');
            } finally {
                imagedestroy($display);
                imagedestroy($preview);
            }

            return [
                'image_path'   => $imagePath,
                'preview_path' => $previewPath,
                'mime'         => function_exists('imagewebp') ? 'image/webp' : 'image/jpeg',
                'width'        => $width,
                'height'       => $height,
            ];
        } finally {
            imagedestroy($source);
        }
    }

    private static function detectType(string $header): ?string
    {
        foreach (self::SIGNATURES as $magic => $type) {
            if (str_starts_with($header, $magic)) {
                return $type;
            }
        }

        // RIFF....WEBP
        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    /** @return \GdImage|false */
    private static function decode(string $path, string $type)
    {
        return match ($type) {
            'jpeg'  => @imagecreatefromjpeg($path),
            'png'   => @imagecreatefrompng($path),
            'gif'   => @imagecreatefromgif($path),
            'webp'  => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    /**
     * Scale to fit a bounding box, preserving aspect ratio. Never scales
     * up - an 800px source stays 800px rather than being blurred to 1600.
     */
    private static function resample(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min(1.0, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // Preserve transparency rather than compositing onto black, which
        // is what a truecolor canvas defaults to.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private static function writeWebpOrJpeg(\GdImage $image, string $baseName, string $subdir): string
    {
        $dir = Config::string('app.storage', BASE_PATH . '/storage') . '/' . $subdir;

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Storage directory is not writable: {$subdir}");
        }

        if (function_exists('imagewebp')) {
            $filename = $baseName . '.webp';
            imagesavealpha($image, true);
            $ok = imagewebp($image, $dir . '/' . $filename, 82);
        } else {
            // Hostinger's GD normally has WebP, but fall back rather than
            // fail an import on a host that does not.
            $filename = $baseName . '.jpg';
            $flattened = self::flatten($image);
            $ok = imagejpeg($flattened, $dir . '/' . $filename, 86);
            imagedestroy($flattened);
        }

        if (!$ok) {
            throw new RuntimeException('The processed image could not be written to storage.');
        }

        @chmod($dir . '/' . $filename, 0640);

        return $filename;
    }

    /** JPEG has no alpha channel; composite onto the interface background. */
    private static function flatten(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $canvas = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($canvas, 12, 11, 14);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $background);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /**
     * Resolve a stored filename to an absolute path.
     *
     * basename() strips any directory component, so a request for
     * `../../.env` resolves to `.env` inside the media directory and then
     * fails the is_file() check.
     */
    public static function absolutePath(string $filename, string $variant = 'preview', string $collection = 'nft'): ?string
    {
        $filename = basename($filename);
        $collection = in_array($collection, ['nft', 'product'], true) ? $collection : 'nft';

        if (preg_match('/^[0-9a-f]{32}\.(webp|jpg)$/', $filename) !== 1) {
            return null;
        }

        $subdir = $variant === 'full' ? $collection : $collection . '/preview';
        $path = Config::string('app.storage', BASE_PATH . '/storage') . '/' . $subdir . '/' . $filename;

        return is_file($path) ? $path : null;
    }

    public static function mimeFor(string $filename): string
    {
        return str_ends_with($filename, '.webp') ? 'image/webp' : 'image/jpeg';
    }

    /** Remove both variants of a stored image. */
    public static function delete(?string $imagePath, ?string $previewPath, string $collection = 'nft'): void
    {
        foreach ([[$imagePath, 'full'], [$previewPath, 'preview']] as [$name, $variant]) {
            if ($name === null || $name === '') {
                continue;
            }

            $path = self::absolutePath($name, $variant, $collection);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is larger than the server accepts.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE    => 'No image was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default               => 'The upload failed.',
        };
    }
}
