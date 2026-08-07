<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Lib\Response;
use App\Lib\View;

/**
 * Shared controller behaviour. Thin on purpose - domain logic belongs in
 * the App\ classes, and a base controller that grows business methods
 * becomes the place nobody can refactor.
 */
abstract class Controller
{
    /** Render a full page. */
    protected function view(string $template, array $data = [], string $layout = 'layout/app'): void
    {
        // Every layout needs these; passing them explicitly from each
        // action would be forgotten somewhere.
        $data += [
            'currentUser' => Auth::user(),
            'csrf'        => Auth::csrfToken(),
            'title'       => null,
        ];

        View::display($template, $data, $layout);
    }

    protected function redirect(string $to): never
    {
        Response::redirect($to);
    }

    /** Flash a message and redirect - the standard post/redirect/get exit. */
    protected function back(string $to, string $type, string $message): never
    {
        Auth::flash($type, $message);
        Response::redirect($to);
    }

    protected function notFound(string $message = 'That page does not exist.'): never
    {
        http_response_code(404);
        View::display('errors/404', [
            'title'       => 'Not found',
            'message'     => $message,
            'currentUser' => Auth::user(),
            'csrf'        => Auth::csrfToken(),
        ]);
        exit;
    }

    /** Parse a route parameter that must be a positive integer. */
    protected function id(array $params, string $key = 'id'): int
    {
        $raw = $params[$key] ?? '';

        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,18}$/', $raw) !== 1) {
            $this->notFound();
        }

        return (int) $raw;
    }
}
