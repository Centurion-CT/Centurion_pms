<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\AddressCheckController;
use App\Http\Controllers\Platform\OpenDashboardController;
use App\Http\Middleware\EnsureSignUpOpen;
use App\Livewire\Platform\FindBusiness;
use App\Livewire\Platform\SignUp;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Routes
|--------------------------------------------------------------------------
|
| Served on the central domain (centurionpms.com) when TENANCY_MODE=domains.
| No business is bound here (docs/MULTITENANCY.md, step 3).
|
*/

Route::view('/', 'platform.home')->name('platform.home');

Route::get('/address-check', AddressCheckController::class)
    ->middleware('throttle:60,1')
    ->name('platform.address-check');

Route::get('/signup', SignUp::class)
    ->middleware(EnsureSignUpOpen::class)
    ->name('platform.signup');
Route::get('/signup/open', OpenDashboardController::class)
    ->middleware('throttle:20,1')
    ->name('platform.signup.open');

Route::get('/login', FindBusiness::class)->name('platform.login');
