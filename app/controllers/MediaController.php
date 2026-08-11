<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\ImageStore;
use App\Lib\Response;

/**
 * Serves NFT media from storage/, which is outside the web root.
 *
 * Going through PHP costs a little performance and buys three things:
 * uploaded files can never be executed by Apache, the filename is
 * validated against a strict pattern before it touches the filesystem,
 * and access could be gated later without moving any files.
 */
final class MediaController extends Controller
{
    public function show(array $params): void
    {
        $this->serve($params, 'nft');
    }

    /** Product preview images - same pipeline, a separate storage subdirectory. */
    public function showProduct(array $params): void
    {
        $this->serve($params, 'product');
    }

    private function serve(array $params, string $collection): void
    {
        $variant = ($params['variant'] ?? '') === 'full' ? 'full' : 'preview';
        $file = (string) ($params['file'] ?? '');

        // absolutePath() enforces the generated-name pattern and strips any
        // directory component, so traversal cannot escape the media folder.
        $path = ImageStore::absolutePath($file, $variant, $collection);

        if ($path === null) {
            http_response_code(404);
            exit;
        }

        Response::file(
            $path,
            ImageStore::mimeFor($file),
            // The filename is derived from random bytes and the content
            // never changes, so it is its own ETag.
            substr(basename($file), 0, 32)
        );
    }
}
