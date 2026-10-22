<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.favicons')
    <title>{{ $tenant->name }}, Coming Soon</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <main style="text-align:center; padding: 4rem 1rem; font-family: system-ui, sans-serif;">
        <h1 data-testid="coming-soon-heading">{{ $tenant->name }}</h1>
        <p data-testid="coming-soon-message">Our booking site is coming soon, check back in a few days.</p>
        @if ($tenant->email)
            <p>In the meantime: <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a></p>
        @endif
    </main>
</body>
</html>
