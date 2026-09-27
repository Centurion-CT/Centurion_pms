<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\BookingsController;
use App\Http\Controllers\Mobile\InspectionsController;
use App\Http\Controllers\Mobile\InventoryController;
use App\Http\Controllers\Mobile\ManagerController;
use App\Http\Controllers\Mobile\PairController;
use App\Http\Controllers\Mobile\PosController;
use App\Http\Controllers\Mobile\ReservationsController;
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
