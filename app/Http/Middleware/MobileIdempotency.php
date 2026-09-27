<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile mutations are queued locally on-device when offline and
 * drained when connectivity returns. The queue can retry the same
 * write multiple times — network flake, app kill mid-request, etc.
 * Every non-idempotent request MUST carry an `Idempotency-Key`
 * header (client-generated ULID); we cache the response body +
 * status for 24 h so a retry returns the exact same result.
 *
 * Missing header on POST/PUT/PATCH/DELETE = 400. GETs are safe by
 * definition, so we skip them.
 *
 * Cache key: idem:{tenant_id}:{operator_id}:{key} — scoped so a
 * key collision across tenants/operators can never happen.
 */
class MobileIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        // GETs, HEAD, OPTIONS — never mutate, safe to skip.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) < 8 || strlen($key) > 128) {
            return response()->json([
                'error' => 'idempotency_key_required',
                'detail' => 'Non-GET requests must carry an Idempotency-Key header (8-128 chars).',
            ], 400);
        }

        $operator = $request->user();
        $cacheKey = 'idem:'.($operator?->tenant_id ?? '0').':'.($operator?->id ?? '0').':'.$key;

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response($cached['body'], $cached['status'])
                ->withHeaders($cached['headers'])
                ->header('Idempotent-Replay', '1');
        }

        $response = $next($request);

        // Only cache 2xx + 4xx. 5xx should retry — never freeze a
        // transient server failure into the idempotency cache.
        $status = $response->getStatusCode();
        if (($status >= 200 && $status < 300) || ($status >= 400 && $status < 500)) {
            Cache::put($cacheKey, [
                'body' => $response->getContent(),
                'status' => $status,
                'headers' => [
                    'Content-Type' => $response->headers->get('Content-Type', 'application/json'),
                ],
            ], now()->addHours(24));
        }

        return $response;
    }
}
