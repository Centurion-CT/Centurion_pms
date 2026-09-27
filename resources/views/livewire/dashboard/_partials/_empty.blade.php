{{--
    Shared empty-state block for dashboard sections. Sits INSIDE a
    section's card, quieter than the card frame around it.

    Params:
      * $icon (string, optional) — one of the presets:
        calendar, clipboard, room, wallet, box, sparkle. Falls back
        to `calendar` if unrecognised.
      * $title (string, required) — one-line headline.
      * $sub (string, optional) — supporting sentence under the headline.
      * $ctaLabel (string, optional) — visible label for the call-to-action pill.
      * $ctaHref (string, optional) — href for the CTA (renders an <a>).
      * $ctaEvent (string, optional) — Alpine $dispatch event name
        (renders a <button>). Ignored if $ctaHref is set.
--}}
@php
    $iconMap = [
        'calendar' => '<rect x="2.5" y="3.5" width="15" height="14" rx="2"/><path d="M6 2v3M14 2v3M2.5 8h15"/>',
        'clipboard'=> '<rect x="4" y="3" width="12" height="14" rx="2"/><path d="M7 3v-.5A1.5 1.5 0 0 1 8.5 1h3A1.5 1.5 0 0 1 13 2.5V3M7 9h6M7 12h4"/>',
        'room'     => '<path d="M3 17V8l7-4 7 4v9"/><path d="M8 17v-4h4v4M13 12h.01"/>',
        'wallet'   => '<rect x="2.5" y="5.5" width="15" height="10" rx="2"/><path d="M13 10h2M2.5 8h15"/>',
        'box'      => '<path d="M3 6l7-3 7 3-7 3-7-3zM3 6v8l7 3M17 6v8l-7 3"/>',
        'sparkle'  => '<path d="M10 3v4M10 13v4M3 10h4M13 10h4M5 5l2 2M13 13l2 2M5 15l2-2M13 7l2-2"/>',
    ];
    $iconSvg = $iconMap[$icon ?? 'calendar'] ?? $iconMap['calendar'];
@endphp
<div class="lp-empty" data-testid="empty-state">
    <span class="lp-empty-icon" aria-hidden>
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
            {!! $iconSvg !!}
        </svg>
    </span>
    <div class="lp-empty-body">
        <p class="lp-empty-title">{{ $title }}</p>
        @if (! empty($sub))
            <p class="lp-empty-sub">{{ $sub }}</p>
        @endif
    </div>
    @if (! empty($ctaLabel))
        @if (! empty($ctaHref))
            <a href="{{ $ctaHref }}" @isset($ctaNavigate) wire:navigate @endisset
               class="lp-empty-cta"
               data-testid="empty-state-cta">
                {{ $ctaLabel }}
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
            </a>
        @elseif (! empty($ctaClickJs))
            <button type="button"
                    x-on:click="{{ $ctaClickJs }}"
                    class="lp-empty-cta"
                    data-testid="empty-state-cta">
                {{ $ctaLabel }}
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
            </button>
        @elseif (! empty($ctaEvent))
            @php
                // $ctaEvent can be:
                //   * a string 'event-name'
                //   * an array [ 'event-name', ['key' => 'val', ...] ]
                if (is_array($ctaEvent)) {
                    [$__ctaEventName, $__ctaEventPayload] = [$ctaEvent[0] ?? '', $ctaEvent[1] ?? []];
                } else {
                    [$__ctaEventName, $__ctaEventPayload] = [$ctaEvent, []];
                }
                $__ctaClickExpr = '$dispatch('.\Illuminate\Support\Js::from($__ctaEventName)
                    .', '.\Illuminate\Support\Js::from((object) $__ctaEventPayload).')';
            @endphp
            <button type="button"
                    x-on:click="{{ $__ctaClickExpr }}"
                    class="lp-empty-cta"
                    data-testid="empty-state-cta">
                {{ $ctaLabel }}
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
            </button>
        @endif
    @endif
</div>
