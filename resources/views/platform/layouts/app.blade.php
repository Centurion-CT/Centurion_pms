{{-- Shell for the platform pages on the central domain (no business bound).
     Pages either @extend it (home) or are Livewire full-page components
     that render into $slot (sign-up, find my business). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? View::yieldContent('title', config('brand.name')) }}</title>
    <meta name="description" content="Bookings, check-in, housekeeping, payments and your own booking website, for serviced apartments and hotels in Nigeria.">
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600|hanken-grotesk:400,500,600&display=swap">
    @include('platform.partials.styles')
</head>
<body>
    {{ $slot ?? '' }}
    @yield('content')
</body>
</html>
