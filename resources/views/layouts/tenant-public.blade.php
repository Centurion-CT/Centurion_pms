@php
    $tenant = \App\Models\Tenant::current();
    $property = $tenant?->property;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $tenant?->name ?? config('brand.name') }}</title>
    @include('partials.favicons')
    @include('partials.public.theme')
    <style>
        /* Legacy token names still read by pages outside the Harbour
           redesign (workspace / residential browse), mapped onto the
           Harbour palette so they stay visually coherent. */
        :root {
            --primary-dark: var(--blue-600);
            --primary-soft: rgba(51, 103, 153, 0.12);
            --paper: var(--mist);
            --paper-2: #E3EAF1;
            --line-soft: rgba(26, 36, 48, 0.06);
            --display-font: var(--display);
            --body-font: var(--body);
        }
        h1, h2, h3 { font-family: var(--display); color: var(--ink); line-height: 1.1; letter-spacing: -0.01em; margin: 0; font-weight: 600; }
        a { text-decoration: none; }
        /* Keep the footer at the bottom on short pages. */
        body { min-height: 100vh; display: flex; flex-direction: column; }
        .cx-main { flex: 1 0 auto; }
    </style>
    @livewireStyles
</head>
<body>
    @include('partials.public.topbar')

    <main class="cx-main">
        {{ $slot }}
    </main>

    @include('partials.public.footer')

    {{-- Global success / error modal, wire spinner and confirm dialog. --}}
    @include('partials.notify-modal')
    @include('partials._wire-spinner')
    @include('partials._confirm-modal')

    @livewireScripts
</body>
</html>
