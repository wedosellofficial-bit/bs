<?php

declare(strict_types=1);

/**
 * Application bootstrap. Loaded by the front controller (index.php, a
 * sibling of this file's parent directory) and by the CLI scripts in
 * bin/. Sets up the autoloader, configuration, error handling, and
 * nothing else - no output, no session, no database connection. Those
 * are started on demand.
 */

use App\Lib\Config;
use App\Lib\Logger;

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Billions Store requires PHP 8.2 or newer. Set the version in hPanel > PHP Configuration.');
}

define('APP_PATH', __DIR__);
define('BASE_PATH', dirname(__DIR__));

// All internal time is UTC. Display is localised at render time only;
// storing local time is how you end up with two 01:30s on a DST night.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

/**
 * Autoloader. `App\Lib\Foo` -> app/lib/Foo.php,
 * `App\Controllers\Admin\Bar` -> app/controllers/admin/Bar.php,
 * `App\Wallet` -> app/Wallet.php.
 *
 * Namespace segments are lowercased to directory names; the class name
 * keeps its case. No Composer autoloader is needed because the
 * application has zero third-party PHP dependencies (see README).
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $parts = explode('\\', substr($class, 4));
    $name = array_pop($parts);

    // Reject anything that could climb out of app/.
    if ($name === '' || preg_match('/[^A-Za-z0-9_]/', $name)) {
        return;
    }

    $dir = APP_PATH;
    foreach ($parts as $segment) {
        if (preg_match('/[^A-Za-z0-9_]/', $segment)) {
            return;
        }
        $dir .= '/' . strtolower($segment);
    }

    $file = $dir . '/' . $name . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load(require APP_PATH . '/config.php');

//---------------------------------------------------------------------
// Error handling
//
// In production nothing about an error reaches the browser. Details go
// to storage/logs and the visitor gets a reference code they can quote
// to support, which is the only way to correlate a user report with a
// log line without exposing the log.
//---------------------------------------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', Config::get('app.storage') . '/logs/php-error.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Respect @-suppression and the current error_reporting mask.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    // Deprecations are recorded, not thrown. They are a message about a
    // future PHP version, not a failure of this request - and turning one
    // into a 500 means a minor-version upgrade can take a working
    // checkout page down. Everything else becomes an exception so it
    // cannot be ignored.
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        try {
            Logger::write('php.deprecated', $message, ['file' => $file, 'line' => $line]);
        } catch (Throwable) {
            // Never let logging a deprecation break the request.
        }

        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    $ref = bin2hex(random_bytes(6));

    try {
        Logger::exception($e, $ref);
    } catch (Throwable) {
        // Logging must never mask the original failure.
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, sprintf(
            "[%s] %s: %s\n  at %s:%d\n%s\n",
            $ref,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (Config::get('app.debug')) {
        printf(
            "<pre style=\"padding:24px;font:13px/1.6 ui-monospace,monospace;background:#08080a;color:#e8e8ef\">"
            . "<strong>%s</strong>\n%s\n\nat %s:%d\n\n%s</pre>",
            htmlspecialchars($e::class, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8'),
            $e->getLine(),
            htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
        );
        exit;
    }

    $errorPage = APP_PATH . '/views/errors/500.php';
    if (is_file($errorPage)) {
        (static function (string $__file, string $reference): void {
            require $__file;
        })($errorPage, $ref);
    } else {
        echo 'Something went wrong. Reference: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8');
    }

    exit;
});

// A fatal that bypasses the exception handler (memory, timeout) still
// must not dump a half-rendered page with a stack trace in it.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    try {
        Logger::write('fatal', $err['message'], [
            'file' => $err['file'],
            'line' => $err['line'],
        ]);
    } catch (Throwable) {
        // Nothing useful left to do at this point.
    }
});
