<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureConsoleAdmin;
use App\Livewire\Console\AcceptInvite;
use App\Livewire\Console\Businesses;
use App\Livewire\Console\Login;
use App\Livewire\Console\Setup;
use App\Livewire\Console\Users;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| The platform owner's console on console.{CENTRAL_DOMAIN}, only in
| TENANCY_MODE=domains (docs/MULTITENANCY.md, step 7). No business is
| bound. /setup creates the first owner with CONSOLE_SETUP_KEY; after
| that, sign-in is a password plus an emailed code, and owners manage
| the other console users.
|
*/

Route::get('/setup', Setup::class)->name('console.setup');
Route::get('/login', Login::class)->name('console.login');
Route::get('/invite/{token}', AcceptInvite::class)->where('token', '[A-Za-z0-9]{48}')->name('console.invite');

Route::post('/logout', function (): RedirectResponse {
    session()->forget(EnsureConsoleAdmin::SESSION_KEY);
    session()->regenerate();

    return redirect()->route('console.login');
})->name('console.logout');

Route::middleware(EnsureConsoleAdmin::class)->group(function (): void {
    Route::get('/', Businesses::class)->name('console.businesses');
});

Route::middleware(EnsureConsoleAdmin::class.':owner')->group(function (): void {
    Route::get('/users', Users::class)->name('console.users');
});
