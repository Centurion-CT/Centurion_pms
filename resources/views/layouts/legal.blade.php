{{-- Legal pages (approved mockup 7): site top bar + footer, page
     header, and a document card with an "On this page" list built from
     the document's own <h2> headings. Pages @extend this and fill
     `title`, `heading`, `kicker` and `content`. --}}
@php
    $tenant = \App\Models\Tenant::current();
    $property = $tenant?->property;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ $tenant?->name ?? config('brand.name') }}</title>
    <link rel="canonical" href="{{ url()->current() }}">
    @include('partials.favicons')
    @include('partials.public.theme')
    <style>
        body { min-height: 100vh; display: flex; flex-direction: column; }
        .cx-legal-main { flex: 1 0 auto; }
        .cx-legal-head { background: var(--white); border-bottom: 1px solid var(--line); }
        .cx-legal-head .cx-wrap { max-width: 1080px; padding-block: 48px; }
        .cx-legal-kicker { font-size: 14px; color: var(--ink-mute); margin: 0; }
        .cx-legal-title { font-family: var(--display); font-weight: 600; font-size: clamp(34px, 5vw, 44px); line-height: 1.08; margin: 6px 0 0; }
        .cx-legal-updated { margin: 10px 0 0; color: var(--ink-soft); }
        .cx-legal-body { max-width: 1080px; margin: 0 auto; padding: 40px 20px 72px; display: grid; gap: 40px; grid-template-columns: 1fr; align-items: start; }
        @media (min-width: 768px) { .cx-legal-body { padding: 40px 40px 72px; } }
        @media (min-width: 960px) { .cx-legal-body { grid-template-columns: 220px minmax(0, 1fr); } }
        .cx-toc { display: none; font-size: 15px; }
        @media (min-width: 960px) { .cx-toc { display: block; position: sticky; top: 96px; } }
        .cx-toc-title { font-size: 13px; font-weight: 600; color: var(--ink-mute); margin: 0 0 8px; }
        .cx-toc a { display: block; padding: 6px 0; color: var(--ink-soft); text-decoration: none; }
        .cx-toc a:hover, .cx-toc a.is-active { color: var(--blue-600); }
        .cx-toc a.is-active { font-weight: 600; }
        .cx-legal-doc { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 28px; font-size: 16px; line-height: 1.75; color: var(--ink-soft); max-width: 76ch; }
        @media (min-width: 768px) { .cx-legal-doc { padding: 40px; } }
        .cx-legal-doc h2 { font-family: var(--display); font-weight: 600; font-size: 24px; color: var(--ink); margin: 32px 0 10px; scroll-margin-top: 96px; }
        .cx-legal-doc h2:first-child { margin-top: 0; }
        .cx-legal-doc h3 { font-size: 18px; color: var(--ink); margin: 22px 0 6px; }
        .cx-legal-doc p { margin: 0 0 14px; }
        .cx-legal-doc ul, .cx-legal-doc ol { margin: 8px 0 16px; padding-left: 22px; }
        .cx-legal-doc li { margin-bottom: 6px; }
        .cx-legal-doc strong { color: var(--ink); }
        .cx-legal-doc a { color: var(--blue-600); text-decoration: underline; text-underline-offset: 3px; }
        .cx-legal-doc table { width: 100%; border-collapse: collapse; margin: 12px 0 18px; font-size: 15px; }
        .cx-legal-doc th, .cx-legal-doc td { text-align: left; padding: 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
    </style>
</head>
<body>
    @include('partials.public.topbar')

    <main class="cx-legal-main">
        <header class="cx-legal-head">
            <div class="cx-wrap">
                <p class="cx-legal-kicker">@yield('kicker', 'Legal')</p>
                <h1 class="cx-legal-title">@yield('heading')</h1>
                <p class="cx-legal-updated">Last updated {{ date('F Y') }}</p>
            </div>
        </header>

        <div class="cx-legal-body">
            <nav class="cx-toc" aria-label="On this page" data-legal-toc hidden>
                <p class="cx-toc-title">On this page</p>
            </nav>
            <article class="cx-legal-doc" data-legal-doc>
                @yield('content')
            </article>
        </div>
    </main>

    @include('partials.public.footer')

    <script>
        (function () {
            var doc = document.querySelector('[data-legal-doc]');
            var toc = document.querySelector('[data-legal-toc]');
            if (!doc || !toc) return;
            var heads = doc.querySelectorAll('h2');
            if (heads.length < 2) return;
            var links = [];
            heads.forEach(function (h, i) {
                if (!h.id) h.id = 'section-' + (i + 1);
                var a = document.createElement('a');
                a.href = '#' + h.id;
                a.textContent = h.textContent.trim();
                toc.appendChild(a);
                links.push(a);
            });
            toc.hidden = false;
            // Highlight the section currently near the top of the viewport.
            if ('IntersectionObserver' in window) {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (e) {
                        if (!e.isIntersecting) return;
                        links.forEach(function (l) { l.classList.toggle('is-active', l.getAttribute('href') === '#' + e.target.id); });
                    });
                }, { rootMargin: '-96px 0px -70% 0px' });
                heads.forEach(function (h) { io.observe(h); });
            }
        })();
    </script>

    @include('partials.cookie-consent')
</body>
</html>
