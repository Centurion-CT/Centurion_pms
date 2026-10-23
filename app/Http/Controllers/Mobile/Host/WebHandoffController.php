<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Open full dashboard" from the host app, without putting the bearer
 * token in a URL (docs/host-app/PHASE-2.md).
 *
 *  1. POST /api/mobile/v1/host/web-handoff {next} (bearer) mints a
 *     random one-time code, cached for 60 seconds against the operator
 *     + tenant + next path, and returns the tenant URL that consumes it.
 *  2. GET /auth/app-handoff?code= (tenant web route) pulls the code
 *     (single use), logs the operator into the `web` guard and
 *     redirects to `next`. Unlike the desktop-shell handoff it never
 *     sets the shell cookie (and clears a stale one), so the dashboard
 *     renders with its normal sidebar.
 */
class WebHandoffController extends HostController
{
    public const TTL_SECONDS = 60;

    private const CACHE_PREFIX = 'host-web-handoff:';

    private const SHELL_COOKIES = ['keverna_desktop_shell', 'keverna_pos_shell'];

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate(['next' => ['nullable', 'string', 'max:500']]);
        $next = (string) ($data['next'] ?? '/dashboard');
        if (! self::isSafeNext($next)) {
            return response()->json([
                'error' => 'invalid_next',
                'detail' => 'Only dashboard pages can be opened.',
            ], 422);
        }

        return response()->json([
            'url' => self::mint($request, $this->operator($request), $next),
            'expires_in' => self::TTL_SECONDS,
        ]);
    }

    /**
     * Mint a one-time handoff URL for `$next` (a safe dashboard path).
     * Shared with endpoints that hand the app a web page in their
     * payload (e.g. the owner summary's pdf_url).
     */
    public static function mint(Request $request, Operator $operator, string $next): string
    {
        if (! self::isSafeNext($next)) {
            throw new \InvalidArgumentException('Only dashboard paths can be handed off.');
        }

        $code = Str::random(64);
        Cache::put(self::CACHE_PREFIX.$code, [
            'operator_id' => (int) $operator->id,
            'tenant_id' => (int) $operator->tenant_id,
            'next' => $next,
        ], self::TTL_SECONDS);

        return $request->getSchemeAndHttpHost().'/auth/app-handoff?code='.$code;
    }

    public function consume(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $payload = $code !== '' && strlen($code) <= 128 ? Cache::pull(self::CACHE_PREFIX.$code) : null;

        $tenant = Tenant::current();
        $operator = is_array($payload) ? Operator::query()->find((int) ($payload['operator_id'] ?? 0)) : null;
        if (! is_array($payload)
            || $tenant === null
            || (int) ($payload['tenant_id'] ?? 0) !== (int) $tenant->getKey()
            || ! $operator instanceof Operator
            || (int) $operator->tenant_id !== (int) $tenant->getKey()
            || ! self::isSafeNext((string) ($payload['next'] ?? ''))) {
            return redirect()->route('tenant.login')
                ->with('status', 'That link has expired. Open the dashboard from the app again.');
        }

        Auth::guard('web')->login($operator);
        $request->session()->regenerate();

        $response = redirect((string) $payload['next']);
        foreach (self::SHELL_COOKIES as $name) {
            if ($request->cookie($name) !== null) {
                $response->withCookie(Cookie::forget($name));
            }
        }

        return $response;
    }

    /**
     * Only same-origin dashboard or settings paths: `/dashboard`,
     * `/dashboard/...`, `/settings/...` (optionally with a query),
     * never a scheme, `//host` or `\`. Settings pages keep their own
     * role gates (e.g. /settings/property is manager-only).
     */
    public static function isSafeNext(string $next): bool
    {
        if ($next === '' || str_contains($next, '\\') || str_contains($next, '..')) {
            return false;
        }

        return (preg_match('#^/dashboard(?:[/?\#][^\s]*)?$#', $next) === 1
                || preg_match('#^/settings/[A-Za-z0-9_\-/]+(?:[?\#][^\s]*)?$#', $next) === 1)
            && ! str_starts_with($next, '//');
    }
}
