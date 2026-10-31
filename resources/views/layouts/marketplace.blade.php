{{--
    Shared HTML shell for the Keverna Stays marketplace pages
    (checkout, confirmation, error states). Kept minimal - the
    /stays index + /stays/{slug} detail pages inline their own
    full HTML because their CSS is bespoke and different enough
    to not benefit from a wrapper yet.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Models\Tenant::current()?->siteTitle() ?? config('brand.name') }}</title>
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { margin: 0; font-family: 'Inter', sans-serif; color: #222; background: #fff; }
        a { color: inherit; text-decoration: none; }
        *:focus-visible { outline: 2px solid #2E5788; outline-offset: 2px; border-radius: 4px; }
    </style>

    @livewireStyles
</head>
<body>
    {{ $slot ?? '' }}
    {{ $__env->yieldContent('content') }}
    @livewireScripts
</body>
</html>
