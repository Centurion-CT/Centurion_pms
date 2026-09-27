<?php

declare(strict_types=1);

namespace App\Services\Errors;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ships uncaught 500-class exceptions to Dokwe's error-ingest API.
 *
 * Design constraints:
 *   - Reporter can NEVER surface an error itself. Any failure in the
 *     HTTP call gets swallowed + logged locally — the last thing a
 *     dying request needs is a second exception on the way out.
 *   - Sync-with-tight-timeout (3s). Queued dispatch is a future
 *     concern — for now we accept the small extra wall-clock on
 *     already-broken responses in exchange for zero infra dependency.
 *   - No-op when disabled OR the project_id / token env vars are
 *     unset — protects local dev from noisy config while keeping the
 *     wiring in bootstrap/app.php on-by-default.
 *   - `shouldReport()` filters expected 4xx exceptions (Validation,
 *     Auth, NotFound, HttpException < 500, TokenMismatch, etc). The
 *     tenant-not-found 404 renderer in bootstrap/app.php also relies
 *     on this to keep the pager quiet.
 */
class DokweErrorReporter
{
    private const CLASSES_TO_SKIP = [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Spatie\Multitenancy\Exceptions\NoCurrentTenant::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ];

    public function report(Throwable $e, ?Request $request = null): void
    {
        try {
            if (! $this->shouldReport($e)) {
                return;
            }

            $config = config('dokwe.errors', []);
            if (! ($config['enabled'] ?? true)) {
                return;
            }

            $env = app()->environment();
            if (! in_array($env, $config['environments'] ?? ['production'], true)) {
                return;
            }

            $projectId = $config['project_id'] ?? null;
            $token = $config['token'] ?? null;
            if (empty($projectId) || empty($token)) {
                return;
            }

            $endpoint = rtrim((string) config('dokwe.base_url', 'https://dokwe.com/api'), '/')
                .'/ingest/errors/'.$projectId;

            $payload = $this->buildPayload($e, $request, $env, (int) ($config['stack_frames'] ?? 20));

            Http::timeout((int) ($config['timeout'] ?? 3))
                ->withToken($token)
                ->asJson()
                ->post($endpoint, $payload);
        } catch (Throwable $reporterFailure) {
            // Reporter must never crash the request further. Local log
            // captures it so a persistent Dokwe outage still surfaces
            // to us via ordinary log ingestion.
            try {
                Log::warning('Dokwe error reporter failed', [
                    'original_exception' => get_class($e),
                    'original_message'   => $e->getMessage(),
                    'reporter_exception' => $reporterFailure->getMessage(),
                ]);
            } catch (Throwable) {
                // Silent — even Log is failing. Nothing left to do.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Throwable $e, ?Request $request, string $env, int $stackFrameLimit): array
    {
        $stack = [];
        foreach (array_slice($e->getTrace(), 0, $stackFrameLimit) as $frame) {
            $stack[] = [
                'function' => (string) ($frame['function'] ?? ''),
                'class'    => (string) ($frame['class'] ?? ''),
                'file'     => (string) ($frame['file'] ?? ''),
                'line'     => (int) ($frame['line'] ?? 0),
            ];
        }

        $context = [
            'exception_class' => get_class($e),
        ];

        if ($request !== null) {
            $context['url'] = $request->fullUrl();
            $context['method'] = $request->method();
            $context['ip'] = $request->ip();
        }

        $tenant = Tenant::current();
        if ($tenant !== null) {
            $context['tenant_id'] = $tenant->id;
            $context['tenant_slug'] = $tenant->slug ?? null;
        }

        if ($request !== null) {
            $user = $request->user();
            if ($user !== null) {
                $context['user_id'] = $user->getAuthIdentifier();
            }
        }

        return [
            'message'     => $e->getMessage() !== '' ? $e->getMessage() : get_class($e),
            'level'       => 'error',
            'platform'    => 'laravel-php',
            'environment' => $env,
            'stack'       => $stack,
            'context'     => $context,
        ];
    }

    public function shouldReport(Throwable $e): bool
    {
        foreach (self::CLASSES_TO_SKIP as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        // Symfony HttpException carries a status; only 5xx count as
        // app failures (4xx are user-caused and expected).
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            return $e->getStatusCode() >= 500;
        }

        return true;
    }
}
