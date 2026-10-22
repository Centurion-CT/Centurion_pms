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
| `sidebar_default` is the staff sidebar layout before an operator picks.
*/
return [
    'name' => env('BRAND_NAME', 'Centurion'),
    'url' => env('BRAND_URL'),
    'logo' => 'brand/logo.png',
    // Guest-facing site title: the landing page's browser tab and link
    // previews. Separate from `name`, which signs the software itself.
    'site_title' => env('BRAND_SITE_TITLE', 'Centurion Apartments'),
    // Staff sidebar layout for operators who haven't picked one: 'list'
    // (rows with tinted icons, mockup 3A) or 'tiles' (the app-icon grid).
    'sidebar_default' => env('BRAND_SIDEBAR', 'list'),
];
