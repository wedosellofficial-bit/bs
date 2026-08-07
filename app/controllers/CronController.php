<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Config;
use App\Lib\Logger;
use App\Lib\Maintenance;
use App\Lib\Migrator;
use App\Lib\Request;
use App\Lib\Response;

/**
 * Scheduled maintenance, driven by Hostinger's cron panel hitting a
 * token-protected URL.
 *
 * There are no long-running daemons here by design: shared hosting kills
 * background processes, and a queue worker that silently dies is worse
 * than no worker. Everything is idempotent and safe to run more often
 * than scheduled.
 */
final class CronController extends Controller
{
    public function run(): void
    {
        $this->authorize();

        // The task list itself lives in App\Lib\Maintenance so that this
        // endpoint and bin/cron.php run exactly the same work.
        Response::json(['ok' => true, 'results' => Maintenance::run()]);
    }

    /**
     * Apply pending migrations over HTTP.
     *
     * The escape hatch for Hostinger plans with no SSH. Same token as the
     * maintenance endpoint.
     */
    public function migrate(): void
    {
        $this->authorize();

        $migrator = new Migrator();

        if (Request::query('status') === '1') {
            Response::json(['ok' => true, 'migrations' => $migrator->status()]);
        }

        $result = $migrator->migrate(Request::query('dry-run') === '1');

        Logger::write('cron.migrate', 'Migrations run over HTTP', [
            'applied' => count($result['applied']),
            'errors'  => count($result['errors']),
        ]);

        Response::json(['ok' => $result['errors'] === [], 'result' => $result], $result['errors'] === [] ? 200 : 500);
    }

    /**
     * Token check.
     *
     * hash_equals against a configured token, and a hard refusal when the
     * token is unset - otherwise an unconfigured install would expose
     * migrations and reconciliation to the internet.
     */
    private function authorize(): void
    {
        $expected = Config::string('cron.token');
        $provided = Request::query('token');

        if ($provided === '') {
            // Also accept a bearer header, which keeps the token out of
            // access logs when the caller can set headers.
            $header = Request::header('Authorization');
            if (str_starts_with($header, 'Bearer ')) {
                $provided = substr($header, 7);
            }
        }

        if ($expected === '' || !hash_equals($expected, $provided)) {
            Logger::write('cron.unauthorized', 'Cron endpoint called without a valid token', [
                'path' => Request::path(),
            ]);

            // 404 rather than 401: nothing here should acknowledge that a
            // maintenance endpoint exists at all.
            http_response_code(404);
            exit;
        }
    }
}
