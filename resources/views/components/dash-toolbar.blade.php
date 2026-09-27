@props([
    // Small mono uppercase strip above the title (section label:
    // "Business" / "Operate" / "Brand" …). Optional.
    'eyebrow' => null,
    // The page title itself. Rendered in the big Fraunces display
    // treatment matching /dashboard/summary so the whole operator
    // dashboard shares one typographic voice, brand + ops together.
    // Callers that specifically want a smaller sans header can opt
    // in via `titleClass="lp-page-title--dense"`.
    'title' => null,
    'titleClass' => 'lp-display lp-page-title-display',
    // Sub-line under the title (usually a "N records" hint or a
    // one-line reminder of what the page is for). Optional.
    'subtitle' => null,
])

{{-- Reusable page toolbar. Composes:
       row 1  ->  [eyebrow / title / subtitle]   <-->   [actions slot]
       row 2  ->  [filters slot]  (full-width, below row 1)
     Callers pass a primary CTA into `actions` and filter chips + the
     `+ Add filter` menu into `filters`. Either slot may be omitted;
     the toolbar shrinks to whatever's populated. --}}

<div {{ $attributes->merge(['class' => 'lp-dash-toolbar']) }}>
    <div class="lp-dash-toolbar__top">
        <div class="lp-dash-toolbar__lead">
            @if ($eyebrow)
                <span class="lp-eyebrow-strip">{{ $eyebrow }}</span>
            @endif
            @if ($title)
                <h1 class="{{ $titleClass }} mt-3">{{ $title }}</h1>
            @endif
            @if ($subtitle)
                <p class="lp-dash-toolbar__subtitle">{{ $subtitle }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="lp-dash-toolbar__actions">
                {{ $actions }}
            </div>
        @endisset
    </div>

    @isset($filters)
        <div class="lp-dash-toolbar__filters">
            {{ $filters }}
        </div>
    @endisset
</div>

@once
    <style>
        .lp-page .lp-dash-toolbar { display: block; }
        .lp-page .lp-dash-toolbar__top {
            display: flex; flex-wrap: wrap; align-items: flex-end;
            justify-content: space-between; gap: 16px;
        }
        .lp-page .lp-dash-toolbar__lead { min-width: 0; }
        .lp-page .lp-dash-toolbar__actions {
            display: inline-flex; align-items: center; gap: 12px;
            flex-wrap: wrap;
        }
        .lp-page .lp-dash-toolbar__subtitle {
            margin-top: 8px;
            font-size: 14.5px;
            color: var(--color-ink-soft);
            max-width: 60ch;
        }
        .lp-page .lp-dash-toolbar__filters {
            margin-top: 18px;
        }
    </style>
@endonce
