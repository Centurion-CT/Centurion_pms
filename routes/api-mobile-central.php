<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\LoginController;
use App\Http\Controllers\Mobile\Staff\LoginController as StaffLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API — /api/mobile/v1 on the CENTRAL domain
|--------------------------------------------------------------------------
| Cross-tenant endpoints. Just /login for now — the client hits this
| before it knows its tenant subdomain, so it has to live on the
| central host. Everything else stays on the tenant subdomain (see
| routes/api-mobile.php).
*/

Route::post('login', [LoginController::class, 'login'])->name('mobile.login');

// Staff app (housekeepers and porters): phone number + staff PIN.
Route::post('staff/login', [StaffLoginController::class, 'login'])->name('mobile.staff.login');
