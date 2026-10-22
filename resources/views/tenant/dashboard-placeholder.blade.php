<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.favicons')
    <meta charset="UTF-8">
    <title>Dashboard, {{ \App\Models\Tenant::current()?->name }}</title>
</head>
<body>
    <main>
        <h1 data-testid="dashboard-heading">Welcome, {{ auth()->user()?->name }}</h1>
        <p data-testid="property-name">{{ \App\Models\Tenant::current()?->property?->name }}</p>
        <p>Your booking engine is ready to be configured. Add rooms, set pricing, and mark your landing page live when you're ready.</p>
    </main>
</body>
</html>
