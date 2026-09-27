{{--
    Auth layout, marketing/landing palette + Fraunces display.

    Distinct from `layouts.central` (which is the operator-dashboard chrome)
    so the lp-* component layer applies without bleeding into the rest of
    the app. The body uses `.lp-page` and any auth view renders inside the
    edge-padded shell with its own card structure.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Keverna' }}</title>
    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="lp-page">
    @include('partials.maintenance-banner')

    <main>
        {{ $slot }}
    </main>
    @include('partials.whatsapp-float', ['context' => 'auth'])
    @livewireScripts
</body>
</html>
