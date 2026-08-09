<?php

declare(strict_types=1);

namespace App\Lib;

use App\Auth;
use RuntimeException;
use Throwable;

/**
 * Plain-PHP templating.
 *
 * Templates receive their data as extracted local variables and are
 * rendered inside a closure, so a template cannot reach $this or the
 * caller's scope. Output escaping is the template's job and is done with
 * the `e()` helper, which is `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
 * with no exceptions - including for values that "came from us", because
 * an admin-entered NFT name is no more trustworthy than a visitor's.
 */
final class View
{
    /** @var array<string,mixed> Shared with every template. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a template inside the main layout.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], string $layout = 'layout/app'): string
    {
        $content = self::partial($template, $data);

        return self::partial($layout, $data + [
            'content' => $content,
            'flashes' => Auth::takeFlashes(),
        ]);
    }

    /** Render and send. */
    public static function display(string $template, array $data = [], string $layout = 'layout/app'): void
    {
        echo self::render($template, $data, $layout);
    }

    /**
     * Render a template without a layout. Used for partials and for the
     * AJAX endpoints that return a fragment of the collection grid.
     *
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $path = self::resolve($template);

        $render = static function (string $__path, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            try {
                require $__path;

                return (string) ob_get_clean();
            } catch (Throwable $e) {
                // A template that throws mid-render must not leave its
                // half-built output in the buffer, where it would be
                // concatenated onto an error page.
                ob_end_clean();

                throw $e;
            }
        };

        return $render($path, $data + self::$shared);
    }

    private static function resolve(string $template): string
    {
        // Templates are named by the application, never by user input, but
        // this is cheap insurance against a future controller passing a
        // request value straight through.
        if (preg_match('#^[a-z0-9_/-]+$#i', $template) !== 1 || str_contains($template, '..')) {
            throw new RuntimeException("Invalid template name: {$template}");
        }

        $path = Config::string('app.views', APP_PATH . '/views') . '/' . $template . '.php';

        if (!is_file($path)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        return $path;
    }

    //-----------------------------------------------------------------
    // Helpers available to every template
    //-----------------------------------------------------------------

    /** Cache-busted URL for a compiled asset. */
    public static function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/') . '?v=' . Config::string('app.asset_version', '1');
    }

    /**
     * URL for NFT media. Always routed through the PHP handler, never a
     * direct link into storage/ - a direct request there is refused by
     * storage/.htaccess anyway, but the handler is also where filename
     * validation and unreleased-inventory access checks happen.
     */
    public static function media(?string $storedPath, string $variant = 'preview'): string
    {
        if ($storedPath === null || $storedPath === '') {
            return '/assets/img/placeholder.svg';
        }

        return '/media/' . $variant . '/' . rawurlencode(basename($storedPath));
    }

    /** Marks the current section in the nav. */
    public static function isActive(string $prefix): bool
    {
        $path = Request::path();

        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }

    /**
     * Build a URL for the collection page with one filter changed,
     * preserving the rest. This is what makes every filtered view
     * linkable.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $changes
     */
    public static function filterUrl(array $current, array $changes, string $base = '/collection'): string
    {
        $merged = array_merge($current, $changes);

        // Changing any filter resets paging - staying on page 7 of a
        // narrower result set usually lands on an empty grid.
        if (!array_key_exists('page', $changes)) {
            unset($merged['page']);
        }

        // Traits are stored grouped; flatten back to `Type:Value` pairs.
        if (isset($merged['traits']) && is_array($merged['traits'])) {
            $flat = [];
            foreach ($merged['traits'] as $type => $values) {
                if (is_array($values)) {
                    foreach ($values as $value) {
                        $flat[] = $type . ':' . $value;
                    }
                }
            }
            $merged['traits'] = $flat;
        }

        // Drop defaults so a plain view has a clean URL.
        foreach (['status' => 'listed', 'sort' => 'newest', 'page' => 1, 'q' => '', 'collection' => ''] as $key => $default) {
            if (($merged[$key] ?? null) === $default) {
                unset($merged[$key]);
            }
        }

        return $base . Fmt::queryString($merged);
    }
}
