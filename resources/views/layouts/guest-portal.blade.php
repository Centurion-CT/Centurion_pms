@php
    // Minimal layout for the in-room guest self-service portal. Distinct
    // from `tenant-public` because that layout ships the marketing site
    // nav (Rooms / About / Reviews / Contact + Book a stay), which is
    // wrong chrome for a signed-in guest inside their room. Portal
    // renders its own sticky app-bar; no site-wide nav or footer here.
    $tenant   = \App\Models\Tenant::current();
    $property = $tenant?->property;
    $primary  = $property?->primary_color ?? '#14171A';

    // Per-tenant font theme, same whitelist tenant-public uses so the
    // guest portal picks up the operator's brand fonts.
    $fontMap = [
        'inter_playfair'          => ['display' => 'Playfair Display', 'body' => 'Inter'],
        'inter_inter'             => ['display' => 'Inter',            'body' => 'Inter'],
        'lora_lora'               => ['display' => 'Lora',             'body' => 'Lora'],
        'poppins_poppins'         => ['display' => 'Poppins',          'body' => 'Poppins'],
        'merriweather_montserrat' => ['display' => 'Merriweather',     'body' => 'Montserrat'],
    ];
    $fonts = $fontMap[$property?->font_theme ?? 'inter_playfair'] ?? $fontMap['inter_playfair'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Two theme-color variants so mobile browsers repaint the status
         bar to match the portal's actual palette in both schemes. On
         iOS the OS reads the media-matching value; the operator brand
         primary still wins for the light default. --}}
    <meta name="theme-color" content="{{ $primary }}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#12100D" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ $tenant?->name ?? 'Guest portal' }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $tenant?->name ?? 'Guest portal' }}</title>
    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $fonts['display']) }}:wght@500;600;700&family={{ str_replace(' ', '+', $fonts['body']) }}:wght@400;500;600&display=swap">

    <style>
        :root {
            --display-font: '{{ $fonts['display'] }}', Georgia, serif;
            --body-font:    '{{ $fonts['body'] }}', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            font-family: var(--body-font);
            background: #FCFAF5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        a { color: inherit; text-decoration: none; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    {{ $slot }}

    {{-- Global success / error modal - reused across surfaces. --}}
    @include('partials.notify-modal')

    {{-- Centre spinner for wire:click round-trips. Debounced so
         instant responses don't flash. --}}
    @include('partials._wire-spinner')
    @include('partials._confirm-modal')

    @include('partials.gp-otp-script')
    @livewireScripts

    {{-- Geo-fence coord capture. RoomPortal dispatches
         `portal-request-coords` when a verified guest lands on the
         home step and the property has a fence configured. We ask
         the browser for a fix and fire it back as `portal-coords`
         (success) or `portal-coords-denied` (declined / unavailable
         / timeout). Score computation + queuing happens server-side. --}}
    <script>
        document.addEventListener('livewire:init', function () {
            Livewire.on('portal-request-coords', function () {
                if (! ('geolocation' in navigator)) {
                    Livewire.dispatch('portal-coords-denied');
                    return;
                }
                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        Livewire.dispatch('portal-coords', {
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude,
                        });
                    },
                    function () {
                        // Permission denied, timeout, or position
                        // unavailable - all treated the same. Under a
                        // strict min-score policy this pushes every
                        // sensitive action to the approval queue.
                        Livewire.dispatch('portal-coords-denied');
                    },
                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
                );
            });
        });
    </script>
</body>
</html>
