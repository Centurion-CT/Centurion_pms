<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Install branding
|--------------------------------------------------------------------------
|
| The software's brand ("Centurion"), shown on the platform pages, in
| "runs on" lines, the favicon, and where a business has no logo of its
| own. Each business's name, title, logo and contact details live on the
| business (Settings → Business; docs/MULTITENANCY.md, step 6). Colours themselves live in CSS:
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
    // Staff sidebar layout for operators who haven't picked one: 'list'
    // (rows with tinted icons, mockup 3A) or 'tiles' (the app-icon grid).
    'sidebar_default' => env('BRAND_SIDEBAR', 'list'),
];
