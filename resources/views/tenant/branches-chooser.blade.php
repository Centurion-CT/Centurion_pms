<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.favicons')
    <title>{{ $tenant->name }} · Choose a location</title>
    <meta name="description" content="{{ $tenant->name }} has {{ $branches->count() }} locations. Pick one to see rooms + book a stay.">
    <link rel="canonical" href="https://{{ $tenant->primaryHost() }}/">

    <style>
        :root {
            --bg: #FAF7F2;
            --card: #ffffff;
            --paper: #F2EEE6;
            --ink: #14171A;
            --ink-soft: #3D434A;
            --ink-mute: #7A7F87;
            --line: #ECE6D8;
            --primary: #14171A;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, sans-serif; }
        .bc-wrap { max-width: 1120px; margin: 0 auto; padding: 3rem 1.25rem 5rem; }
        .bc-head { text-align: center; padding: 2rem 0 2.5rem; }
        .bc-eyebrow {
            display: inline-block;
            font-size: 10.5px; letter-spacing: 0.22em; text-transform: uppercase;
            color: var(--ink-mute); font-weight: 600;
        }
        .bc-title {
            margin: 0.75rem 0 0.5rem;
            font-size: clamp(30px, 4.5vw, 46px); font-weight: 600; line-height: 1.1;
        }
        .bc-lede {
            margin: 0.5rem auto 0; max-width: 540px;
            font-size: 15.5px; line-height: 1.55; color: var(--ink-soft);
        }
        .bc-grid {
            display: grid; gap: 1.5rem;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            margin-top: 2rem;
        }
        .bc-card {
            display: block;
            padding: 0;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            overflow: hidden;
            text-decoration: none;
            color: var(--ink);
            transition: box-shadow 200ms, transform 200ms;
        }
        .bc-card:hover { box-shadow: 0 12px 34px rgba(20,17,12,0.09); transform: translateY(-2px); }
        .bc-card-photo {
            aspect-ratio: 16 / 10;
            background: var(--paper) center/cover no-repeat;
            border-bottom: 1px solid var(--line);
        }
        .bc-card-body { padding: 1.15rem 1.25rem 1.35rem; }
        .bc-card-name { margin: 0 0 4px; font-size: 20px; font-weight: 600; }
        .bc-card-location { font-size: 13px; color: var(--ink-mute); }
        .bc-card-cta {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 12px;
            font-size: 12.5px; letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--primary); font-weight: 600;
        }
        .bc-foot {
            margin-top: 3rem; padding-top: 1.5rem;
            border-top: 1px solid var(--line);
            font-size: 12.5px; color: var(--ink-mute);
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem;
        }
        .bc-foot a { text-decoration: underline; text-underline-offset: 3px; color: var(--ink-soft); }
    </style>
</head>
<body>
    <main class="bc-wrap" data-testid="branches-chooser">
        <div class="bc-head">
            <span class="bc-eyebrow">{{ $tenant->name }}</span>
            <h1 class="bc-title">Choose a location</h1>
            <p class="bc-lede">
                We have {{ $branches->count() }} {{ $branches->count() === 1 ? 'branch' : 'branches' }}.
                Pick the one closest to where you'll be staying.
            </p>
        </div>

        <section class="bc-grid">
            @foreach ($branches as $branch)
                @php
                    $heroUrl = $branch->getMedia('gallery')->first()?->getUrl('medium')
                        ?? $branch->getFirstMediaUrl('logo');
                    $location = trim(collect([$branch->city, $branch->state])->filter()->implode(', '));
                @endphp
                <a href="{{ route('tenant.branch.show', ['property' => $branch->slug]) }}"
                   class="bc-card"
                   data-testid="branch-card-{{ $branch->slug }}">
                    <div class="bc-card-photo"
                         @if ($heroUrl) style="background-image: url('{{ $heroUrl }}');" @endif>
                    </div>
                    <div class="bc-card-body">
                        <h2 class="bc-card-name">{{ $branch->name }}</h2>
                        @if ($location !== '')
                            <p class="bc-card-location">{{ $location }}</p>
                        @endif
                        <span class="bc-card-cta">
                            See rooms
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6h6M6.5 3L9 6 6.5 9"/>
                            </svg>
                        </span>
                    </div>
                </a>
            @endforeach
        </section>

        <footer class="bc-foot">
            <span>© {{ date('Y') }} {{ $tenant->name }}</span>
            <span>Powered by <a href="https://keverna.com">Keverna</a></span>
        </footer>
    </main>
</body>
</html>
