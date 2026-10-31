<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureConsoleAdmin;
use App\Livewire\Console\Businesses;
use App\Livewire\Console\Login;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| The platform owner's console on console.{CENTRAL_DOMAIN}, only in
| TENANCY_MODE=domains (docs/MULTITENANCY.md, step 7). No business is
| bound; sign-in is an emailed code for CONSOLE_ADMINS addresses.
|
*/

Route::get('/login', Login::class)->name('console.login');

Route::middleware(EnsureConsoleAdmin::class)->group(function (): void {
    Route::get('/', Businesses::class)->name('console.businesses');
});
