<?php

declare(strict_types=1);

use App\Exceptions\SubscriptionExpiredException;
use App\Http\Middleware\ConfigureTenantMediaDisk;
use App\Http\Middleware\ForceHttpsInProduction;
use App\Http\Middleware\RedirectOldSlug;
use App\Http\Middleware\RedirectWwwToApex;
use App\Http\Middleware\ResolveTenantFromRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Session\TokenMismatchException;
use Spatie\Multitenancy\Exceptions\NoCurrentTenant;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Central marketing + auth — restricted to the central host (PRD §5.2).
            Route::middleware([
                'web',
                // First-touch source capture for /stays marketplace
                // pricing (see CaptureFirstTouchReferrer). No-op on
                // pages with a same-host or missing Referer, so the
                // marketing pages are unaffected.
                \App\Http\Middleware\CaptureFirstTouchReferrer::class,
            ])
                ->domain((string) config('keverna.central_domain'))
                ->group(base_path('routes/web.php'));

            // Platform admin panel — restricted to admin.keverna.com (PRD §8).
            // No URL prefix: admin has a dedicated subdomain, so /login lives
            // at admin.keverna.com/login rather than admin.keverna.com/admin/login.
            Route::middleware('web')
                ->domain((string) config('keverna.admin_domain'))
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            // Tenant dashboards + public booking engine — any host that
            // DomainTenantFinder resolves to a tenant (PRD §6.6). NeedsTenant
            // returns 404 when no tenant is bound, so this group cannot leak
            // to central/admin hosts.
            Route::middleware([
                'web',
                ResolveTenantFromRequest::class,
                NeedsTenant::class,
                // After the tenant is bound, swap MediaLibrary's disk to
                // the tenant's own Dokwe Storage space when they've
                // connected the integration. No-op for tenants on the
                // platform default.
                ConfigureTenantMediaDisk::class,
                // First-touch acquisition source for rate rules.
                // Sticks the original external referer on entry so
                // multi-branch chooser hops don't overwrite it.
                \App\Http\Middleware\CaptureFirstTouchReferrer::class,
            ])->group(base_path('routes/tenant.php'));

            // Mobile API — bearer-token JSON, no cookies, no CSRF. Lives on
            // the tenant subdomain so the tenant is resolved from the host
            // exactly like the web dashboard. See routes/api-mobile.php.
            Route::middleware([
                'api',
                ResolveTenantFromRequest::class,
                NeedsTenant::class,
            ])->prefix('api/mobile/v1')
              ->group(base_path('routes/api-mobile.php'));

            // Mobile *central* endpoints — /login lives here (not
            // tenant-scoped) because the client hits it before it
            // knows which tenant. Everything else stays on the
            // tenant subdomain. See routes/api-mobile-central.php.
            Route::middleware(['api'])
                ->domain((string) config('keverna.central_domain'))
                ->prefix('api/mobile/v1')
                ->group(base_path('routes/api-mobile-central.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust X-Forwarded-* coming from any upstream proxy. In production
        // every request reaches us through dokwe's nginx edge (and, in front
        // of that, Cloudflare in some envs) — both rewrite Host / Scheme /
        // ClientIP into X-Forwarded-*. Laravel 11+ removed the default
        // TrustProxies middleware, so without this `$request->isSecure()`
        // returns false behind TLS termination, `ForceHttpsInProduction`
        // 301-loops, and session cookies refuse to set Secure.
        //
        // The container's network boundary already filters who can reach us,
        // so `at: '*'` is safe — restrict later if we ever expose the origin
        // directly to the public internet.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Unauthenticated operators land on the tenant-side login (PRD §2).
        // Admin-guarded routes redirect to the admin login instead. The check
        // is host-based: admin.keverna.com goes to admin.login, anything else
        // goes to tenant.login (the central-domain doesn't use auth).
        $middleware->redirectGuestsTo(function ($request) {
            $adminHost = (string) config('keverna.admin_domain');
            if ($request->getHost() === $adminHost) {
                return route('admin.login');
            }

            return route('tenant.login');
        });

        // Inverse of the above — when a signed-in user hits a `guest`
        // route (like /login), send them somewhere useful instead of
        // silently reloading the login page (the framework default
        // was RouteServiceProvider::HOME, which resolved to `/`).
        //
        // Two paths to check because our auth stack has three guards:
        //   * `identity` — central login guard (keverna.com/login)
        //   * `web`      — tenant subdomain operator (tenant.keverna.com)
        //   * `admin`    — admin.keverna.com
        //
        // The most useful destination for an authenticated identity
        // hitting /login is the account-chooser; from there they pick
        // their tenant + get handed off. `web`/`admin` fall through to
        // their own home page.
        $middleware->redirectUsersTo(function ($request) {
            if (auth()->guard('identity')->check()) {
                return route('central.choose');
            }
            if (auth()->guard('admin')->check()) {
                return route('admin.dashboard');
            }
            if (auth()->guard('web')->check()) {
                return route('tenant.dashboard');
            }
            return '/';
        });

        // Paystack signs webhook payloads with HMAC-SHA512; CSRF tokens are
        // meaningless to them. The signature check is enforced inside the
        // controller — see SubscriptionWebhookController.
        $middleware->validateCsrfTokens(except: [
            'paystack/subscription-webhook',
            // Tenant-side per-operator webhook (PRD §11). Signature is
            // HMAC-SHA512 with the operator's Paystack secret — verified in
            // the controller, which 401s anything tampered.
            'paystack/booking-webhook',
            // Corporate wallet top-up webhook — signed with the master
            // Paystack key. Controller verifies signature + reference
            // prefix; CSRF tokens are meaningless here.
            'paystack/corporate-wallet-topup-webhook',
        ]);

        // PRD §6.6.1: 301-redirect old slugs (post slug-change) to the new
        // host for 90 days. Registered globally so it fires even on URLs that
        // don't match any tenant route — otherwise Laravel returns 404
        // before the route group's middleware runs.
        $middleware->prepend(RedirectOldSlug::class);

        // PRD §19: enforce HTTPS in production. Bypassed in local + testing.
        // Prepended before RedirectOldSlug so an http://old-slug.example.com
        // request gets bumped to HTTPS first, then 301'd to the new slug.
        $middleware->prepend(ForceHttpsInProduction::class);

        // Canonicalise host: strip `www.` before anything else runs so
        // the tenant resolver never sees `www` as a slug (that bug made
        // www.keverna.com render as a would-be tenant landing page).
        // Prepended last → runs first: www→apex first, then HTTPS
        // enforcement rewrites http://apex to https://apex if needed.
        $middleware->prepend(RedirectWwwToApex::class);

        // Laravel's default middlewarePriority puts AuthenticatesRequests
        // (auth:sanctum) BEFORE any custom middleware — which meant
        // ResolveTenantFromRequest was running AFTER Sanctum resolved
        // the bearer token's tokenable, so Operator::find(...) hit the
        // BelongsToTenant scope with no current tenant + returned null.
        // Force ResolveTenantFromRequest ahead of auth so the tenant
        // is bound before any Operator lookup.
        $middleware->priority([
            ResolveTenantFromRequest::class,
            NeedsTenant::class,
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        // Role-based gate for dashboard routes — `->middleware('role:bookings')`
        // checks the operator's role can access the named module.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRouteForRole::class,
            'linked-device' => \App\Http\Middleware\EnsureLinkedDeviceActive::class,
            'role.prices' => \App\Http\Middleware\EnsurePriceManager::class,
            'central-store' => \App\Http\Middleware\EnsureCentralStoreMode::class,
            'mobile.device' => \App\Http\Middleware\EnsureMobileDeviceActive::class,
            'mobile.idempotency' => \App\Http\Middleware\MobileIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Remote error ingest — ship every uncaught 500-class exception
        // to Dokwe. Reporter itself is defensive (no-op in local /
        // testing, no-op without env config, never re-throws).
        $exceptions->reportable(function (\Throwable $e): void {
            app(\App\Services\Errors\DokweErrorReporter::class)
                ->report($e, request());
        });

        // PRD §12 — unresolved tenants must 404 from the public surface, not 500.
        // NeedsTenant throws when no tenant is bound; we render it as Not Found.
        $exceptions->render(function (NoCurrentTenant $e, $request) {
            return response()->view('errors.tenant-not-found', [
                'attemptedHost' => $request->getHost(),
                'centralDomain' => (string) config('keverna.central_domain'),
            ], 404);
        });

        // Global write-guard bounce. When an expired-trial tenant tries to
        // save/delete anything (Livewire action, form POST, or a raw service
        // call), AppServiceProvider's Model::saving guard throws this
        // exception. Livewire requests get a plain 403 (its client shows the
        // built-in error toast); regular POSTs bounce back with a flash so
        // the tenant lands where they were with the bottom bar's CTA
        // already prompting them to renew.
        $exceptions->render(function (SubscriptionExpiredException $e, $request) {
            if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
                return null;
            }

            return redirect()
                ->back(fallback: route('tenant.subscription'))
                ->with('status', $e->getMessage());
        });

        // Stale CSRF (browser back after logout, session TTL exceeded) surfaces
        // as a 419 that Livewire otherwise renders as its "This page has
        // expired" modal. On full-page form POSTs it'd render Laravel's plain
        // 419 page. Both read as broken. Silently bounce back with a message —
        // the auth middleware then routes guests to the right login screen.
        $exceptions->render(function (TokenMismatchException $e, $request) {
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            return redirect()
                ->back(fallback: '/')
                ->with('status', 'Your session expired. Please try again.');
        });
    })->create();
