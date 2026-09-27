<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Paystack\CorporateWalletTopupWebhookController;
use App\Http\Controllers\Portal\CorporateLogoutController;
use App\Http\Controllers\Portal\CorporateWalletCallbackController;
use App\Http\Middleware\EnsureCorporateActive;
use App\Livewire\Portal\Corporate\Auth\Login as CorporatePortalLogin;
use App\Livewire\Portal\Corporate\Bookings as CorporatePortalBookings;
use App\Livewire\Portal\Corporate\Dashboard as CorporatePortalDashboard;
use App\Livewire\Portal\Corporate\Members as CorporatePortalMembers;
use App\Livewire\Portal\Corporate\Wallet as CorporatePortalWallet;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Solo Web Routes
|--------------------------------------------------------------------------
|
| Public-facing surface for the hotel's own domain: guest booking flow,
| corporate portal, health check, webhook receivers. The operator
| dashboard lives in routes/tenant.php.
|
| The `/` route belongs to routes/tenant.php (PublicLandingController) —
| we don't shadow it here.
|
*/

// PRD §19: cheap subsystem ping for load balancer + uptime monitor.
Route::get('/health', HealthController::class)->name('health');

// Legal - linked from every marketing surface footer + the cookie-consent banner.
Route::view('/cookies', 'legal.cookies')->name('legal.cookies');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms',   'legal.terms')->name('legal.terms');
Route::view('/ndpr',    'legal.ndpr')->name('legal.ndpr');

// Newsletter one-click unsubscribe.
Route::get('/newsletter/unsubscribe/{token}', \App\Http\Controllers\Marketing\NewsletterUnsubscribeController::class)
    ->name('newsletter.unsubscribe');

// SEO plumbing.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');
Route::get('/llms.txt', \App\Http\Controllers\LlmsGuideController::class)->name('llms-guide');

// Property detail page - rooms + reviews + book CTA. Solo mode has one
// property so this is effectively the hotel's public site. Multi-branch
// installs get one page per branch, chosen from `/` via the property
// landing controller.
Route::get('/stays/{slug}', \App\Http\Controllers\Marketplace\StaysShowController::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('marketplace.stays.show');

// Livewire checkout — guest info + room pick + Paystack redirect.
Route::get('/stays/{slug}/book', \App\Livewire\Marketplace\StaysBookCheckout::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('marketplace.stays.book');

// Guest-submitted "something's wrong" report.
Route::post('/stays/{slug}/report', \App\Http\Controllers\Marketplace\StaysReportController::class)
    ->where('slug', '[a-z0-9\-]+')
    ->name('marketplace.stays.report');

// Paystack card-flow callback + terminal screens.
Route::get('/stays/pay/callback', \App\Http\Controllers\Marketplace\StaysPaymentCallbackController::class)
    ->name('marketplace.stays.pay.callback');

Route::get('/stays/pay/success/{booking_ref}', \App\Http\Controllers\Marketplace\StaysPaymentSuccessController::class)
    ->where('booking_ref', '[A-Z0-9\-]+')
    ->name('marketplace.stays.pay.success');

Route::view('/stays/pay/failed', 'marketplace.stays.pay-failed')
    ->name('marketplace.stays.pay.failed');

// Post-stay review flow. Guest arrives via the signed link emailed at
// checkout (see MarkCheckedOut::maybeQueueStaysReviewInvite).
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

// Paystack webhook for corporate wallet top-ups. Signed with the property's master key.
Route::post('/paystack/corporate-wallet-topup-webhook', CorporateWalletTopupWebhookController::class)
    ->name('paystack.corporate-wallet-topup-webhook');

/*
|--------------------------------------------------------------------------
| Corporate self-service portal
|--------------------------------------------------------------------------
|
| Guest-facing corporate accounts. Backed by the `corporate` auth guard
| (corporate_members table). EnsureCorporateActive logs the session out
| if the underlying member or corporate is no longer Active.
|
*/

Route::prefix('corporates')->name('corporate.')->group(function (): void {
    Route::middleware('guest:corporate')->group(function (): void {
        Route::get('/login', CorporatePortalLogin::class)->name('login');
    });

    // Paystack callback. NO auth middleware — Paystack's redirect strips
    // the session cookie, so the controller does its own best-effort
    // verify via reference. The webhook is authoritative for the actual
    // wallet credit.
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
*/
if (! app()->environment('production')) {
    Route::get('/dev/mail/preview/{template}', function (string $template) {
        return \App\Support\DevMailPreview::render($template);
    })->name('dev.mail.preview');
}
