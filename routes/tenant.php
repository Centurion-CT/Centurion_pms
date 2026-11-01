<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PostRegistrationLoginController;
use App\Http\Controllers\Dashboard\SearchCacheController;
use App\Http\Controllers\Paystack\BookingWebhookController;
use App\Http\Controllers\Tenant\BookingsCsvExportController;
use App\Http\Controllers\Tenant\FinanceCsvExportController;
use App\Http\Controllers\Tenant\OperatorInviteClaimController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Tenant\PublicLandingController;
use App\Http\Controllers\Tenant\SsoConsumeController;
use App\Http\Middleware\RequireCompleteOnboarding;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Booking\CategoryDetail;
use App\Livewire\Booking\RoomDetail;
use App\Livewire\Booking\RoomsBrowse;
use App\Livewire\Booking\UnitsBrowse;
use App\Livewire\Booking\WorkspacesBrowse;
use App\Livewire\Dashboard\DeskBookings\Form as DeskBookingForm;
use App\Livewire\Dashboard\DeskBookings\Index as DeskBookingsIndex;
use App\Livewire\Dashboard\Leases\Form as LeaseForm;
use App\Livewire\Dashboard\Leases\Index as LeasesIndex;
use App\Livewire\Dashboard\Memberships\Form as MembershipForm;
use App\Livewire\Dashboard\Memberships\Index as MembershipsIndex;
use App\Livewire\Dashboard\RentSchedule\Index as RentScheduleIndex;
use App\Livewire\Dashboard\Units\Form as UnitForm;
use App\Livewire\Dashboard\Units\Index as UnitsIndex;
use App\Livewire\Dashboard\Workspaces\Form as WorkspaceForm;
use App\Livewire\Dashboard\Workspaces\Index as WorkspacesIndex;
use App\Livewire\Dashboard\WorkspacePlans\Form as WorkspacePlanForm;
use App\Livewire\Dashboard\WorkspacePlans\Index as WorkspacePlansIndex;
use App\Livewire\Dashboard\Corporates\Form as TenantCorporatesForm;
use App\Livewire\Dashboard\Corporates\Index as TenantCorporatesIndex;
use App\Livewire\Dashboard\Corporates\Show as TenantCorporatesShow;
use App\Livewire\Dashboard\Customers\Form as CustomerForm;
use App\Livewire\Dashboard\Discounts\Form as DiscountForm;
use App\Livewire\Dashboard\Discounts\Index as DiscountsIndex;
use App\Livewire\Dashboard\Membership\Businesses as MembershipBusinesses;
use App\Livewire\Dashboard\Membership\Index as MembershipIndex;
use App\Livewire\Dashboard\Membership\Members as MembershipMembers;
use App\Livewire\Dashboard\Membership\Plans as MembershipPlans;
use App\Livewire\Dashboard\Membership\Show as MembershipShow;
use App\Livewire\Dashboard\Customers\Index as CustomersIndex;
use App\Livewire\Dashboard\Customers\Show as CustomerShow;
use App\Livewire\Dashboard\EmailTemplates\Edit as EmailTemplateEdit;
use App\Livewire\Dashboard\EmailTemplates\Index as EmailTemplatesIndex;
use App\Livewire\Dashboard\Finance\Index as FinanceIndex;
use App\Livewire\Dashboard\Integrations\DokweStorage as IntegrationsDokweStorage;
use App\Livewire\Dashboard\Integrations\Index as IntegrationsIndex;
use App\Livewire\Dashboard\Inventory\AuditLog as InventoryAuditLog;
use App\Livewire\Dashboard\Inventory\Index as InventoryIndex;
use App\Livewire\Dashboard\Inventory\OutletForm as InventoryOutletForm;
use App\Livewire\Dashboard\Inventory\OutletShow as InventoryOutletShow;
use App\Livewire\Dashboard\Inventory\ProductForm as InventoryProductForm;
use App\Livewire\Dashboard\Inventory\ReceiveStock as InventoryReceiveStock;
use App\Livewire\Dashboard\Inventory\RecipeForm as InventoryRecipeForm;
use App\Livewire\Dashboard\Inventory\Sheet as InventoryOutletSheet;
use App\Livewire\Dashboard\Stores\Disabled as StoreDisabled;
use App\Livewire\Dashboard\Stores\Index as StoreIndex;
use App\Livewire\Dashboard\Stores\IssueForm as StoreIssueForm;
use App\Livewire\Dashboard\Stores\ProductForm as StoreProductForm;
use App\Livewire\Dashboard\Stores\Products as StoreProducts;
use App\Livewire\Dashboard\Stores\Receive as StoreReceive;
use App\Http\Controllers\Tenant\PosDeviceDisconnectController;
use App\Http\Controllers\Tenant\PosDevicePairingController;
use App\Http\Controllers\Tenant\PosOfflineQueueController;
use App\Http\Controllers\Tenant\PosProductsJsonController;
use App\Livewire\Dashboard\Pos\LinkDevice as PosLinkDevice;
use App\Livewire\Dashboard\Pos\OutletPicker as PosOutletPicker;
use App\Livewire\Dashboard\Pos\Sales as PosSales;
use App\Livewire\Dashboard\Pos\SaleShow as PosSaleShow;
use App\Livewire\Dashboard\Pos\Terminal as PosTerminal;
use App\Livewire\Dashboard\Purchasing\PurchaseOrderForm as PurchasingOrderForm;
use App\Livewire\Dashboard\Purchasing\PurchaseOrderShow as PurchasingOrderShow;
use App\Livewire\Dashboard\Purchasing\PurchaseOrders as PurchasingOrders;
use App\Livewire\Dashboard\Purchasing\Suppliers as PurchasingSuppliers;
use App\Livewire\Dashboard\Housekeeping\Index as HousekeepingIndex;
use App\Livewire\Dashboard\Housekeeping\Inspections as HousekeepingInspections;
use App\Livewire\Dashboard\Housekeeping\InspectionShow as HousekeepingInspectionShow;
use App\Livewire\Dashboard\Housekeeping\RoomHistory as HousekeepingRoomHistory;
use App\Livewire\Dashboard\Notifications\Index as NotificationsIndex;
use App\Livewire\Dashboard\Payments\Index as PaymentsIndex;
use App\Livewire\Dashboard\Reports\Index as ReportsIndex;
use App\Livewire\Dashboard\Settings\Business as SettingsBusiness;
use App\Livewire\Dashboard\Settings\Profile as SettingsProfile;
use App\Livewire\Dashboard\Branches\Index as BranchesIndex;
use App\Livewire\Dashboard\GuestRequests\Index as GuestRequestsIndex;
use App\Livewire\Dashboard\Approvals\Index as ApprovalsIndex;
use App\Http\Controllers\Tenant\BranchSwitchController;
use App\Http\Controllers\Tenant\RoomQrCodeController;
use App\Livewire\Dashboard\Settings\PropertyForm as SettingsProperty;
use App\Livewire\Dashboard\Staff\Devices as StaffDevices;
use App\Livewire\Dashboard\Staff\Index as StaffIndex;
use App\Livewire\Dashboard\Staff\OperatorPermissions as StaffOperatorPermissions;
use App\Livewire\Dashboard\Summary\Index as SummaryIndex;
use App\Livewire\Dashboard\Complimentary\Index as ComplimentaryIndex;
use App\Livewire\Dashboard\CreditLines\Form as CreditLineForm;
use App\Livewire\Dashboard\CreditLines\Index as CreditLinesIndex;
use App\Livewire\Dashboard\CreditLines\Show as CreditLineShow;
use App\Livewire\Dashboard\InHouse\Index as InHouseIndex;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Livewire\Dashboard\Reservations\Show as ReservationsShow;
use App\Livewire\Dashboard\Accounting\Expenses\Index as AccountingExpensesIndex;
use App\Livewire\Dashboard\Accounting\Hub as AccountingHub;
use App\Livewire\Dashboard\Accounting\ProfitLoss\Index as AccountingProfitLossIndex;
use App\Livewire\Dashboard\Accounting\Revenue\Index as AccountingRevenueIndex;
use App\Livewire\Dashboard\Bookings\Index as BookingsIndex;
use App\Livewire\Dashboard\Bookings\ManualCreate as BookingsManualCreate;
use App\Livewire\Dashboard\Bookings\Show as BookingsShow;
use App\Http\Controllers\Tenant\Accounting\ExpenseExportController;
use App\Http\Controllers\Tenant\Accounting\ProfitLossExportController;
use App\Http\Controllers\Tenant\Accounting\RevenueExportController;
use App\Livewire\Dashboard\Invoices\Create as InvoicesCreate;
use App\Livewire\Dashboard\Invoices\Index as InvoicesIndex;
use App\Livewire\Dashboard\Invoices\Show as InvoicesShow;
use App\Livewire\Dashboard\Branding\Photos as BrandingPhotos;
use App\Livewire\Dashboard\Calendar\PropertyView as CalendarProperty;
use App\Livewire\Dashboard\Calendar\RoomView as CalendarRoom;
use App\Livewire\Dashboard\Home as DashboardHome;
use App\Livewire\Dashboard\Packages\Form as PackagesForm;
use App\Livewire\Dashboard\Packages\Index as PackagesIndex;
use App\Livewire\Dashboard\Pricing\Manage as PricingManage;
use App\Livewire\Dashboard\Reviews\Editor as ReviewEditor;
use App\Livewire\Dashboard\Reviews\Index as ReviewsIndex;
use App\Livewire\Dashboard\RoomCategories\Form as RoomCategoryForm;
use App\Livewire\Dashboard\RoomCategories\Index as RoomCategoriesIndex;
use App\Livewire\Dashboard\Rooms\Form as RoomForm;
use App\Livewire\Dashboard\Rooms\Index as RoomIndex;
use App\Livewire\Domain\Manage as DomainManage;
use App\Livewire\Guest\Cancel as GuestCancel;
use App\Livewire\Guest\Packages as GuestPackages;
use App\Livewire\Guest\PayBalance as GuestPayBalance;
use App\Livewire\Guest\RequestChange as GuestRequestChange;
use App\Livewire\Guest\Review as GuestReview;
use App\Livewire\Guest\Show as GuestShow;
use App\Livewire\Site\Checkout as SiteCheckout;
use App\Livewire\Site\ManageBooking as SiteManageBooking;
use App\Livewire\Onboarding\EmailVerification;
use App\Livewire\Onboarding\PhoneVerification;
use App\Livewire\Onboarding\PropertyBasics;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Routes served from any host that resolves to a tenant via DomainTenantFinder
| (subdomain or verified custom domain - PRD §6.6). The route group attaches
| Spatie's NeedsTenant middleware so requests with NO bound tenant 404 instead
| of falling through to central routes.
|
*/

Route::get('/__tenant-ping', function () {
    /** @var Tenant $tenant */
    $tenant = Tenant::current();

    return response()->json([
        'tenant_id' => $tenant->getKey(),
        'slug' => $tenant->slug,
        'host' => request()->getHost(),
    ]);
})->name('tenant.ping');

// The booking site (docs/MULTITENANCY.md, step 5; design/website/c-map):
// home with the map, apartments free for a stay, booking and payment,
// and finding a booking. A business with no live building yet sends `/`
// to the staff login, as before.
Route::get('/', [SiteController::class, 'home'])->name('tenant.landing');
Route::get('/apartments', [SiteController::class, 'results'])->name('site.results');
Route::get('/book/{branch}/{type}', SiteCheckout::class)->name('site.checkout');
Route::get('/manage', SiteManageBooking::class)->middleware('throttle:30,1')->name('site.manage');
Route::get('/sitemap.xml', [PublicLandingController::class, 'sitemap'])->name('tenant.sitemap');
Route::get('/robots.txt', [PublicLandingController::class, 'robots'])->name('tenant.robots');

// Branch-scoped public URLs - one per property under an org. Every
// booking-flow surface has an equivalent /branches/{slug}/... path so
// a multi-property tenant can share direct links per branch.
Route::prefix('branches/{property:slug}')->group(function (): void {
    Route::get('/', [PublicLandingController::class, 'show'])
        ->name('tenant.branch.show');
    Route::get('/rooms', RoomsBrowse::class)
        ->name('tenant.branch.rooms.browse');
    Route::get('/categories/{category:slug}', CategoryDetail::class)
        ->name('tenant.branch.categories.show');
    Route::get('/rooms/{room}', RoomDetail::class)
        ->name('tenant.branch.rooms.show');
});

// Legacy (single-property) URLs - kept for backwards compat so
// bookmarks + confirmation-email links from before multi-property
// keep resolving. RoomsBrowse / CategoryDetail / RoomDetail resolve
// their property via the Livewire component's PropertyContext fallback
// (property_id on the child model, then Tenant::properties()->first()).
Route::get('/rooms', RoomsBrowse::class)
    ->name('tenant.rooms.browse');

Route::get('/categories/{category:slug}', CategoryDetail::class)
    ->name('tenant.categories.show');

Route::get('/rooms/{room}', RoomDetail::class)
    ->name('tenant.rooms.show');

// Per-property guest QR portal. One QR per branch (hung in the
// lobby / on room-service menus). Guest scans → phone + room
// number + PIN → 8-tile portal. Auth is enforced inside the
// Livewire component via booking match + PIN.
Route::get('/portal/{qrSlug}', \App\Livewire\GuestPortal\RoomPortal::class)
    ->name('tenant.guest.portal');

// Legacy per-room QR - kept so any physical QRs printed under the
// earlier design still resolve. The component detects a room-slug
// vs property-slug at mount() time and pre-fills the room number
// so the guest skips one step.
Route::get('/room/{qrSlug}', \App\Livewire\GuestPortal\RoomPortal::class)
    ->name('tenant.guest.portal.legacy');

// Paystack callback for guest-portal card payments (extend stay,
// order, reserve). Verifies the transaction server-side, resolves
// the GuestPortalIntent, and redirects back to the portal home
// with a flash. The webhook fires the same resolver as a backup.
Route::get('/room/portal/pay/callback',
    \App\Http\Controllers\Tenant\GuestPortalPaymentCallbackController::class)
    ->name('tenant.guest.portal.pay.callback');

// Public workspaces browse - co-working operators surface plans +
// inventory + sign-up enquiry form.
Route::get('/workspaces', WorkspacesBrowse::class)
    ->name('tenant.workspaces.browse');

// Public residential units browse - landlord-style operators surface
// available units + lease enquiry form.
Route::get('/units', UnitsBrowse::class)
    ->name('tenant.units.browse');


// Guest self-service portal (PRD §6.4.4 / §13). Authentication is via the
// ?token= query string - see GuestPortalTokenService. Routes are tenant-
// scoped because the booking resolution uses the bound tenant context;
// hitting `/guest/booking/X?token=Y` on tenant A's host with a token issued
// on tenant B returns 404 (cross-tenant denied).
Route::prefix('/guest/booking/{bookingRef}')->group(function (): void {
    Route::get('/', GuestShow::class)->name('tenant.guest.show');
    Route::get('/cancel', GuestCancel::class)->name('tenant.guest.cancel');
    Route::get('/balance', GuestPayBalance::class)->name('tenant.guest.balance');
    Route::get('/change', GuestRequestChange::class)->name('tenant.guest.change');
    Route::get('/review', GuestReview::class)->name('tenant.guest.review');
    Route::get('/packages', GuestPackages::class)->name('tenant.guest.packages');
});

// Per-tenant Paystack webhook for guest bookings (PRD §11). Operator
// registers this URL in their own Paystack dashboard. CSRF-exempt; signature
// is verified inside the controller using the tenant's secret key.
Route::post('/paystack/booking-webhook', BookingWebhookController::class)
    ->name('tenant.paystack.booking-webhook');

// POS phone pairing consumer - the URL the QR encodes. Public: the code
// itself is the auth. Rate-limited as defence-in-depth (the code is
// 32-char alphanumeric, so guessing is already infeasible).
Route::get('/pos/pair/{code}', [PosDevicePairingController::class, 'claim'])
    ->middleware('throttle:20,1')
    ->name('tenant.pos.pair.claim');

// Landing page after a paired phone self-disconnects. Public because
// the disconnect action already logged the operator out.
Route::view('/pos/device-disconnected', 'errors.pos-device-disconnected')
    ->name('tenant.pos.device-disconnected');

// SSO handoff consumer (docs/unified-login.md §3, Phase 1c). No auth
// middleware - the signed URL IS the auth. The controller then logs
// the linked Operator into the `web` guard.
Route::get('/sso/consume', SsoConsumeController::class)->name('tenant.sso.consume');

// Centurion Desk "open on the web dashboard": consumes the one-time code
// minted by POST /api/mobile/v1/host/web-handoff (60s, single use) and
// signs the operator into the normal dashboard.
Route::get('/auth/app-handoff', [\App\Http\Controllers\Mobile\Host\WebHandoffController::class, 'consume'])
    ->name('tenant.mobile.app-handoff');

// Operator invite magic-link consumer. Not gated by guest/auth
// middleware - a signed URL is its own auth. Verifies the HMAC
// signature (Laravel `signed` middleware), then the controller
// bcrypt-checks the nonce, stashes the operator id in the session,
// and redirects to /invite/activate. NO login happens here - the
// account is un-activated until the activation page saves a
// password.
Route::get('/operator-invite/claim/{operator}/{token}', OperatorInviteClaimController::class)
    ->middleware('signed')
    ->name('tenant.operator-invite.claim');

// Invite-activation form. Reached only from the claim controller;
// gated by a session key so a random visit bounces to /login.
Route::get('/invite/activate', \App\Livewire\Tenant\Auth\ActivateInvite::class)
    ->name('tenant.invite.activate');

// Guest (unauthenticated operator) auth surface.
Route::middleware('guest:web')->group(function (): void {
    Route::get('/login', Login::class)->name('tenant.login');
    Route::get('/password/forgot', ForgotPassword::class)->name('tenant.password.request');
    Route::get('/password/reset/{token}', ResetPassword::class)->name('tenant.password.reset');
    Route::get('/auth/post-registration', PostRegistrationLoginController::class)
        ->name('tenant.auth.post-registration');
});

// Authenticated operator surface.
Route::middleware('auth:web')->group(function (): void {
    Route::post('/logout', LogoutController::class)->name('tenant.logout');

    // Onboarding wizard (PRD §6.1.1) - explicitly NOT gated by
    // RequireCompleteOnboarding so the operator can reach the steps that
    // make them complete.
    Route::get('/onboarding', function (): RedirectResponse {
        /** @var Operator $operator */
        $operator = auth()->guard('web')->user();
        $tenant = $operator->tenant;

        if (! $tenant->property()->exists()) {
            return redirect()->route('tenant.onboarding.property');
        }
        if ($operator->email_verified_at === null) {
            return redirect()->route('tenant.onboarding.email');
        }
        if (! $operator->hasResolvedPhoneStep()) {
            return redirect()->route('tenant.onboarding.phone');
        }

        return redirect()->route('tenant.dashboard');
    })->name('tenant.onboarding');

    Route::get('/onboarding/property', PropertyBasics::class)->name('tenant.onboarding.property');
    Route::get('/onboarding/email', EmailVerification::class)->name('tenant.onboarding.email');
    Route::get('/onboarding/phone', PhoneVerification::class)->name('tenant.onboarding.phone');

    // Dashboard home (PRD §6.2.1 / §15). Gated by completed onboarding.
    // Solo mode has no billing enforcement — the property runs on the
    // hotel's own infra, not on Keverna's subscription rails.
    Route::middleware([RequireCompleteOnboarding::class])->group(function (): void {
        Route::get('/dashboard', DashboardHome::class)
            ->middleware('role:dashboard')
            ->name('tenant.dashboard');
        // Opt-in — see keverna.domain_management in config/keverna.php.
        if (config('keverna.domain_management.enabled')) {
            Route::get('/domain', DomainManage::class)
                ->middleware('role:domain')
                ->name('tenant.domain');
        }

        // Room categories - manager-only inventory management.
        Route::middleware('role:categories')->group(function (): void {
            Route::get('/dashboard/room-categories', RoomCategoriesIndex::class)
                ->name('tenant.room-categories.index');
            Route::get('/dashboard/room-categories/new', RoomCategoryForm::class)
                ->name('tenant.room-categories.create');
            Route::get('/dashboard/room-categories/{roomCategory:id}/edit', RoomCategoryForm::class)
                ->name('tenant.room-categories.edit');
        });

        // Rooms CRUD (PRD §6.3) - manager-only.
        Route::middleware('role:rooms')->group(function (): void {
            Route::get('/dashboard/rooms', RoomIndex::class)->name('tenant.rooms.index');
            Route::get('/dashboard/rooms/create', RoomForm::class)->name('tenant.rooms.create');
            Route::get('/dashboard/rooms/{room}/edit', RoomForm::class)->name('tenant.rooms.edit');
            Route::get('/dashboard/rooms/{room}/pricing', PricingManage::class)
                ->name('tenant.rooms.pricing');
            Route::get('/dashboard/rooms/{room}/photos', function (\App\Models\Room $room) {
                $catId = $room->room_category_id;

                return $catId
                    ? redirect()->route('tenant.room-categories.edit', $catId)
                    : redirect()->route('tenant.room-categories.index');
            })->name('tenant.rooms.photos');

            // Printable QR code PNG for the in-room guest portal.
            // Returns the raw image; operators typically print + laminate
            // it and hang it in the room. Uses the room's qr_slug (not
            // id) so the URL a guest scans is unguessable.
            // Deliberately NOT ending in `.png` - nginx's static-file
            // rule intercepts *.png URLs and returns 404 before Laravel
            // sees the request. The controller still returns an
            // image/png response; the browser + downloader treat it
            // like an image regardless of the URL extension.
            Route::get('/dashboard/rooms/{room}/qr', RoomQrCodeController::class)
                ->name('tenant.rooms.qr');
        });

        // Guest requests queue - sees anything raised from the in-room
        // portal (complaints, taxi, transfer, order). role:reservations
        // gates it because front-desk staff are the primary responders,
        // not managers-only.
        Route::middleware('role:reservations')->group(function (): void {
            Route::get('/dashboard/guest-requests', GuestRequestsIndex::class)
                ->name('tenant.guest-requests.index');
            Route::get('/dashboard/approvals', ApprovalsIndex::class)
                ->name('tenant.approvals.index');

            // Search cache feed for the dashboard's IndexedDB store.
            // Rate-limited to 60 req/min per operator (a 30s poll +
            // some focus-driven refetches sit well under that).
            Route::get('/api/dashboard/search-cache', SearchCacheController::class)
                ->middleware('throttle:60,1')
                ->name('tenant.dashboard.search-cache');
        });

        // Calendars (PRD §6.2.3).
        Route::middleware('role:calendar')->group(function (): void {
            Route::get('/dashboard/calendar', CalendarProperty::class)->name('tenant.calendar');
            Route::get('/dashboard/rooms/{room}/calendar', CalendarRoom::class)
                ->name('tenant.rooms.calendar');
        });

        // Accounting hub + first app (Expenses). Branch-scoped via
        // PropertyContext inside the components. Export streams CSV.
        Route::middleware('role:accounting')->group(function (): void {
            Route::get('/dashboard/accounting', AccountingHub::class)
                ->name('tenant.accounting.hub');
            Route::get('/dashboard/accounting/expenses', AccountingExpensesIndex::class)
                ->name('tenant.accounting.expenses.index');
            Route::get('/dashboard/accounting/expenses/export', ExpenseExportController::class)
                ->name('tenant.accounting.expenses.export');
            Route::get('/dashboard/accounting/revenue', AccountingRevenueIndex::class)
                ->name('tenant.accounting.revenue.index');
            Route::get('/dashboard/accounting/revenue/export', RevenueExportController::class)
                ->name('tenant.accounting.revenue.export');
            Route::get('/dashboard/accounting/profit-loss', AccountingProfitLossIndex::class)
                ->name('tenant.accounting.profit-loss.index');
            Route::get('/dashboard/accounting/profit-loss/export', ProfitLossExportController::class)
                ->name('tenant.accounting.profit-loss.export');
        });

        // Property photos — feeds the stays detail hero + gallery.
        // Was previously "/dashboard/branding/photos" under the retired
        // Branding editor; kept the alias route so old bookmarks +
        // wire:navigate calls in the reviews editor still resolve.
        Route::middleware('role:branding')->group(function (): void {
            Route::get('/dashboard/photos', BrandingPhotos::class)->name('tenant.branding.photos');
            Route::get('/dashboard/branding/photos', BrandingPhotos::class);
        });

        Route::middleware('role:reviews')->group(function (): void {
            Route::get('/dashboard/reviews', ReviewsIndex::class)->name('tenant.reviews.index');
            Route::get('/dashboard/reviews/new', ReviewEditor::class)->name('tenant.reviews.create');
            Route::get('/dashboard/reviews/{reviewId}/edit', ReviewEditor::class)->name('tenant.reviews.edit');
        });

        // Bookings management (PRD §14).
        Route::middleware('role:bookings')->group(function (): void {
            Route::get('/dashboard/bookings', BookingsIndex::class)->name('tenant.bookings.index');
            Route::get('/dashboard/bookings/create', BookingsManualCreate::class)
                ->name('tenant.bookings.create');
            Route::get('/dashboard/bookings/export.csv', BookingsCsvExportController::class)
                ->name('tenant.bookings.export');
            Route::get('/dashboard/bookings/{bookingRef}', BookingsShow::class)
                ->name('tenant.bookings.show');

            // Guest invoices - printable, mid-stay or checkout billing.
            // Under the same role:bookings gate: whoever can see the
            // booking can also issue an invoice against it.
            Route::get('/dashboard/invoices', InvoicesIndex::class)
                ->name('tenant.invoices.index');
            Route::get('/dashboard/bookings/{bookingRef}/invoices/create', InvoicesCreate::class)
                ->name('tenant.invoices.create');
            Route::get('/dashboard/invoices/{invoice}', InvoicesShow::class)
                ->name('tenant.invoices.show');
            Route::get('/dashboard/invoices/{invoice}/pdf', [\App\Http\Controllers\Tenant\InvoicePdfController::class, 'download'])
                ->name('tenant.invoices.pdf');

            // Dynamic rates for the /stays online booking surface.
            // Front-desk pricing is unaffected — this maps to the
            // marketplace-side RateResolver only.
            Route::get('/dashboard/rates', \App\Livewire\Dashboard\Rates\Index::class)
                ->name('tenant.rates.index');
        });

        // Email templates - manager-only (Brand surface).
        Route::middleware('role:email_templates')->group(function (): void {
            Route::get('/dashboard/email-templates', EmailTemplatesIndex::class)
                ->name('tenant.email-templates.index');
            Route::get('/dashboard/email-templates/{key}', EmailTemplateEdit::class)
                ->name('tenant.email-templates.edit');
        });

        // Settings. Profile is universally available (every operator
        // can change their own password); property + staff are
        // manager-only.
        Route::get('/settings/profile', SettingsProfile::class)
            ->middleware('role:settings_profile')
            ->name('tenant.settings.profile');

        // Per-operator UI preferences (sidebar layout is the only one
        // now, more will land here). All operators have access — the
        // preference is scoped to the signed-in user, not a role.
        Route::put('/dashboard/preferences/sidebar-variant',
            [\App\Http\Controllers\Tenant\OperatorPreferencesController::class, 'updateSidebar'])
            ->name('tenant.preferences.sidebar-variant');
        Route::middleware('role:settings')->group(function (): void {
            Route::get('/settings/business', SettingsBusiness::class)
                ->name('tenant.settings.business');
            Route::get('/settings/property', SettingsProperty::class)
                ->name('tenant.settings.property');

            // Multi-branch - list + inline create. Full edit for each
            // branch reuses the existing property settings screen after
            // the operator switches into it via the sidebar switcher.
            Route::get('/dashboard/branches', BranchesIndex::class)
                ->name('tenant.branches.index');

            // Printable per-property portal QR - encodes the
            // /portal/{qr_slug} URL guests scan from the lobby.
            // Bind by id since operator-facing routes take numeric
            // ids elsewhere; Property's route-key defaults to slug
            // (for /branches/{property:slug} public URLs), so an
            // explicit :id keeps this dashboard route unambiguous.
            //
            // URL does NOT end in `.png` - nginx's static-file rule
            // intercepts *.png before Laravel sees the request and
            // returns 404 for the missing file. The controller still
            // returns image/png so the browser renders it as an image.
            Route::get('/dashboard/branches/{property:id}/qr',
                \App\Http\Controllers\Tenant\PropertyQrCodeController::class)
                ->name('tenant.branches.qr');
        });

        // All branches: money, tonight's rooms and live activity for
        // every branch. Managers, plus anyone granted the
        // overview.all_branches permission (role:overview).
        Route::get('/dashboard/all-branches', \App\Livewire\Dashboard\AllBranches\Index::class)
            ->middleware('role:overview')
            ->name('tenant.all-branches');
        // Old Portfolio address, from bookmarks.
        Route::redirect('/dashboard/portfolio', '/dashboard/all-branches');

        // Branch switcher - every authenticated operator can flip
        // context between branches they have access to. Auth middleware
        // (parent group) is enough; no role gate since read-scoped
        // operators still need to switch branches to see their queue.
        Route::post('/dashboard/branches/switch', BranchSwitchController::class)
            ->name('tenant.branches.switch');

        // Staff / user management - lives at its own top-level route
        // rather than nested under /settings so operators find it under
        // the workspace-admin cluster in the sidebar.
        Route::middleware('role:staff')->group(function (): void {
            Route::get('/dashboard/staff', StaffIndex::class)
                ->name('tenant.staff.index');
            Route::get('/dashboard/staff/{operator}/permissions', StaffOperatorPermissions::class)
                ->name('tenant.staff.permissions');
            Route::get('/dashboard/staff/devices', StaffDevices::class)
                ->name('tenant.staff.devices.index');
        });

        // Notifications - available to every operator.
        Route::get('/dashboard/notifications', NotificationsIndex::class)
            ->middleware('role:notifications')
            ->name('tenant.notifications.index');

        // Housekeeping - front desk + house cleaners + managers.
        Route::get('/dashboard/housekeeping', HousekeepingIndex::class)
            ->middleware('role:housekeeping')
            ->name('tenant.housekeeping.index');
        Route::get('/dashboard/housekeeping/inspections', HousekeepingInspections::class)
            ->middleware('role:housekeeping')
            ->name('tenant.housekeeping.inspections.index');
        Route::get('/dashboard/housekeeping/inspections/{inspection}', HousekeepingInspectionShow::class)
            ->middleware('role:housekeeping')
            ->name('tenant.housekeeping.inspections.show');
        Route::get('/dashboard/housekeeping/rooms/{room}', HousekeepingRoomHistory::class)
            ->middleware('role:housekeeping')
            ->name('tenant.housekeeping.rooms.show');

        // Payments - manager-only.
        Route::get('/dashboard/payments', PaymentsIndex::class)
            ->middleware('role:payments')
            ->name('tenant.payments.index');
        Route::get('/dashboard/payments/export.csv', \App\Http\Controllers\Tenant\PaymentsCsvExportController::class)
            ->middleware('role:payments')
            ->name('tenant.payments.export');

        // Refunds - held cash on cancelled/no-show bookings that
        // never got refunded. Same audience as Payments.
        Route::get('/dashboard/refunds', \App\Livewire\Dashboard\Refunds\Index::class)
            ->middleware('role:refunds')
            ->name('tenant.refunds.index');
        Route::get('/dashboard/refunds/export.csv', \App\Http\Controllers\Tenant\RefundsCsvExportController::class)
            ->middleware('role:refunds')
            ->name('tenant.refunds.export');

        // Summary - property-wide activity rollup (manager-only).
        // Composes FinanceSnapshot + ReportSnapshot + InventoryTimeline
        // into a single filterable view so owners don't have to hop
        // between modules.
        Route::get('/dashboard/summary', SummaryIndex::class)
            ->middleware('role:summary')
            ->name('tenant.summary');

        // Finance - comprehensive revenue analytics + forecast (manager-only).
        Route::middleware('role:finance')->group(function (): void {
            Route::get('/dashboard/finance', FinanceIndex::class)
                ->name('tenant.finance.index');
            Route::get('/dashboard/finance/export.csv', FinanceCsvExportController::class)
                ->name('tenant.finance.export');
            Route::get('/dashboard/finance/wallets', \App\Livewire\Dashboard\Finance\Wallets::class)
                ->name('tenant.finance.wallets');
        });

        // Integrations - third-party service connectors (manager-only).
        Route::middleware('role:integrations')->group(function (): void {
            Route::get('/dashboard/integrations', IntegrationsIndex::class)
                ->name('tenant.integrations.index');
            Route::get('/dashboard/integrations/dokwe-storage', IntegrationsDokweStorage::class)
                ->name('tenant.integrations.dokwe-storage');
        });

        // Central store - daily stock sheet, receive from suppliers,
        // issue to outlets. Manager + StockKeeper. `central-store`
        // redirects the whole family to /store/off when the property
        // runs on per_department inventory (no central store exists).
        // The /off landing sits outside the central-store middleware
        // so operators reaching it via bookmark / old link get a
        // one-click path to enable central-store mode instead of a
        // dead 404.
        Route::middleware('role:store')->group(function (): void {
            Route::get('/dashboard/store/off', StoreDisabled::class)
                ->name('tenant.store.off');
        });
        Route::middleware(['role:store', 'central-store'])->group(function (): void {
            Route::get('/dashboard/store', StoreIndex::class)
                ->name('tenant.store.index');
            Route::get('/dashboard/store/issue', StoreIssueForm::class)
                ->name('tenant.store.issue');
            Route::get('/dashboard/store/receive', StoreReceive::class)
                ->name('tenant.store.receive');

            Route::get('/dashboard/store/products', StoreProducts::class)
                ->name('tenant.store.products.index');
            Route::get('/dashboard/store/products/new', StoreProductForm::class)
                ->middleware('role.prices')
                ->name('tenant.store.products.create');
            Route::get('/dashboard/store/products/{product}/edit', StoreProductForm::class)
                ->name('tenant.store.products.edit');

            // Reconciliation - the "am I losing money?" report tying
            // receipts + issuances + outlet POS sales + variance for
            // a date range. Same guards as the rest of /store.
            Route::get('/dashboard/store/reconciliation',
                \App\Livewire\Dashboard\Stores\Reconciliation::class)
                ->name('tenant.store.reconciliation');
        });

        // Inventory management - outlets, products, stock. Manager +
        // InventoryClerk. The two "create" routes are additionally gated
        // by role.prices because creating an outlet sets tax_rate and
        // creating a product sets sell price / cost - those are manager
        // decisions.
        Route::middleware('role:inventory')->group(function (): void {
            Route::get('/dashboard/inventory', InventoryIndex::class)
                ->name('tenant.inventory.index');
            Route::get('/dashboard/inventory/audit-log', InventoryAuditLog::class)
                ->name('tenant.inventory.audit-log');
            Route::get('/dashboard/inventory/outlets/new', InventoryOutletForm::class)
                ->middleware('role.prices')
                ->name('tenant.inventory.outlets.create');
            Route::get('/dashboard/inventory/outlets/{outlet}', InventoryOutletShow::class)
                ->name('tenant.inventory.outlets.show');
            Route::get('/dashboard/inventory/outlets/{outlet}/edit', InventoryOutletForm::class)
                ->name('tenant.inventory.outlets.edit');
            Route::get('/dashboard/inventory/outlets/{outlet}/receive', InventoryReceiveStock::class)
                ->name('tenant.inventory.outlets.receive');
            Route::get('/dashboard/inventory/outlets/{outlet}/sheet', InventoryOutletSheet::class)
                ->name('tenant.inventory.outlets.sheet');
            Route::get('/dashboard/inventory/outlets/{outlet}/products/new', InventoryProductForm::class)
                ->middleware('role.prices')
                ->name('tenant.inventory.products.create');
            Route::get('/dashboard/inventory/products/{product}/edit', InventoryProductForm::class)
                ->name('tenant.inventory.products.edit');
            Route::get('/dashboard/inventory/products/{product}/recipe', InventoryRecipeForm::class)
                ->name('tenant.inventory.products.recipe');
        });

        // Supplies - housekeeping supply rooms (soaps, towels, bedsheets,
        // amenities). Scoped read + request-from-store surface so Front
        // desk / House cleaners / Supervisors can see what's on hand and
        // raise a request without holding full inventory admin. The Show
        // controller 404s any outlet that isn't OutletType::Housekeeping,
        // so this module can't be used to peek at bar / kitchen stock.
        Route::middleware('role:supplies')->group(function (): void {
            Route::get('/dashboard/supplies', \App\Livewire\Dashboard\Supplies\Index::class)
                ->name('tenant.supplies.index');
            Route::get('/dashboard/supplies/{outlet}', \App\Livewire\Dashboard\Supplies\Show::class)
                ->name('tenant.supplies.show');
        });

        // Purchasing - conceptually part of the store (stock lands
        // in the store from a supplier via a PO). Gated on role:store so
        // Manager + StockKeeper can reach it; Inventory Clerk cannot.
        // Also 404'd in per_department mode - the PO flow assumes
        // central-store receiving. Per-department direct-to-outlet
        // purchasing is a separate reshape when we get there.
        Route::middleware(['role:store', 'central-store'])->group(function (): void {
            Route::get('/dashboard/purchasing/suppliers', PurchasingSuppliers::class)
                ->name('tenant.purchasing.suppliers.index');
            Route::get('/dashboard/purchasing/orders', PurchasingOrders::class)
                ->name('tenant.purchasing.orders.index');
            Route::get('/dashboard/purchasing/orders/new', PurchasingOrderForm::class)
                ->name('tenant.purchasing.orders.create');
            Route::get('/dashboard/purchasing/orders/{order}/edit', PurchasingOrderForm::class)
                ->name('tenant.purchasing.orders.edit');
            Route::get('/dashboard/purchasing/orders/{order}', PurchasingOrderShow::class)
                ->name('tenant.purchasing.orders.show');
        });

        // POS terminal - Manager + PosStaff (per-outlet gated inside components).
        // The linked-device middleware is a no-op for desktop kiosk sessions
        // (session key absent); it kicks in only when the request came from
        // a phone paired via /pos/pair/{code}.
        Route::middleware(['role:pos', 'linked-device'])->group(function (): void {
            Route::get('/dashboard/pos', PosOutletPicker::class)
                ->name('tenant.pos.pick-outlet');

            // Static-path routes MUST come before /dashboard/pos/{outlet}
            // - Laravel matches in definition order, and the wildcard
            // would swallow "heartbeat" / "sales" / "queue" otherwise.
            Route::get('/dashboard/pos/heartbeat', [PosOfflineQueueController::class, 'heartbeat'])
                ->name('tenant.pos.heartbeat');
            Route::post('/dashboard/pos/queue', [PosOfflineQueueController::class, 'flush'])
                ->name('tenant.pos.queue.flush');
            Route::get('/dashboard/pos/link-device', PosLinkDevice::class)
                ->name('tenant.pos.link-device');
            Route::post('/dashboard/pos/link-device/disconnect', PosDeviceDisconnectController::class)
                ->name('tenant.pos.link-device.disconnect');
            Route::get('/dashboard/pos/sales/list', PosSales::class)
                ->name('tenant.pos.sales.index');

            // Shift ledger + per-shift report + CSV export. Static paths
            // MUST come before the /dashboard/pos/{outlet} wildcard.
            Route::get('/dashboard/pos/shifts', \App\Livewire\Dashboard\Pos\Shifts::class)
                ->name('tenant.pos.shifts.index');
            Route::get('/dashboard/pos/shifts/{shift}', \App\Livewire\Dashboard\Pos\ShiftShow::class)
                ->name('tenant.pos.shifts.show');
            Route::get('/dashboard/pos/shifts/{shift}/export.csv', \App\Http\Controllers\Tenant\OutletShiftExportController::class)
                ->name('tenant.pos.shifts.export');

            // Outlet-scoped product feed for the offline cache. Must sit
            // ABOVE the /dashboard/pos/{outlet} wildcard route below.
            Route::get('/dashboard/pos/{outlet}/products.json', PosProductsJsonController::class)
                ->name('tenant.pos.products.json');
            Route::get('/dashboard/pos/sales/{sale}', PosSaleShow::class)
                ->name('tenant.pos.sales.show');

            Route::get('/dashboard/pos/{outlet}', PosTerminal::class)
                ->name('tenant.pos.terminal');
        });

        // Reports - manager + reservation officer.
        Route::get('/dashboard/reports', ReportsIndex::class)
            ->middleware('role:reports')
            ->name('tenant.reports.index');
        Route::get('/dashboard/reports/staff-activity/export.csv',
                   \App\Http\Controllers\Tenant\StaffActivityCsvExportController::class)
            ->middleware('role:reports')
            ->name('tenant.reports.staff-activity.export');

        // Reservations - front-desk operations console.
        Route::middleware('role:reservations')->group(function (): void {
            Route::get('/dashboard/reservations', ReservationsIndex::class)
                ->name('tenant.reservations.index');
            Route::get('/dashboard/reservations/{bookingRef}', ReservationsShow::class)
                ->name('tenant.reservations.show');
            // Drain endpoint for reservations queued offline in
            // IndexedDB. Idempotent via client-generated offline_key,
            // so a retry after a partial network fail never lands
            // as a duplicate booking. See
            // resources/js/offline-reservations.js for the client.
            Route::post('/dashboard/reservations/offline-sync', \App\Http\Controllers\Tenant\OfflineReservationSyncController::class)
                ->name('tenant.reservations.offline-sync');
        });

        // In-house - who's checked in right now.
        Route::middleware('role:in_house')->group(function (): void {
            Route::get('/dashboard/in-house', InHouseIndex::class)
                ->name('tenant.in-house.index');
        });

        // Complimentary breakfast roster - kitchen prep + front-desk lookup.
        Route::middleware('role:complimentary')->group(function (): void {
            Route::get('/dashboard/complimentary', ComplimentaryIndex::class)
                ->name('tenant.complimentary.index');
        });

        // Membership - ancillary services (gym / spa / snooker / pool /
        // any recurring service the hotel runs beside its rooms).
        Route::middleware('role:membership')->group(function (): void {
            Route::get('/dashboard/membership', MembershipIndex::class)
                ->name('tenant.membership.index');
            Route::get('/dashboard/membership/businesses', MembershipBusinesses::class)
                ->name('tenant.membership.businesses');
            Route::get('/dashboard/membership/plans', MembershipPlans::class)
                ->name('tenant.membership.plans');
            Route::get('/dashboard/membership/members', MembershipMembers::class)
                ->name('tenant.membership.members');
            Route::get('/dashboard/membership/{memberRef}', MembershipShow::class)
                ->name('tenant.membership.show');
        });

        // Credit lines - house-account tabs.
        Route::middleware('role:credit_lines')->group(function (): void {
            Route::get('/dashboard/credit-lines', CreditLinesIndex::class)
                ->name('tenant.credit-lines.index');
            Route::get('/dashboard/credit-lines/new', CreditLineForm::class)
                ->name('tenant.credit-lines.create');
            Route::get('/dashboard/credit-lines/{creditLine}/edit', CreditLineForm::class)
                ->name('tenant.credit-lines.edit');
            Route::get('/dashboard/credit-lines/{creditLine}', CreditLineShow::class)
                ->name('tenant.credit-lines.show');
        });

        // Discounts & coupons - manager + reservation officer.
        Route::middleware('role:discounts')->group(function (): void {
            Route::get('/dashboard/discounts', DiscountsIndex::class)
                ->name('tenant.discounts.index');
            Route::get('/dashboard/discounts/new', DiscountForm::class)
                ->name('tenant.discounts.create');
            Route::get('/dashboard/discounts/{discount}/edit', DiscountForm::class)
                ->name('tenant.discounts.edit');
        });

        // Packages (add-ons / complimentary extras) - manager + reservation officer.
        Route::middleware('role:packages')->group(function (): void {
            Route::get('/dashboard/packages', PackagesIndex::class)
                ->name('tenant.packages.index');
            Route::get('/dashboard/packages/new', PackagesForm::class)
                ->name('tenant.packages.create');
            Route::get('/dashboard/packages/{package}/edit', PackagesForm::class)
                ->name('tenant.packages.edit');
        });

        // Workspaces (Co-working segment) - manager-only inventory.
        Route::middleware('role:workspaces')->group(function (): void {
            Route::get('/dashboard/workspaces', WorkspacesIndex::class)
                ->name('tenant.workspaces.index');
            Route::get('/dashboard/workspaces/new', WorkspaceForm::class)
                ->name('tenant.workspaces.create');
            Route::get('/dashboard/workspaces/{workspace}/edit', WorkspaceForm::class)
                ->name('tenant.workspaces.edit');
        });
        Route::middleware('role:workspace_plans')->group(function (): void {
            Route::get('/dashboard/workspace-plans', WorkspacePlansIndex::class)
                ->name('tenant.workspace-plans.index');
            Route::get('/dashboard/workspace-plans/new', WorkspacePlanForm::class)
                ->name('tenant.workspace-plans.create');
            Route::get('/dashboard/workspace-plans/{workspacePlan}/edit', WorkspacePlanForm::class)
                ->name('tenant.workspace-plans.edit');
        });
        Route::middleware('role:memberships')->group(function (): void {
            Route::get('/dashboard/memberships', MembershipsIndex::class)
                ->name('tenant.memberships.index');
            Route::get('/dashboard/memberships/new', MembershipForm::class)
                ->name('tenant.memberships.create');
            Route::get('/dashboard/memberships/{membership}/edit', MembershipForm::class)
                ->name('tenant.memberships.edit');
        });
        Route::middleware('role:desk_bookings')->group(function (): void {
            Route::get('/dashboard/desk-bookings', DeskBookingsIndex::class)
                ->name('tenant.desk-bookings.index');
            Route::get('/dashboard/desk-bookings/new', DeskBookingForm::class)
                ->name('tenant.desk-bookings.create');
            Route::get('/dashboard/desk-bookings/{deskBooking}/edit', DeskBookingForm::class)
                ->name('tenant.desk-bookings.edit');
        });

        // Residential leases (Residential segment) - manager-only inventory.
        Route::middleware('role:units')->group(function (): void {
            Route::get('/dashboard/units', UnitsIndex::class)
                ->name('tenant.units.index');
            Route::get('/dashboard/units/new', UnitForm::class)
                ->name('tenant.units.create');
            Route::get('/dashboard/units/{unit}/edit', UnitForm::class)
                ->name('tenant.units.edit');
        });
        Route::middleware('role:leases')->group(function (): void {
            Route::get('/dashboard/leases', LeasesIndex::class)
                ->name('tenant.leases.index');
            Route::get('/dashboard/leases/new', LeaseForm::class)
                ->name('tenant.leases.create');
            Route::get('/dashboard/leases/{lease}/edit', LeaseForm::class)
                ->name('tenant.leases.edit');
        });
        Route::middleware('role:rent_schedule')->group(function (): void {
            Route::get('/dashboard/rent-schedule', RentScheduleIndex::class)
                ->name('tenant.rent-schedule.index');
        });

        // Corporates - list + read-only detail of Keverna B2B partners
        // that use this property, plus (for Managers) tenant-side
        // registration + staff profiling. The Form itself gates edits
        // to corps created by this tenant so a Manager can't rewrite
        // a centrally-onboarded corp's KYC.
        Route::middleware('role:corporates')->group(function (): void {
            Route::get('/dashboard/corporates', TenantCorporatesIndex::class)
                ->name('tenant.corporates.index');
            Route::get('/dashboard/corporates/new', TenantCorporatesForm::class)
                ->name('tenant.corporates.create');
            Route::get('/dashboard/corporates/{corporate}/edit', TenantCorporatesForm::class)
                ->name('tenant.corporates.edit');
            Route::get('/dashboard/corporates/{corporate}', TenantCorporatesShow::class)
                ->name('tenant.corporates.show');
        });

        // Customers (CRM) - manager / reservation officer / front desk.
        Route::middleware('role:customers')->group(function (): void {
            Route::get('/dashboard/customers', CustomersIndex::class)
                ->name('tenant.customers.index');
            Route::get('/dashboard/customers/new', CustomerForm::class)
                ->name('tenant.customers.create');
            Route::get('/dashboard/customers/{customer}', CustomerShow::class)
                ->name('tenant.customers.show');
            Route::get('/dashboard/customers/{customer}/edit', CustomerForm::class)
                ->name('tenant.customers.edit');
            Route::get('/dashboard/customers/{customer}/wallet', \App\Livewire\Dashboard\Customers\Wallet::class)
                ->name('tenant.customers.wallet');
            Route::get('/dashboard/customers/{customer}/wallet/statement.pdf', [\App\Http\Controllers\Tenant\CustomerWalletStatementPdfController::class, 'download'])
                ->name('tenant.customers.wallet.statement');
            Route::get('/dashboard/customers/{customer}/purchases/export.csv', \App\Http\Controllers\Tenant\CustomerPurchasesExportController::class)
                ->name('tenant.customers.purchases.export');
        });
    });
});
