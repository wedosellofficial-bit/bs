<?php

declare(strict_types=1);

namespace App\Lib;

use App\Auth;
use App\Membership;

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
 * The membership gate is enforced here for the same reason: hiding a nav
 * link is not access control, and a per-controller check is a check that
 * a new controller can forget to add. See membershipGateApplies().
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
     * Routes reachable regardless of Membership::gateMode(), when the
     * mode is 'all' (the operator's chosen default - a full paywall).
     * Everything here is either how a visitor becomes a member (auth,
     * the membership page itself, funding the wallet to pay the fee) or
     * account self-service that has to work before someone has paid
     * anything - a non-member must still be able to log out or secure
     * their own account.
     *
     * Legal pages (terms/privacy) and support/FAQ stay reachable too:
     * they are disclosures and help content, not "the store", and most
     * of them need to be readable before someone hands over payment.
     *
     * @var list<string>
     */
    private const MEMBERSHIP_EXEMPT = [
        '/login', '/register', '/logout',
        '/verify-email', '/verify-email/resend',
        '/forgot-password', '/reset-password',
        '/login/2fa',
        '/membership', '/membership/join',
        '/account', '/account/settings',
        '/account/settings/profile', '/account/settings/password', '/account/settings/payout-address',
        '/account/settings/2fa', '/account/settings/2fa/enable', '/account/settings/2fa/disable',
        '/account/wallet', '/account/wallet/statement',
        '/terms', '/privacy', '/faq', '/support',
        '/cron/run', '/cron/migrate',
    ];

    /** Prefixes always exempt, regardless of gate mode. @var list<string> */
    private const MEMBERSHIP_EXEMPT_PREFIXES = ['/admin', '/media'];

    /**
     * Routes gated even under the looser 'purchase' mode: the money-
     * moving and post-purchase endpoints, not the browsing pages.
     *
     * @var list<string>
     */
    private const MEMBERSHIP_PURCHASE_GATED_PATTERNS = [
        '#^/buy/[^/]+$#',
        '#^/products/[^/]+/buy$#',
        '#^/account/product-orders/[^/]+/download$#',
        '#^/download/[^/]+$#',
    ];

    /**
     * Whether $path needs Auth::requireMembership() under the configured
     * gate mode.
     */
    private static function membershipGateApplies(string $path): bool
    {
        $mode = Membership::gateMode();

        if ($mode === Membership::GATE_OFF) {
            return false;
        }

        foreach (self::MEMBERSHIP_EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        if ($mode === Membership::GATE_PURCHASE) {
            foreach (self::MEMBERSHIP_PURCHASE_GATED_PATTERNS as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return true;
                }
            }

            return false;
        }

        // GATE_ALL: everything except the explicit exemptions above.
        return !in_array($path, self::MEMBERSHIP_EXEMPT, true);
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

            if (self::membershipGateApplies($path)) {
                Auth::requireMembership();
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
