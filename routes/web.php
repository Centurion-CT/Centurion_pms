<?php

declare(strict_types=1);

use App\Http\Controllers\Central\IdentityClaimController;
use App\Http\Controllers\Central\IdentityHandoffController;
use App\Http\Controllers\Central\IdentityLogoutController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Paystack\CorporateWalletTopupWebhookController;
use App\Http\Controllers\Portal\CorporateLogoutController;
use App\Http\Controllers\Portal\CorporateWalletCallbackController;
use App\Http\Middleware\EnsureCorporateActive;
use App\Livewire\Auth\Register;
use App\Livewire\Central\Chooser as CentralChooser;
use App\Livewire\Central\ForgotPassword as CentralForgotPassword;
use App\Livewire\Central\Login as CentralLogin;
use App\Livewire\Central\Reservations as CentralReservations;
use App\Livewire\Central\ResetPassword as CentralResetPassword;
use App\Livewire\Portal\Corporate\Auth\Login as CorporatePortalLogin;
use App\Livewire\Portal\Corporate\Bookings as CorporatePortalBookings;
use App\Livewire\Portal\Corporate\Dashboard as CorporatePortalDashboard;
use App\Livewire\Portal\Corporate\Members as CorporatePortalMembers;
use App\Livewire\Portal\Corporate\Wallet as CorporatePortalWallet;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Web Routes
|--------------------------------------------------------------------------
|
| Routes served from the central domain (PRD §5.2) — marketing site,
| registration, password reset request. Tenant resolution does NOT happen
| here; DomainTenantFinder returns null for the central host.
|
*/

Route::view('/', 'welcome')->name('home');

// v2 marketplace landing — WIP. Lives at /v2 until it's promoted to /.
// The current `welcome.blade.php` at / is the pre-marketplace landing
// (the "rounded card-stack" design) restored while the v2 iteration
// continues here.
Route::view('/v2', 'welcome-v2')->name('home.v2');

Route::view('/pricing', 'pricing')->name('pricing');

// Demo-first buyer funnel — see prototype brief. The primary CTA
// on / now routes here instead of straight to /register; /register
// is still live for the small share of operators who prefer to
// self-serve.
Route::get('/demo', \App\Livewire\Marketing\BookDemo::class)->name('demo');

// Public API early-access waitlist. Distinct funnel from /demo since
// these are developers building on top of Keverna, not operators
// running a property on it.
Route::get('/api-access', \App\Livewire\Marketing\ApiAccess::class)->name('api-access');

// SEO plumbing - XML sitemap for search engines + plain-text
// crawl guide for LLM/RAG systems.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');
Route::get('/llms.txt', \App\Http\Controllers\LlmsGuideController::class)->name('llms-guide');

// Legal - linked from every marketing surface footer + the
// cookie-consent banner.
Route::view('/cookies', 'legal.cookies')->name('legal.cookies');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms',   'legal.terms')->name('legal.terms');
Route::view('/ndpr',    'legal.ndpr')->name('legal.ndpr');

// One-click unsubscribe from the newsletter footer.
Route::get('/newsletter/unsubscribe/{token}', \App\Http\Controllers\Marketing\NewsletterUnsubscribeController::class)
    ->name('newsletter.unsubscribe');

// "List your property" — the operator marketing site. This is the page
// keverna.com/ used to render before the marketplace pivot; preserved
// verbatim at /for-hosts so the sales story stays intact and shareable.
Route::view('/for-hosts', 'hosts')->name('for-hosts');

// Keverna Stays marketplace - cross-tenant search index. Reads from
// StaysListingQuery which bypasses TenantScope + filters to properties
// that opted in (is_listed_publicly=true). URL contract:
//   /stays?q=<city or name>&from=YYYY-MM-DD&to=YYYY-MM-DD&adults=&children=&budget=
Route::get('/stays', \App\Http\Controllers\Marketplace\StaysIndexController::class)
    ->name('marketplace.stays.index');

// Property detail page - rooms + reviews + book CTA. Carries the
// from/to/adults/children query params forward so a click into
// "Book" drops the guest into the (Packet 4) booking flow with a
// fully-seeded context (no re-typing dates).
Route::get('/stays/{slug}', \App\Http\Controllers\Marketplace\StaysShowController::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('marketplace.stays.show');

// Livewire checkout page - guest info + room pick + Paystack redirect.
// The pay() action delegates to CreateStaysBooking which switches to
// the property's tenant context, creates the Booking + AvailabilityBlock,
// initialises Paystack (subaccount split 98/2), then redirects the
// guest to Paystack's hosted card form.
Route::get('/stays/{slug}/book', \App\Livewire\Marketplace\StaysBookCheckout::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('marketplace.stays.book');

// Guest-submitted "something's wrong" report. XHR-only, queues an
// email to platform support with the reason + free-text. v1 has
// no DB persistence - inbox IS the moderator queue until volume
// warrants a /admin/reports view.
Route::post('/stays/{slug}/report', \App\Http\Controllers\Marketplace\StaysReportController::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('marketplace.stays.report');

// Paystack calls this back after the guest completes the card form.
// Central-domain equivalent of the per-tenant BookingWebhookController -
// looks up the Payment cross-tenant, re-enters the correct tenant
// scope, verifies the transaction with the master key, then hands off
// to ConfirmBookingPayment.
Route::get('/stays/pay/callback', \App\Http\Controllers\Marketplace\StaysPaymentCallbackController::class)
    ->name('marketplace.stays.pay.callback');

// Thank-you screen. Reachable only via the callback's redirect once
// ConfirmBookingPayment has flipped the booking to Confirmed.
Route::get('/stays/pay/success/{booking_ref}', \App\Http\Controllers\Marketplace\StaysPaymentSuccessController::class)
    ->where('booking_ref', '[A-Z0-9\-]+')
    ->name('marketplace.stays.pay.success');

// Soft-failure landing (guest cancelled the card form, verify said
// not-success, amount mismatch, etc.). The `?reason=<code>` maps to a
// friendly message via the view's match block.
Route::view('/stays/pay/failed', 'marketplace.stays.pay-failed')
    ->name('marketplace.stays.pay.failed');

// Post-stay review flow. Guest arrives via the signed link emailed
// at checkout (see MarkCheckedOut::maybeQueueStaysReviewInvite):
//   * signed 90-day TTL
//   * one review per booking (DB-enforced via reviews.booking_id unique)
//   * short-circuits to an "already reviewed" screen if the DB row exists
Route::get('/stays/reviews/{booking_ref}', [
        \App\Http\Controllers\Marketplace\StaysReviewController::class, 'show',
    ])
    ->where('booking_ref', '[A-Z0-9\-]+')
    ->middleware('signed')
    ->name('marketplace.stays.review.show');

Route::post('/stays/reviews/{booking_ref}', [
        \App\Http\Controllers\Marketplace\StaysReviewController::class, 'store',
    ])
    ->where('booking_ref', '[A-Z0-9\-]+')
    ->middleware('signed')
    ->name('marketplace.stays.review.store');

Route::get('/stays/reviews/{booking_ref}/thanks', [
        \App\Http\Controllers\Marketplace\StaysReviewController::class, 'thanks',
    ])
    ->where('booking_ref', '[A-Z0-9\-]+')
    ->name('marketplace.stays.review.thanks');

// Old /search stub predates the marketplace rename - 301 to /stays
// preserving query params so the earlier URL contract still resolves
// (bookmarks, prior SEO, the welcome-v2 hero search form).
Route::get('/search', function (\Illuminate\Http\Request $request) {
    $qs = $request->getQueryString();
    return redirect('/stays'.($qs ? '?'.$qs : ''), 301);
})->name('search');

// PRD §19: cheap subsystem ping for load balancer + uptime monitor.
Route::get('/health', HealthController::class)->name('health');

Route::get('/register', Register::class)->name('register');

/*
|--------------------------------------------------------------------------
| Unified identity login (docs/unified-login.md, Phase 1b)
|--------------------------------------------------------------------------
|
| Central-domain login backed by the `identity` guard. On successful auth
| PostLoginRouter picks the destination:
|   - 1 operator link + 0 guest links → /handoff/{link} (Phase 1c will SSO
|     into the tenant subdomain from there; Phase 1b shows a placeholder).
|   - 0 operators + ≥1 guest              → /reservations (Phase 2 stub).
|   - Any other combination               → /choose (account chooser).
|
| The `login` route name is what unblocks the register-page fallback:
| Route::has('login') now returns true on the central domain.
|
*/

Route::middleware('guest:identity')->group(function (): void {
    Route::get('/login', CentralLogin::class)->name('login');

    // Central-domain forgot / reset flow. Backs the "Forgot?" link on
    // /login. Reset link in the email points back at
    // /password/reset/{token}?email=… via Laravel's default builder.
    Route::get('/password/forgot', CentralForgotPassword::class)
        ->name('password.request');
    Route::get('/password/reset/{token}', CentralResetPassword::class)
        ->name('password.reset');
});

// Magic-link claim (docs/unified-login.md §3, Phase 2). No auth
// middleware — the signed URL IS the auth. On success the controller
// logs the guest into the identity guard and bounces to /reservations.
Route::get('/claim', IdentityClaimController::class)->name('central.claim');

Route::middleware('auth:identity')->group(function (): void {
    Route::post('/logout', IdentityLogoutController::class)->name('central.logout');
    Route::get('/choose', CentralChooser::class)->name('central.choose');
    Route::get('/handoff/{link}', IdentityHandoffController::class)->name('central.handoff');
    Route::get('/reservations', CentralReservations::class)->name('central.reservations');
});

// Paystack webhook for corporate wallet top-ups. Signed with the property's
// master key.
// Filters by reference prefix `KCT-` so subscription events arriving
// here are politely rejected with 422 (so we'd notice misrouting).
Route::post('/paystack/corporate-wallet-topup-webhook', CorporateWalletTopupWebhookController::class)
    ->name('paystack.corporate-wallet-topup-webhook');

/*
|--------------------------------------------------------------------------
| Corporate self-service portal
|--------------------------------------------------------------------------
|
| Lives on the central domain at /corporates/*. Backed by the `corporate`
| auth guard (corporate_members table). EnsureCorporateActive logs the
| session out if the underlying member or corporate is no longer Active.
|
*/

Route::prefix('corporates')->name('corporate.')->group(function (): void {
    Route::middleware('guest:corporate')->group(function (): void {
        Route::get('/login', CorporatePortalLogin::class)->name('login');
    });

    // Paystack callback. NO auth middleware — Paystack's redirect
    // strips the session cookie, so the controller does its own
    // best-effort verify via reference. The webhook is authoritative
    // for the actual wallet credit.
    Route::get('/wallet/callback', CorporateWalletCallbackController::class)
        ->name('wallet.callback');

    Route::middleware(['auth:corporate', EnsureCorporateActive::class])->group(function (): void {
        Route::post('/logout', CorporateLogoutController::class)->name('logout');

        Route::get('/', CorporatePortalDashboard::class)->name('dashboard');
        Route::get('/bookings', CorporatePortalBookings::class)->name('bookings.index');
        Route::get('/members', CorporatePortalMembers::class)->name('members.index');
        Route::get('/wallet', CorporatePortalWallet::class)->name('wallet');
    });
});

/*
|--------------------------------------------------------------------------
| Mail Previews (local only)
|--------------------------------------------------------------------------
| Renders transactional emails in-browser so we can eyeball branding
| without piping through a mailbox. Only mounted outside production.
*/
if (! app()->environment('production')) {
    Route::get('/dev/mail/preview/{template}', function (string $template) {
        return \App\Support\DevMailPreview::render($template);
    })->name('dev.mail.preview');

}
