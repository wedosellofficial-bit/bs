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
 * payment provider's webhook (authenticated by HMAC signature) and the
 * cron endpoint (authenticated by a bearer token). A per-controller check
 * would eventually be forgotten on exactly one form.
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
        '/webhooks/coinbase',
        '/cron/run',
        '/cron/migrate',
    ];

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
