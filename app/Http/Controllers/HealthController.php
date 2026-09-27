<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Subsystem health check (PRD §19). One row per ping, 200 when every
 * dependency is reachable, 503 when any is degraded. Surfaced on the
 * central host (NOT a tenant URL — we don't want a healthcheck wedged
 * behind tenant resolution failures).
 *
 * Probes are deliberately cheap:
 *   - DB: a `SELECT 1` (verifies pool + credentials).
 *   - Cache: write-read-delete on a scratch key.
 *   - Storage: existence check on the configured default disk root.
 *
 * Paystack / Sendpipe are intentionally NOT probed — we don't want our
 * healthcheck to fail open when those providers have brief blips.
 * Production monitors poll them separately.
 *
 * Queue depth IS probed: a dead worker manifests as a growing backlog
 * of local Redis jobs, which is a real internal degradation (as
 * opposed to a third-party blip). See {@see self::QUEUE_DEPTH_LIMIT}.
 */
class HealthController extends Controller
{
    /**
     * Depth (default queue) above which we start reporting degraded.
     * Sized to tolerate normal scheduled bursts (low-stock digest at
     * 07:30, summary reports Monday 06:00, guest-facing OTP bursts)
     * while still catching a stopped worker within a couple of minutes.
     */
    private const int QUEUE_DEPTH_LIMIT = 100;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(fn () => DB::connection()->select('select 1 as ok')),
            'cache' => $this->probe(function (): bool {
                $key = '_health:'.uniqid('', true);
                Cache::put($key, '1', 5);
                $back = Cache::get($key);
                Cache::forget($key);

                return $back === '1';
            }),
            'storage' => $this->probe(fn () => Storage::disk(config('filesystems.default'))->exists('.')),
            'queue' => $this->probe(function (): bool {
                $depth = (int) Queue::size();
                if ($depth > self::QUEUE_DEPTH_LIMIT) {
                    throw new RuntimeException("queue backlog {$depth} > ".self::QUEUE_DEPTH_LIMIT.' (worker may be down)');
                }

                return true;
            }),
        ];

        $allOk = collect($checks)->every(fn (array $c) => $c['ok'] === true);

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'timestamp' => Carbon::now()->toIso8601String(),
            'checks' => $checks,
        ], $allOk ? 200 : 503);
    }

    /**
     * @param  callable(): mixed  $callable
     * @return array{ok: bool, error: ?string}
     */
    private function probe(callable $callable): array
    {
        try {
            $callable();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
