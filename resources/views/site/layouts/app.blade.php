{{-- Shell for the booking site on each business's address (docs/MULTITENANCY.md,
     step 5; design: design/website/c-map). Pages set $pageClass (pg-home,
     pg-results, pg-checkout) and either @extend this or render into $slot. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? View::yieldContent('title', $business->name) }}</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap">
    @stack('head')
    @include('site.partials.styles')
    @livewireStyles
</head>
<body class="{{ $pageClass ?? View::yieldContent('page', '') }}">
    {{ $slot ?? '' }}
    @yield('content')
    @livewireScripts
    @stack('scripts')
</body>
</html>
