<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @include('partials.favicons')
    <title>{{ $tenant->name }}, Temporarily unavailable</title>
</head>
<body>
    <main>
        <h1 data-testid="locked-heading">{{ $tenant->name }} is temporarily unavailable</h1>
        <p data-testid="locked-message">
            This booking site is paused. Please check back soon, or contact the
            property directly at <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a>.
        </p>
    </main>
</body>
</html>
