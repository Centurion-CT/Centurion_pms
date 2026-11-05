{{-- Shell for the booking site on each business's address (docs/MULTITENANCY.md,
     step 5). Pages set $pageClass and either @extend this or render into $slot. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
@php($color = \App\Support\BusinessColor::forSignIn($business))
<!DOCTYPE html>
<html lang="en" @if ($color) style="--panel: {{ $color }}; --action: {{ $color }}; --on-panel: #FFFFFF;" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? View::yieldContent('title', $business->siteTitle()) }}</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    <script>document.documentElement.classList.add('js')</script>
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600|hanken-grotesk:400,500,600&display=swap">
    @stack('head')
    @include('site.partials.styles')
    @livewireStyles
</head>
<body class="site {{ $pageClass ?? View::yieldContent('page', '') }}">
    {{ $slot ?? '' }}
    @yield('content')
    @livewireScripts
    @stack('scripts')
</body>
</html>
