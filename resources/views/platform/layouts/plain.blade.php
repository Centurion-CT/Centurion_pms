{{-- Self-contained shell for platform pages that render with no business
     bound (unknown address, paused business). Tenant layouts assume
     Tenant::current() is set, so this one includes nothing tenant-scoped.
     Look: design/platform/superdesign/08-unavailable-paused.png. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('brand.name') }}</title>
    @include('partials.favicons')
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600|hanken-grotesk:400,600&display=swap">
    <style>
        :root { --navy: #16324B; --ink-900: #14202C; --ink-500: #5E6F80; --ink-300: #A3B1BE; --line: #D9E2EA; --fill: #EDF2F6; --paper: #F5F8FA; --blue: #336799; --blue-700: #26527D; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: var(--paper); color: var(--ink-500); font: 400 16px/1.55 'Hanken Grotesk', system-ui, sans-serif; }
        main { flex: 1; display: grid; place-items: center; padding: 48px 16px; }
        .box { width: 100%; max-width: 560px; }
        .bar { display: flex; align-items: center; gap: 12px; height: 40px; padding: 0 12px; border-radius: 12px; background: var(--fill); margin-bottom: 40px; }
        .bar i { width: 10px; height: 10px; border-radius: 50%; background: var(--ink-300); opacity: .6; }
        .bar span { flex: 1; min-width: 0; height: 26px; display: flex; align-items: center; padding: 0 12px; border-radius: 6px; background: #fff; font-size: 13px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .bar b { color: var(--ink-900); font-weight: 600; }
        h1 { margin: 0 0 12px; font: 600 clamp(28px, 5vw, 40px)/1.1 'Bricolage Grotesque', system-ui, sans-serif; letter-spacing: -0.02em; color: var(--ink-900); }
        p { margin: 0; }
        .lead { font-size: 18px; }
        .lead b { color: var(--ink-900); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; }
        .btn { display: inline-flex; align-items: center; height: 44px; padding: 0 20px; border-radius: 8px; font-weight: 600; text-decoration: none; }
        .btn-primary { background: var(--blue); color: #fff; }
        .btn-primary:hover { background: var(--blue-700); }
        .btn-secondary { background: #fff; color: var(--ink-900); box-shadow: inset 0 0 0 1px var(--line); }
        .card { margin-top: 32px; padding: 24px; border-radius: 12px; background: #fff; box-shadow: inset 0 0 0 1px var(--line); display: grid; gap: 8px; }
        .card a { color: var(--ink-900); font-weight: 600; text-decoration: none; }
        .note { margin-top: 24px; font-size: 14px; }
        footer { display: flex; justify-content: center; align-items: center; gap: 8px; padding: 24px; font-size: 13px; }
        footer a { display: inline-flex; align-items: center; gap: 8px; color: var(--ink-900); font-weight: 600; text-decoration: none; }
        footer img { width: 24px; height: 24px; padding: 3px; border-radius: 6px; background: #fff; box-shadow: inset 0 0 0 1px var(--line); }
    </style>
</head>
<body>
    <main><div class="box">@yield('content')</div></main>
    <footer>Runs on <a href="https://{{ config('keverna.central_domain') }}"><img src="{{ asset(config('brand.logo')) }}" alt="">{{ config('brand.name') }}</a></footer>
</body>
</html>
