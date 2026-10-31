<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureBusinessIsOpen;
use App\Http\Middleware\ForceHttpsInProduction;
use App\Http\Middleware\ResolveTenantFromRequest;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
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
            // HostTenantFinder binds the business for every request: the
            // first one in TENANCY_MODE=single, the one the host belongs to
            // in TENANCY_MODE=domains (docs/MULTITENANCY.md).
            //
            // Setup wizard: mounted BEFORE the tenant-scoped groups and
            // deliberately does NOT run NeedsTenant, because this is the
            // page the operator lands on when there IS no tenant row yet.
            // Its component aborts 404 on itself once the tenant exists.
            // PRD §19: cheap subsystem ping for load balancer + uptime
            // monitor. Needs no business, so it answers on any host.
            Route::get('/health', \App\Http\Controllers\HealthController::class)->name('health');

            Route::middleware(['web'])->group(function (): void {
                Route::get('/setup', \App\Livewire\Setup\Wizard::class)
                    ->name('setup');
            });

            // Platform pages on the central domain bind no business, so
            // they are registered first and only in TENANCY_MODE=domains
            // (in single mode the central domain may be the business's own).
            if (HostTenantFinder::isDomainMode()) {
                $central = (string) config('keverna.central_domain');
                Route::domain('www.'.$central)->get('/{path?}', fn (Request $request) => redirect()->away(
                    'https://'.$central.$request->getRequestUri(), 301,
                ))->where('path', '.*');
                Route::middleware(['web'])->domain($central)
                    ->group(base_path('routes/platform.php'));
            }

            // routes/web.php holds the guest-facing surface (property
            // landing, booking flow, corporate portal). routes/tenant.php
            // holds the operator dashboard. Both need a bound Tenant.
            Route::middleware([
                'web',
                ResolveTenantFromRequest::class,
                NeedsTenant::class,
                EnsureBusinessIsOpen::class,
            ])->group(base_path('routes/web.php'));

            Route::middleware([
                'web',
                ResolveTenantFromRequest::class,
                NeedsTenant::class,
                EnsureBusinessIsOpen::class,
            ])->group(base_path('routes/tenant.php'));

            // Mobile API — bearer-token JSON, no cookies, no CSRF.
            Route::middleware([
                'api',
                ResolveTenantFromRequest::class,
                NeedsTenant::class,
                EnsureBusinessIsOpen::class,
            ])->prefix('api/mobile/v1')
              ->group(base_path('routes/api-mobile.php'));

            // Mobile central endpoints (login, device pairing) live at the
            // same host as everything else in solo mode.
            Route::middleware(['api'])
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

        // Unauthenticated operators land on the operator login.
        $middleware->redirectGuestsTo(fn () => route('tenant.login'));

        // Signed-in operators hitting a `guest` route (e.g. /login) go
        // straight to the dashboard.
        $middleware->redirectUsersTo(function () {
            if (auth()->guard('web')->check()) {
                return route('tenant.dashboard');
            }
            return '/';
        });

        // Paystack signs webhook payloads with HMAC-SHA512; CSRF tokens are
        // meaningless to them. Signature checks live in each controller.
        $middleware->validateCsrfTokens(except: [
            // Per-operator booking webhook. HMAC-SHA512 with the operator's
            // Paystack secret — verified in-controller.
            'paystack/booking-webhook',
            // Corporate wallet top-up webhook — signed with the property's
            // master Paystack key. Controller verifies signature + reference
            // prefix; CSRF tokens are meaningless here.
            'paystack/corporate-wallet-topup-webhook',
        ]);

        // Enforce HTTPS in production. Bypassed in local + testing.
        //
        // Must run AFTER Laravel's built-in TrustProxies middleware (which
        // is configured via $middleware->trustProxies(...) above). TrustProxies
        // reads X-Forwarded-Proto from the edge (nginx terminates TLS and
        // forwards HTTP to the origin), so $request->isSecure() only returns
        // true once TrustProxies has run. Using prepend() here put this
        // middleware at position 0 of the global stack — BEFORE TrustProxies —
        // so isSecure() returned false even on HTTPS requests, triggering an
        // infinite 301 → HTTPS → 301 loop. append() runs it after the proxy
        // trust has been applied.
        $middleware->append(ForceHttpsInProduction::class);

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
            // After the session (so it can sign staff out), before auth (so
            // a paused business's app gets 403, not 401).
            EnsureBusinessIsOpen::class,
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
            'staff.app' => \App\Http\Middleware\EnsureStaffAppRole::class,
            'host.role' => \App\Http\Middleware\EnsureHostAppOperator::class,
            'desk' => \App\Http\Middleware\EnsureDeskToken::class,
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

        // NoCurrentTenant: the request needs a business and none was found.
        //  - No business exists at all (fresh install): send everything to
        //    the /setup wizard.
        //  - TENANCY_MODE=domains and the host belongs to no business: the
        //    "this address isn't in use" page, or a 404 for API clients.
        //    The platform's own host has its own routes; anything else
        //    there goes back to its home page.
        $exceptions->render(function (NoCurrentTenant $e, $request) {
            if ($request->is('setup') || $request->is('setup/*')) {
                return null;
            }
            if (! HostTenantFinder::isDomainMode() || ! Tenant::query()->exists()) {
                return redirect('/setup');
            }

            $host = strtolower($request->getHost());
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No business uses this address.'], 404);
            }
            if (HostTenantFinder::isPlatformHost($host)) {
                return redirect('https://'.config('keverna.central_domain').'/');
            }

            return response()->view('platform.address-unused', [
                'host' => $host,
                'slug' => HostTenantFinder::slugFromHost($host),
                'central' => config('keverna.central_domain'),
            ], 404);
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
