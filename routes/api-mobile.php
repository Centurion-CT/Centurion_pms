<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\BookingsController;
use App\Http\Controllers\Mobile\Host\BookingsController as HostBookingsController;
use App\Http\Controllers\Mobile\Host\CalendarController as HostCalendarController;
use App\Http\Controllers\Mobile\Host\DeviceController;
use App\Http\Controllers\Mobile\Host\GuestRequestsController as HostGuestRequestsController;
use App\Http\Controllers\Mobile\Host\InboxController as HostInboxController;
use App\Http\Controllers\Mobile\Host\LookupController as HostLookupController;
use App\Http\Controllers\Mobile\Host\NotificationsController as HostNotificationsController;
use App\Http\Controllers\Mobile\Host\RoomsController as HostRoomsController;
use App\Http\Controllers\Mobile\Host\SearchController as HostSearchController;
use App\Http\Controllers\Mobile\Host\SessionController as HostSessionController;
use App\Http\Controllers\Mobile\Host\TodayController as HostTodayController;
use App\Http\Controllers\Mobile\Host\WebHandoffController as HostWebHandoffController;
use App\Http\Controllers\Mobile\InspectionsController;
use App\Http\Controllers\Mobile\InventoryController;
use App\Http\Controllers\Mobile\ManagerController;
use App\Http\Controllers\Mobile\PairController;
use App\Http\Controllers\Mobile\PosController;
use App\Http\Controllers\Mobile\ReservationsController;
use App\Http\Controllers\Mobile\Staff\ChecksController as StaffChecksController;
use App\Http\Controllers\Mobile\Staff\RoomsController as StaffRoomsController;
use App\Http\Controllers\Mobile\Staff\SessionController as StaffSessionController;
use App\Http\Controllers\Mobile\Staff\SuppliesController as StaffSuppliesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API — /api/mobile/v1
|--------------------------------------------------------------------------
| Bearer-token JSON routes for the Flutter app. Registered in
| bootstrap/app.php under the tenant-subdomain group, so every route
| here runs with a resolved tenant + no cookies + no CSRF.
|
| Anonymous:  /pair  (consumes a MobilePairingCode issued from web)
| Authed:     everything else, guarded by:
|               - auth:sanctum  (bearer token)
|               - mobile.device (device linked + not revoked)
|               - mobile.idempotency (non-GETs require Idempotency-Key)
*/

// Anonymous — no auth required, this is how the app gets its token.
Route::post('pair', [PairController::class, 'consume'])->name('mobile.pair');

Route::middleware(['auth:sanctum', 'mobile.device', 'mobile.idempotency'])
    ->group(function () {
        // ── Auth ────────────────────────────────────────────────
        Route::get('me', [AuthController::class, 'me'])->name('mobile.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('mobile.logout');

        // ── Inspections ─────────────────────────────────────────
        Route::get('inspections', [InspectionsController::class, 'index'])->name('mobile.inspections.index');
        Route::get('inspections/{inspection}', [InspectionsController::class, 'show'])->name('mobile.inspections.show');
        Route::post('inspections/{inspection}/submit', [InspectionsController::class, 'submit'])->name('mobile.inspections.submit');
        Route::post('inspections/{inspection}/verify', [InspectionsController::class, 'verify'])->name('mobile.inspections.verify');
        Route::post('inspections/{inspection}/sign-off', [InspectionsController::class, 'signOff'])->name('mobile.inspections.sign-off');

        // ── Rooms ───────────────────────────────────────────────
        Route::get('rooms/{room}/history', [InspectionsController::class, 'roomHistory'])->name('mobile.rooms.history');

        // ── Reservations ────────────────────────────────────────
        Route::get('reservations', [ReservationsController::class, 'index'])->name('mobile.reservations.index');
        Route::get('reservations/{ref}', [ReservationsController::class, 'show'])->name('mobile.reservations.show');
        Route::post('reservations/{ref}/check-in', [ReservationsController::class, 'checkIn'])->name('mobile.reservations.check-in');
        Route::post('reservations/{ref}/check-out', [ReservationsController::class, 'checkOut'])->name('mobile.reservations.check-out');
        Route::post('reservations/{ref}/no-show', [ReservationsController::class, 'noShow'])->name('mobile.reservations.no-show');
        Route::post('reservations/{ref}/cancel', [ReservationsController::class, 'cancel'])->name('mobile.reservations.cancel');
        Route::post('reservations/{ref}/payment', [ReservationsController::class, 'recordPayment'])->name('mobile.reservations.payment');

        // ── Manual booking ──────────────────────────────────────
        Route::get('rooms/available', [BookingsController::class, 'availableRooms'])->name('mobile.rooms.available');
        Route::post('bookings', [BookingsController::class, 'create'])->name('mobile.bookings.create');

        // ── POS ─────────────────────────────────────────────────
        Route::get('outlets', [PosController::class, 'outlets'])->name('mobile.outlets.index');
        Route::get('outlets/{outlet}/products', [PosController::class, 'products'])->name('mobile.outlets.products');
        Route::post('outlets/{outlet}/sales', [PosController::class, 'createSale'])->name('mobile.outlets.sales');
        Route::get('sales/{reference}', [PosController::class, 'showSale'])->name('mobile.sales.show');

        // ── Inventory ───────────────────────────────────────────
        Route::get('inventory/store-products', [InventoryController::class, 'storeProducts'])->name('mobile.inventory.store-products');
        Route::get('stock-requests', [InventoryController::class, 'requestsIndex'])->name('mobile.stock-requests.index');
        Route::post('stock-requests', [InventoryController::class, 'createRequest'])->name('mobile.stock-requests.create');
        Route::post('stock-requests/{id}/approve', [InventoryController::class, 'approveRequest'])->name('mobile.stock-requests.approve');
        Route::post('stock-requests/{id}/reject', [InventoryController::class, 'rejectRequest'])->name('mobile.stock-requests.reject');

        // ── Manager glance ──────────────────────────────────────
        Route::get('manager/glance', [ManagerController::class, 'glance'])->name('mobile.manager.glance');
    });

/*
| Staff app (housekeepers and porters) — docs/staff-app/PLAN.md.
| Same bearer token + device + idempotency guards, plus staff.app
| (house_cleaner / porter only).
*/
Route::middleware(['auth:sanctum', 'mobile.device', 'mobile.idempotency', 'staff.app'])
    ->prefix('staff')
    ->name('mobile.staff.')
    ->group(function () {
        Route::get('session', [StaffSessionController::class, 'session'])->name('session');
        Route::get('me', [StaffSessionController::class, 'me'])->name('me');

        Route::get('rooms', [StaffRoomsController::class, 'index'])->name('rooms.index');
        Route::get('rooms/{inspection}', [StaffRoomsController::class, 'show'])->whereNumber('inspection')->name('rooms.show');
        Route::post('rooms/{inspection}/start', [StaffRoomsController::class, 'start'])->whereNumber('inspection')->name('rooms.start');
        Route::post('rooms/{inspection}/items/{item}', [StaffRoomsController::class, 'tick'])->whereNumber(['inspection', 'item'])->name('rooms.tick');
        Route::post('rooms/{inspection}/finish', [StaffRoomsController::class, 'finish'])->whereNumber('inspection')->name('rooms.finish');

        Route::get('checks', [StaffChecksController::class, 'index'])->name('checks.index');
        Route::get('checks/{inspection}', [StaffChecksController::class, 'show'])->whereNumber('inspection')->name('checks.show');
        Route::post('checks/{inspection}/ready', [StaffChecksController::class, 'ready'])->whereNumber('inspection')->name('checks.ready');
        Route::post('checks/{inspection}/send-back', [StaffChecksController::class, 'sendBack'])->whereNumber('inspection')->name('checks.send-back');

        Route::get('supplies', [StaffSuppliesController::class, 'index'])->name('supplies.index');
        Route::post('supplies', [StaffSuppliesController::class, 'store'])->name('supplies.store');
    });

// ── Host app (Keverna for Hosts) ────────────────────────────────
// Owners, managers, front desk, reservation officers. `host.role`
// rejects other roles (403 role_not_supported) and binds the branch
// (operators.current_property_id, or X-Keverna-Property for one
// request) so every host endpoint is scoped to it. See
// docs/host-app/PHASE-1.md. Centurion Desk
// (docs/desk-app/PLAN.md) uses the same endpoints.
Route::middleware(['auth:sanctum', 'mobile.device', 'mobile.idempotency', 'host.role'])
    ->prefix('host')
    ->group(function () {
        Route::get('session', [HostSessionController::class, 'session'])->name('mobile.host.session');
        Route::get('properties', [HostSessionController::class, 'properties'])->name('mobile.host.properties');
        Route::post('properties/{id}/switch', [HostSessionController::class, 'switch'])->name('mobile.host.properties.switch');
        Route::get('today', HostTodayController::class)->name('mobile.host.today');

        // ── Phase 2: front desk (docs/host-app/PHASE-2.md) ──────
        Route::get('meta', [HostLookupController::class, 'meta'])->name('mobile.host.meta');
        Route::get('rooms/available', [HostLookupController::class, 'availableRooms'])->name('mobile.host.rooms.available');
        Route::get('customers/lookup', [HostLookupController::class, 'customerLookup'])->name('mobile.host.customers.lookup');

        Route::get('bookings', [HostBookingsController::class, 'index'])->name('mobile.host.bookings.index');
        Route::post('bookings', [HostBookingsController::class, 'store'])->name('mobile.host.bookings.store');
        Route::get('bookings/{ref}', [HostBookingsController::class, 'show'])->name('mobile.host.bookings.show');
        Route::get('bookings/{ref}/extend-quote', [HostBookingsController::class, 'extendQuote'])->name('mobile.host.bookings.extend-quote');
        Route::post('bookings/{ref}/payments', [HostBookingsController::class, 'takePayment'])->name('mobile.host.bookings.payments');
        Route::post('bookings/{ref}/check-in', [HostBookingsController::class, 'checkIn'])->name('mobile.host.bookings.check-in');
        Route::post('bookings/{ref}/check-out', [HostBookingsController::class, 'checkOut'])->name('mobile.host.bookings.check-out');
        Route::post('bookings/{ref}/charges', [HostBookingsController::class, 'addCharge'])->name('mobile.host.bookings.charges');
        Route::post('bookings/{ref}/extend', [HostBookingsController::class, 'extend'])->name('mobile.host.bookings.extend');
        Route::post('bookings/{ref}/no-show', [HostBookingsController::class, 'noShow'])->name('mobile.host.bookings.no-show');
        Route::post('bookings/{ref}/cancel', [HostBookingsController::class, 'cancel'])->name('mobile.host.bookings.cancel');

        Route::post('web-handoff', [HostWebHandoffController::class, 'issue'])->name('mobile.host.web-handoff');

        // ── Phase 3: calendar, rooms, inbox, alerts, search (docs/host-app/PHASE-3.md) ──
        Route::get('calendar', HostCalendarController::class)->name('mobile.host.calendar');

        Route::get('rooms', [HostRoomsController::class, 'index'])->name('mobile.host.rooms.index');
        Route::post('rooms/{id}/status', [HostRoomsController::class, 'status'])->whereNumber('id')->name('mobile.host.rooms.status');
        Route::post('rooms/{id}/assign-cleaner', [HostRoomsController::class, 'assignCleaner'])->whereNumber('id')->name('mobile.host.rooms.assign-cleaner');
        Route::get('rooms/{id}/history', [HostRoomsController::class, 'history'])->whereNumber('id')->name('mobile.host.rooms.history');
        Route::get('housekeepers', [HostRoomsController::class, 'housekeepers'])->name('mobile.host.housekeepers');

        Route::get('inbox/counts', [HostInboxController::class, 'counts'])->name('mobile.host.inbox.counts');
        Route::get('inbox/approvals', [HostInboxController::class, 'approvals'])->name('mobile.host.inbox.approvals');
        Route::post('inbox/approvals/{id}/decide', [HostInboxController::class, 'decide'])->name('mobile.host.inbox.decide');

        Route::get('guest-requests', [HostGuestRequestsController::class, 'index'])->name('mobile.host.guest-requests.index');
        Route::get('guest-requests/{id}', [HostGuestRequestsController::class, 'show'])->whereNumber('id')->name('mobile.host.guest-requests.show');
        Route::post('guest-requests/{id}/reply', [HostGuestRequestsController::class, 'reply'])->whereNumber('id')->name('mobile.host.guest-requests.reply');
        Route::post('guest-requests/{id}/resolve', [HostGuestRequestsController::class, 'resolve'])->whereNumber('id')->name('mobile.host.guest-requests.resolve');
        Route::post('guest-requests/{id}/reopen', [HostGuestRequestsController::class, 'reopen'])->whereNumber('id')->name('mobile.host.guest-requests.reopen');

        Route::get('notifications', [HostNotificationsController::class, 'index'])->name('mobile.host.notifications.index');
        Route::post('notifications/read', [HostNotificationsController::class, 'read'])->name('mobile.host.notifications.read');
        Route::post('notifications/read-all', [HostNotificationsController::class, 'readAll'])->name('mobile.host.notifications.read-all');

        Route::get('search', HostSearchController::class)->name('mobile.host.search');

        // ── Phase 5: push + notification settings (docs/host-app/PHASE-5.md) ──
        Route::put('device/push', [DeviceController::class, 'push'])->name('mobile.host.device.push');
        Route::get('device/notifications', [DeviceController::class, 'notifications'])->name('mobile.host.device.notifications');
        Route::put('device/notifications', [DeviceController::class, 'updateNotifications'])->name('mobile.host.device.notifications.update');
    });
