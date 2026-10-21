<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Install branding
|--------------------------------------------------------------------------
|
| Product-level brand for this install, shown in the auth chrome, sidebar,
| browser tab and favicon. Distinct from the tenant (hotel) name, which
| still headlines each page. Colours themselves live in CSS:
| --color-accent* in resources/css/app.css and the --accent* tokens at the
| top of the auth views.
|
| `url` is optional: when set, "Powered by <name>" links to it.
*/
return [
    'name' => env('BRAND_NAME', 'Centurion'),
    'url' => env('BRAND_URL'),
    'logo' => 'brand/logo.png',
];
