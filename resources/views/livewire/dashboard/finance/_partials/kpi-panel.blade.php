{{--
    Server-side detail panel for a Finance KPI tile. Loaded on
    demand (see Finance\Index::openKpi) so the wire trip is only
    paid when the operator clicks. A centered spinner sits above
    the modal while the trip resolves — visual feedback so the
    click doesn't read as a dead button.
--}}

{{-- Loader — teleported to body, dead-centered. Spinner sits inside
     a small white pill so it reads clearly on any page background.
     `wire:loading.flex` (NOT bare `wire:loading`) is deliberate:
     bare wire:loading toggles the element's display with an
     inline `display: inline`, which our inline `display: flex`
     used to override — leaving the pill visible when nothing
     was loading. The .flex modifier tells Livewire to swap
     display: flex ↔ none per state. --}}
<template x-teleport="body">
<div wire:loading.flex wire:target="openKpi"
     data-testid="finance-kpi-loader"
     style="position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            z-index: 90; pointer-events: none;
            background: #fff;
            border-radius: 999px;
            padding: 10px 14px;
            box-shadow: 0 8px 24px rgba(20, 17, 12, 0.18);
            align-items: center; justify-content: center;">
    <svg width="28" height="28" viewBox="0 0 28 28" fill="none"
         style="animation: finance-kpi-spin 0.9s linear infinite; display: block;">
        <circle cx="14" cy="14" r="11" stroke="rgba(20,17,12,0.14)" stroke-width="2.5"></circle>
        <path d="M14 3 a11 11 0 0 1 11 11"
              stroke="var(--color-accent-deep)"
              stroke-width="2.5" stroke-linecap="round" fill="none"></path>
    </svg>
    <style>@@keyframes finance-kpi-spin { to { transform: rotate(360deg); } }</style>
</div>
</template>

@if ($kpiPanel !== null)
    {{-- Client-side visibility: Alpine hides the drawer the
         instant the operator clicks close / backdrop / Escape, then
         `$wire.closeKpi()` fires in the background to sync server
         state. Was doing the round-trip inline, which read as a 400-
         800ms freeze while Livewire replayed the whole page. --}}
    <div x-data="{ visible: true, dismiss() { this.visible = false; this.$wire.closeKpi(); } }"
         x-show="visible"
         x-cloak
         data-testid="finance-kpi-panel-backdrop"
         @click.self="dismiss()"
         @keydown.escape.window="dismiss()"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 80;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);">
        <aside data-testid="finance-kpi-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(540px, 96vw);
                      background: var(--color-card);
                      box-shadow: -18px 0 40px -18px rgba(20, 17, 12, 0.28);
                      display: flex; flex-direction: column;">
            <div style="display: flex; align-items: flex-start; justify-content: space-between;
                        padding: 16px 20px 12px;
                        border-bottom: 1px solid var(--color-line-soft);">
                <div>
                    <p class="lp-mono text-[10.5px]"
                       style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                        {{ $kpiPanel['subtitle'] }}
                    </p>
                    <h3 class="lp-display"
                        style="font-size: 18px; font-weight: 600; margin-top: 1px; line-height: 1.15;">
                        {{ $kpiPanel['title'] }}
                    </h3>
                </div>
                <button type="button" @click="dismiss()"
                        aria-label="Close"
                        style="background: transparent; border: 0; cursor: pointer;
                               font-size: 22px; color: var(--color-ink-mute);
                               padding: 2px 4px; line-height: 1;">×</button>
            </div>

            <div style="flex: 1 1 auto; overflow-y: auto; padding: 14px 20px 16px;
                        display: flex; flex-direction: column; gap: 14px; font-size: 12.5px;">
                {{-- KPI mini-strip --}}
                <div style="display: grid; grid-template-columns: repeat({{ max(1, count($kpiPanel['kpis'])) }}, minmax(0, 1fr)); gap: 8px;">
                    @foreach ($kpiPanel['kpis'] as $k)
                        <div style="background: var(--color-card-deep); border-radius: 10px;
                                    padding: 10px 12px; display: flex; flex-direction: column; gap: 2px;">
                            <span style="font-size: 10.5px; letter-spacing: 0.12em;
                                         text-transform: uppercase; color: var(--color-ink-mute);">
                                {{ $k['label'] }}
                            </span>
                            <span class="lp-num" style="font-size: 16px; color: var(--color-ink); font-weight: 600;">
                                {{ $k['value'] }}
                            </span>
                        </div>
                    @endforeach
                </div>

                {{-- Rows — branches on kind: bookings vs payments --}}
                @if (empty($kpiPanel['rows']))
                    <p style="font-size: 12px; color: var(--color-ink-mute); margin: 0;">
                        Nothing in this window.
                    </p>
                @elseif ($kpiPanel['kind'] === 'payments')
                    <div>
                        <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                            Payments
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 0;
                                   display: flex; flex-direction: column; gap: 0;">
                            @foreach ($kpiPanel['rows'] as $p)
                                <li style="display: grid;
                                           grid-template-columns: 1fr auto;
                                           gap: 4px 10px; padding: 8px 0;
                                           border-bottom: 1px solid var(--color-line-soft);">
                                    <div style="min-width: 0;">
                                        <span style="color: var(--color-ink); font-weight: 500; font-size: 13px;">
                                            {{ $p['guest'] }}
                                        </span>
                                        @if ($p['booking_ref'])
                                            <span class="lp-mono text-[11px]"
                                                  style="color: var(--color-ink-mute); margin-left: 6px;">
                                                {{ $p['booking_ref'] }}
                                            </span>
                                        @endif
                                    </div>
                                    <span class="lp-mono"
                                          style="font-size: 12.5px; color: var(--color-ink); font-weight: 600;
                                                 white-space: nowrap;">
                                        &#8358;{{ number_format($p['amount']) }}
                                    </span>
                                    <div style="font-size: 11.5px; color: var(--color-ink-soft); grid-column: 1;">
                                        {{ $p['paid_at'] }}
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ $p['method'] }}
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        <span class="lp-mono"
                                              style="font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase;
                                                     color: var(--color-ink-mute); font-weight: 600;">
                                            {{ $p['type'] }}
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    {{-- kind = bookings --}}
                    <div>
                        <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                            Bookings
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 0;
                                   display: flex; flex-direction: column; gap: 0;">
                            @foreach ($kpiPanel['rows'] as $b)
                                <li style="display: grid;
                                           grid-template-columns: 1fr auto;
                                           gap: 4px 10px; padding: 8px 0;
                                           border-bottom: 1px solid var(--color-line-soft);">
                                    <div style="min-width: 0;">
                                        <a href="{{ $b['url'] }}" wire:navigate
                                           style="color: var(--color-ink); font-weight: 500; font-size: 13px;
                                                  text-decoration: none;">
                                            {{ $b['guest'] }}
                                        </a>
                                        <span class="lp-mono text-[11px]"
                                              style="color: var(--color-ink-mute); margin-left: 6px;">
                                            {{ $b['ref'] }}
                                        </span>
                                    </div>
                                    <span class="lp-mono"
                                          style="font-size: 12.5px; color: var(--color-ink); font-weight: 600;
                                                 white-space: nowrap;">
                                        &#8358;{{ number_format($b['amount']) }}
                                    </span>
                                    <div style="font-size: 11.5px; color: var(--color-ink-soft); grid-column: 1;">
                                        {{ $b['room'] }}
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ $b['checkin'] }} → {{ $b['checkout'] }}
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ $b['nights'] }} night{{ $b['nights'] === 1 ? '' : 's' }}
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div style="padding: 12px 20px;
                        border-top: 1px solid var(--color-line-soft);
                        display: flex; justify-content: flex-end;">
                <button type="button" @click="dismiss()"
                        data-testid="finance-kpi-panel-close"
                        style="background: transparent; color: var(--color-ink);
                               border: 1px solid var(--color-line);
                               padding: 6px 14px; border-radius: 999px;
                               font-family: inherit; font-size: 12px; font-weight: 500;
                               cursor: pointer;">
                    Close
                </button>
            </div>
        </aside>
    </div>
@endif
