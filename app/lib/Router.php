<?php

declare(strict_types=1);

namespace App\Lib;

use App\Auth;

/**
 * Pattern router.
 *
 * Routes are registered as `/orders/{id}` where `{id}` matches one path
 * segment. Handlers are `[ControllerClass::class, 'method']`.
 *
 * CSRF is enforced here rather than in each controller. Every POST, PUT,
 * PATCH and DELETE is checked before dispatch, with an explicit opt-out
 * list for the two endpoints that cannot present a session token: the
 * cron endpoint (authenticated by a bearer token or query token, not a
 * session). A per-controller check would eventually be forgotten on
 * exactly one form.
 *
 * The marketplace access gate is enforced here for the same reason:
 * hiding a nav link is not access control, and a per-controller check is
 * a check that a new controller can forget to add. See
 * marketplaceGateApplies().
 */
final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:array{0:class-string,1:string}}> */
    private array $routes = [];

    /**
     * Paths exempt from CSRF verification, each authenticated another way.
     * Kept as an explicit list so adding an exemption is a visible edit.
     *
     * @var list<string>
     */
    private const CSRF_EXEMPT = [
        '/cron/run',
        '/cron/migrate',
    ];

    /**
     * The NFT and digital-product catalog requires a fully activated
     * account - signed in AND funded to the activation threshold, not
     * just signed in. This is an explicit allowlist of GATED paths,
     * deliberately the opposite shape of the old CSRF exemption list
     * above: the site's default posture is open, so a new route added
     * later is never accidentally gated unless someone deliberately
     * lists it here. Auth::requireMarketplaceAccess() sends a guest
     * through /login?next=<this path> and a signed-in-but-unfunded
     * account to /account/wallet instead, so a link straight into the
     * catalog survives either detour.
     *
     * @var list<string>
     */
    private const MARKETPLACE_GATE_PATHS = [
        '/collection',
        '/collection/results',
        '/shop',
        '/latest',
    ];

    /** Same gate, for routes with a path parameter. @var list<string> */
    private const MARKETPLACE_GATE_PATTERNS = [
        '#^/nft/[^/]+$#',
        '#^/products/[^/]+$#',
        '#^/products/[^/]+/buy$#',
        // The NFT purchase-confirmation screen (OrderController::confirm())
        // renders the item's name, image and price before any payment is
        // taken - the same catalogue detail /nft/{id} shows, just on a
        // different path. It was never in the old login-only gate either;
        // closing it here rather than leaving it as the one checkout path
        // that skips the marketplace check entirely.
        '#^/buy/[^/]+$#',
    ];

    /**
     * Same gate, by prefix - covers NFT and product preview images
     * (/media/... and /media/product/...) regardless of the exact shape
     * of what follows. An image URL is only ever reachable by having
     * already seen a catalog page, but the filename itself is not a
     * secret, so it is closed off the same way rather than relying on
     * that.
     *
     * @var list<string>
     */
    private const MARKETPLACE_GATE_PREFIXES = [
        '/media',
    ];

    /**
     * True when a path is part of the gated marketplace - used here to
     * decide whether to run the access guard before dispatch, and reused
     * by the header partial to decide whether to show a marketplace nav
     * link to a visitor who cannot follow it yet.
     */
    public static function marketplaceGateApplies(string $path): bool
    {
        if (in_array($path, self::MARKETPLACE_GATE_PATHS, true)) {
            return true;
        }

        foreach (self::MARKETPLACE_GATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        foreach (self::MARKETPLACE_GATE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{0:class-string,1:string} $handler */
    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param array{0:class-string,1:string} $handler */
    private function add(string $method, string $pattern, array $handler): void
    {
        $params = [];

        $regex = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                // One segment, no slashes. A route parameter that can match
                // `/` turns /orders/{id} into a prefix match.
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /** Resolve and run the current request. */
    public function dispatch(): void
    {
        $method = Request::method();
        $path = Request::path();

        // HEAD is GET without a body; PHP discards the body for us.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $lookupMethod) {
                $pathMatchedOtherMethod = true;
                continue;
            }

            if (in_array($lookupMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && !in_array($path, self::CSRF_EXEMPT, true)) {
                Auth::requireCsrf();
            }

            if (self::marketplaceGateApplies($path)) {
                Auth::requireMarketplaceAccess();
            }

            array_shift($matches);
            $args = [];
            foreach ($route['params'] as $index => $name) {
                $args[$name] = $matches[$index] ?? '';
            }

            [$class, $action] = $route['handler'];
            (new $class())->{$action}($args);

            return;
        }

        if ($pathMatchedOtherMethod) {
            $this->methodNotAllowed();
        }

        $this->notFound();
    }

    private function notFound(): never
    {
        http_response_code(404);

        if (Request::isAjax()) {
            Response::json(['ok' => false, 'error' => 'Not found'], 404);
        }

        echo View::render('errors/404', ['title' => 'Page not found']);
        exit;
    }

    private function methodNotAllowed(): never
    {
        http_response_code(405);

        if (Request::isAjax()) {
            Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
        }

        echo View::render('errors/404', [
            'title'   => 'Page not found',
            'message' => 'That address exists but does not accept this kind of request.',
        ]);
        exit;
    }
}
