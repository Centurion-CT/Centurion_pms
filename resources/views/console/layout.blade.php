{{-- The platform owner's console on console.{central domain}
     (docs/MULTITENANCY.md, step 7). Self-contained: no business is bound. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? config('brand.name').' console' }}</title>
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600|hanken-grotesk:400,500,600&display=swap">
    <style>
        :root { --navy: #16324B; --navy-900: #0F2436; --ink-900: #14202C; --ink-700: #33475C; --ink-500: #5E6F80; --ink-300: #A3B1BE; --line: #D9E2EA; --fill: #EDF2F6; --paper: #F5F8FA; --blue: #336799; --blue-700: #26527D; --green: #0F6B4F; --green-50: #E2F4EC; --amber: #8F5300; --amber-50: #FFF1D9; --red: #B42318; --red-50: #FDECEA; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--paper); color: var(--ink-700); font: 400 15px/1.5 'Hanken Grotesk', system-ui, sans-serif; }
        a { color: var(--blue); }
        .bar { background: var(--navy-900); color: #fff; }
        .bar .wrap { display: flex; align-items: center; gap: 16px; height: 60px; }
        .brand { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; font-weight: 600; }
        .brand img { width: 26px; height: 26px; padding: 3px; border-radius: 6px; background: #fff; }
        .brand small { color: var(--ink-300); font-weight: 500; }
        .bar .end { margin-left: auto; display: flex; align-items: center; gap: 12px; font-size: 14px; color: var(--ink-300); }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        main { padding: 32px 0 64px; }
        h1 { font: 600 30px/1.15 'Bricolage Grotesque', system-ui, sans-serif; color: var(--ink-900); margin: 0; letter-spacing: -0.02em; }
        .lead { margin: 6px 0 0; color: var(--ink-500); }
        .btn { display: inline-flex; align-items: center; justify-content: center; height: 40px; padding: 0 16px; border: 0; border-radius: 8px; font: 600 14px 'Hanken Grotesk', sans-serif; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .btn-primary { background: var(--blue); color: #fff; } .btn-primary:hover { background: var(--blue-700); }
        .btn-ghost { background: transparent; color: inherit; box-shadow: inset 0 0 0 1px rgba(255,255,255,.25); height: 32px; font-size: 13px; }
        .btn-quiet { background: #fff; color: var(--ink-900); box-shadow: inset 0 0 0 1px var(--line); height: 32px; font-size: 13px; }
        .btn-quiet:hover { box-shadow: inset 0 0 0 1px var(--ink-500); }
        .btn-danger { color: var(--red); }
        .input { height: 44px; width: 100%; padding: 0 14px; border: 0; border-radius: 8px; box-shadow: inset 0 0 0 1px var(--line); font: 400 15px 'Hanken Grotesk', sans-serif; color: var(--ink-900); background: #fff; }
        .input:focus { outline: none; box-shadow: inset 0 0 0 2px var(--blue); }
        :focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
        .card { background: #fff; border-radius: 12px; box-shadow: inset 0 0 0 1px var(--line); }
        .error { color: var(--red); font-size: 14px; margin: 8px 0 0; }
        .meta { color: var(--ink-500); font-size: 13px; }
        .tag { display: inline-flex; align-items: center; height: 22px; padding: 0 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: var(--fill); color: var(--ink-700); white-space: nowrap; }
        .tag-ok { background: var(--green-50); color: var(--green); } .tag-warn { background: var(--amber-50); color: var(--amber); } .tag-bad { background: var(--red-50); color: var(--red); }
        [x-cloak] { display: none !important; }
        .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: var(--ink-900); color: #fff; padding: 10px 16px; border-radius: 8px; font-size: 14px; }
    </style>
</head>
<body>
    <header class="bar">
        <div class="wrap">
            <a class="brand" href="{{ route('console.businesses') }}"><img src="{{ asset(config('brand.logo')) }}" alt="">{{ config('brand.name') }} <small>console</small></a>
            @isset($admin)
                <div class="end"><span>{{ $admin }}</span></div>
            @endisset
        </div>
    </header>
    <main><div class="wrap">{{ $slot }}</div></main>
    <div x-data="{ msg: '', t: null }" x-on:notify.window="msg = $event.detail.message ?? $event.detail[0]?.message ?? ''; clearTimeout(t); t = setTimeout(() => msg = '', 3500)">
        <div class="toast" x-show="msg" x-text="msg" x-cloak role="status"></div>
    </div>
</body>
</html>
