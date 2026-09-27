@props([
    // Main headline shown in the empty card. Falls through to the
    // default slot when the caller wants full markup control.
    'title' => null,
    // Optional secondary line under the title.
    'hint' => null,
    // Optional test id on the empty card for automated tests.
    'testid' => null,
])

{{-- Reusable illustrated empty state for tables and dashboards.
     Renders inside .lp-dash-card so the same shadow / padding chrome
     as other card surfaces applies. Callers can pass a primary CTA
     via the `cta` slot; falls back to a bare "No records" render
     when only $title is provided. --}}

<section {{ $attributes->merge([
        'class' => 'lp-dash-card lp-dash-empty text-center',
    ]) }}
    @if ($testid) data-testid="{{ $testid }}" @endif>

    {{-- Loose editorial mark — a hand-drawn Fraunces asterisk in
         warm ink. Feels crafted vs. a stock line-icon empty state
         while adding zero external assets. --}}
    <div class="lp-dash-empty__mark" aria-hidden="true">
        <svg width="46" height="46" viewBox="0 0 46 46" fill="none">
            <path d="M23 6v34M9 14l28 18M9 32l28-18"
                  stroke="currentColor" stroke-width="1.4"
                  stroke-linecap="round"/>
        </svg>
    </div>

    @if ($title)
        <p class="lp-dash-empty__title">{{ $title }}</p>
    @endif

    @if ($hint)
        <p class="lp-dash-empty__hint">{{ $hint }}</p>
    @endif

    {{-- Free-form slot for callers that want a longer block (e.g. a
         checklist of first-run steps). --}}
    @isset($body)
        <div class="lp-dash-empty__body">{{ $body }}</div>
    @endisset

    @isset($cta)
        <div class="lp-dash-empty__cta">{{ $cta }}</div>
    @endisset
</section>

@once
    <style>
        .lp-page .lp-dash-empty { padding: 56px 24px; }
        .lp-page .lp-dash-empty__mark {
            display: inline-flex; align-items: center; justify-content: center;
            width: 60px; height: 60px;
            margin-bottom: 18px;
            border-radius: 999px;
            background: var(--color-accent-soft);
            color: var(--color-accent-deep);
        }
        .lp-page .lp-dash-empty__title {
            font-family: var(--font-body);
            font-weight: 600;
            font-size: 16px;
            color: var(--color-ink);
            max-width: 40ch;
            margin: 0 auto;
        }
        .lp-page .lp-dash-empty__hint {
            margin: 8px auto 0;
            font-size: 13.5px;
            color: var(--color-ink-soft);
            max-width: 52ch;
        }
        .lp-page .lp-dash-empty__body { margin-top: 18px; }
        .lp-page .lp-dash-empty__cta { margin-top: 20px; display: inline-flex; gap: 10px; }
    </style>
@endonce
