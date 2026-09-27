<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminLogoutController;
use App\Http\Controllers\Admin\NewsletterExportController;
use App\Http\Middleware\EnsureAdmin2fa;
use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Livewire\Admin\Activity\Index as AdminActivityIndex;
use App\Livewire\Admin\Admins\Form as AdminAdminsForm;
use App\Livewire\Admin\Admins\Index as AdminAdminsIndex;
use App\Livewire\Admin\Auth\Login as AdminLogin;
use App\Livewire\Admin\Bookings\Index as AdminBookingsIndex;
use App\Livewire\Admin\Corporates\Form as AdminCorporateForm;
use App\Livewire\Admin\Corporates\Index as AdminCorporatesIndex;
use App\Livewire\Admin\Corporates\Show as AdminCorporateShow;
use App\Livewire\Admin\Dashboard\Home as AdminDashboard;
use App\Livewire\Admin\ApiAccessRequests\Index as AdminApiAccessRequestsIndex;
use App\Livewire\Admin\DemoRequests\Index as AdminDemoRequestsIndex;
use App\Livewire\Admin\Newsletter\Index as AdminNewsletterIndex;
use App\Livewire\Admin\PaystackFallback\Index as AdminPaystackFallbackIndex;
use App\Livewire\Admin\Rents\Index as AdminRentsIndex;
use App\Livewire\Admin\Revenue as AdminRevenue;
use App\Livewire\Admin\Subscriptions\Index as AdminSubscriptionsIndex;
use App\Livewire\Admin\Tenants\Cleanup as AdminTenantsCleanup;
use App\Livewire\Admin\Tenants\Index as AdminTenantsIndex;
use App\Livewire\Admin\Tenants\Show as AdminTenantsShow;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Admin Routes (PRD §8 / §18)
|--------------------------------------------------------------------------
|
| Restricted to admin.keverna.com. Auth guard `admin` backs the `admins`
| table; EnsureActiveAdmin middleware additionally bounces deactivated
| accounts on every request.
|
*/

Route::get('/__admin-ping', function () {
    return response()->json(['admin' => true, 'host' => request()->getHost()]);
})->name('ping');

// Guest (unauthenticated admin) surface.
Route::middleware('guest:admin')->group(function (): void {
    Route::get('/login', AdminLogin::class)->name('login');
});

// Authenticated admin surface. Three layers: auth:admin, is_active
// check, then 2FA challenge (skipped for admins with no TOTP set up
// so onboarding still works).
Route::middleware(['auth:admin', EnsureActiveAdmin::class, EnsureAdmin2fa::class])->group(function (): void {
    Route::post('/logout', AdminLogoutController::class)->name('logout');

    // 2FA enrolment + challenge. Enroll is always available so an
    // admin without TOTP can set one up. Challenge is what the
    // middleware bounces to for admins who have TOTP + haven't
    // passed it this session.
    Route::get('/two-factor', \App\Livewire\Admin\Auth\TwoFactorEnroll::class)
        ->name('two-factor.enroll');
    Route::get('/two-factor/challenge', \App\Livewire\Admin\Auth\TwoFactorChallenge::class)
        ->name('two-factor.challenge');

    Route::get('/', AdminDashboard::class)->name('dashboard');

    Route::get('/tenants', AdminTenantsIndex::class)->name('tenants.index');

    // Sales — /demo submissions. Every admin can work the queue +
    // issue codes; nothing here is destructive so no super-admin gate.
    Route::get('/demo-requests', AdminDemoRequestsIndex::class)->name('demo-requests.index');
    Route::get('/api-access-requests', AdminApiAccessRequestsIndex::class)->name('api-access-requests.index');
    Route::get('/newsletter', AdminNewsletterIndex::class)->name('newsletter.index');
    Route::get('/newsletter/export', NewsletterExportController::class)->name('newsletter.export');
    Route::get('/tenants/{tenant}', AdminTenantsShow::class)->name('tenants.show');

    Route::get('/revenue', AdminRevenue::class)->name('revenue');
    Route::get('/subscriptions', AdminSubscriptionsIndex::class)->name('subscriptions.index');
    Route::get('/bookings', AdminBookingsIndex::class)->name('bookings.index');
    Route::get('/rents', AdminRentsIndex::class)->name('rents.index');
    Route::get('/paystack-fallback', AdminPaystackFallbackIndex::class)->name('paystack-fallback.index');

    // Corporates (B2B) — onboarded companies + their wallets/members/rates.
    Route::get('/corporates', AdminCorporatesIndex::class)->name('corporates.index');
    Route::get('/corporates/new', AdminCorporateForm::class)->name('corporates.create');
    Route::get('/corporates/{corporate}', AdminCorporateShow::class)->name('corporates.show');
    Route::get('/corporates/{corporate}/edit', AdminCorporateForm::class)->name('corporates.edit');

    // Audit log — readable by every admin (transparency), no mutations.
    Route::get('/activity', AdminActivityIndex::class)->name('activity.index');

    // Operator feedback inbox — one row per in-dashboard prompt
    // answer, filterable by module + rating, plus per-module
    // rollup so we can spot which surface operators struggle with.
    Route::get('/feedback', \App\Livewire\Admin\Feedback\Index::class)->name('feedback.index');

    // Admin account management — super-only.
    Route::middleware(EnsureSuperAdmin::class)->group(function (): void {
        Route::get('/admins', AdminAdminsIndex::class)->name('admins.index');
        Route::get('/admins/new', AdminAdminsForm::class)->name('admins.create');
        Route::get('/admins/{admin}/edit', AdminAdminsForm::class)->name('admins.edit');

        // Tenant data cleanup — support tool for wiping self-service
        // test bookings/payments so a live property's reports stay clean.
        Route::get('/tenants/{tenant}/cleanup', AdminTenantsCleanup::class)->name('tenants.cleanup');

        // Dangerous, irreversible tenant delete. 2FA is enforced at
        // the Livewire component level with a per-request TOTP code
        // (not just the session pass flag), so an authenticated 2FA
        // session isn't enough by itself.
        Route::get('/tenants/{tenant}/destroy', \App\Livewire\Admin\Tenants\Destroy::class)
            ->name('tenants.destroy');

        // Concierge content import - super admin pastes public listing
        // URLs, we fetch structured content + LLM extraction, admin
        // reviews per-field, approves + notifies the operator. Scoped
        // by tenant so Property lookup happens inside the tenant's
        // context (Property is tenant-scoped).
        Route::get('/tenants/{tenant}/properties/{propertyId}/import-content',
            \App\Livewire\Admin\PropertyContentImport\Show::class)
            ->name('tenants.properties.import-content');

        // AI spend audit - live view of the ai_extractions table with
        // per-provider totals and monthly cap headroom. Read-only.
        Route::get('/ai-spend',
            \App\Livewire\Admin\AiSpend\Index::class)
            ->name('ai-spend.index');
    });
});
