<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Errors\DokweErrorReporter;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Diagnostic for the Dokwe error reporter — prints config state and
 * (with --send) fires a test event through the same code path a real
 * 500 would take.
 *
 * Meant for prod debugging via `dokwe run` / SSH tinker. Safe to run
 * anywhere; --send bypasses the environment whitelist for the duration
 * of the call so a `local` box can prove the ingest wiring end-to-end.
 */
class ProbeErrorReporter extends Command
{
    protected $signature = 'errors:probe {--send : Actually POST a synthetic error to Dokwe}';

    protected $description = "Print Dokwe error reporter config; --send fires a test event";

    public function handle(): int
    {
        $env = app()->environment();
        $enabled = (bool) config('dokwe.errors.enabled', false);
        $projectId = (string) config('dokwe.errors.project_id', '');
        $token = (string) config('dokwe.errors.token', '');
        $envs = (array) config('dokwe.errors.environments', []);
        $baseUrl = rtrim((string) config('dokwe.base_url', ''), '/');

        $this->line('APP_ENV               = '.$env);
        $this->line('config:cache stale?   = '.(file_exists(base_path('bootstrap/cache/config.php')) ? 'cached (rerun `php artisan config:cache` after env changes)' : 'no cache — reads .env live'));
        $this->line('dokwe.errors.enabled  = '.($enabled ? 'true' : 'false'));
        $this->line('project_id set        = '.($projectId !== '' ? 'yes ('.substr($projectId, 0, 8).'…)' : 'NO — missing'));
        $this->line('token set             = '.($token !== '' ? 'yes (len='.strlen($token).')' : 'NO — missing'));
        $this->line('allowed environments  = '.json_encode($envs));
        $this->line('endpoint              = '.$baseUrl.'/ingest/errors/'.$projectId);

        $wouldFire = $enabled
            && $projectId !== ''
            && $token !== ''
            && in_array($env, $envs, true);
        $this->line('');
        $this->line('Would a real 500 fire? '.($wouldFire ? '✓ YES' : '✗ NO — see reasons above'));

        if (! $this->option('send')) {
            $this->line('');
            $this->comment('Rerun with --send to POST a synthetic error to Dokwe.');

            return self::SUCCESS;
        }

        if ($projectId === '' || $token === '') {
            $this->error('Cannot --send: token or project_id is unset.');

            return self::FAILURE;
        }

        // Whitelist the current environment for this probe call only.
        // Belt-and-braces: also do a direct HTTP call so a filter bug in
        // the reporter can't hide the response.
        config(['dokwe.errors.environments' => array_unique(array_merge($envs, [$env]))]);

        $this->line('');
        $this->line('→ POSTing synthetic error through DokweErrorReporter…');
        try {
            throw new RuntimeException('errors:probe — synthetic test event (safe to ignore)');
        } catch (Throwable $e) {
            $request = Request::create('https://keverna.com/errors/probe', 'GET');
            app(DokweErrorReporter::class)->report($e, $request);
            $this->info('  reporter returned cleanly (check Dokwe for the event).');
        }

        $this->line('');
        $this->line('→ Direct POST bypassing the reporter (raw response visibility)…');
        try {
            $resp = Http::timeout(10)->withToken($token)->asJson()->post(
                $baseUrl.'/ingest/errors/'.$projectId,
                [
                    'message'     => 'errors:probe — direct HTTP verification',
                    'level'       => 'error',
                    'platform'    => 'laravel-php',
                    'environment' => $env,
                    'stack'       => [['function' => 'probe', 'file' => 'artisan', 'line' => __LINE__]],
                ],
            );
            $this->line('  HTTP '.$resp->status().' — '.substr($resp->body(), 0, 300));
        } catch (Throwable $e) {
            $this->error('  direct POST failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
