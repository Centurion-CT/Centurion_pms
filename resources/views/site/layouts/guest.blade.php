{{-- Guest pages with their own forms and modals: the booking site's header
     and footer around the shared public theme. The guest's own booking
     (/guest/booking/{ref}) keeps the quieter header; $browsing gives the
     pages guests shop from (rooms, units, workspaces) the site's menu. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
@php($color = \App\Support\BusinessColor::forSignIn($business))
<!DOCTYPE html>
<html lang="en" @if ($color) style="--panel: {{ $color }}; --action: {{ $color }}; --on-panel: #FFFFFF;" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $business->name }}</title>
    @include('partials.favicons')
    @include('partials.public.theme')
    @include('site.partials.styles')
    <style>
        :root {
            --navy: var(--panel); --navy-deep: var(--panel-deep); --blue: var(--action); --blue-600: var(--action-hover); --blue-50: var(--tint);
            --primary: var(--action); --primary-dark: var(--action-hover); --primary-soft: var(--tint);
            --paper: #FFFFFF; --paper-2: var(--c-band); --line-soft: var(--c-rule);
            --display-font: var(--f-display); --body-font: var(--f-body);
        }
        .cx-main { flex: 1 0 auto; }
        :where(.cx-main) h1, :where(.cx-main) h2, :where(.cx-main) h3 { font-family: var(--f-display); line-height: 1.1; letter-spacing: -0.01em; font-weight: 600; }
        :where(.cx-main) a { color: inherit; text-decoration: none; }
    </style>
    @livewireStyles
</head>
<body class="site pg-guest">
    @include('site.partials.header', ['nav' => $browsing ?? false, 'help' => ! ($browsing ?? false)])

    <main class="cx-main">
        {{ $slot }}
    </main>

    @include('site.partials.footer')

    {{-- Global success / error modal, wire spinner and confirm dialog. --}}
    @include('partials.notify-modal')
    @include('partials._wire-spinner')
    @include('partials._confirm-modal')

    @livewireScripts
</body>
</html>
