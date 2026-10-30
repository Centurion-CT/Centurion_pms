<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Routes
|--------------------------------------------------------------------------
|
| Served on the central domain (centurionpms.com and www.) when
| TENANCY_MODE=domains. No business is bound here. Step 3 of
| docs/MULTITENANCY.md adds the home page, sign-up and find-my-business
| login; until then the home page is a placeholder.
|
*/

Route::get('/', fn () => view('platform.home-placeholder'))->name('platform.home');
