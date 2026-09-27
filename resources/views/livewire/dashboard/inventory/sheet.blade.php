<div class="lp-dash" wire:poll.15s>

    <div>
        <span class="lp-eyebrow-strip">Inventory · {{ $outlet->name }}</span>
        <div class="flex flex-wrap items-start justify-between gap-3 mt-3">
            <div>
                <h1 class="lp-display text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Daily sheet
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    {{ $outlet->name }}. Dates going down, products across the top.
                    <span style="color: var(--color-ink-mute);">Auto-refreshes as POS sales come in.</span>
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('tenant.inventory.outlets.show', $outlet) }}" wire:navigate class="lp-btn">
                    &larr; Back to outlet
                </a>
                <button type="button" wire:click="extendWindow(7)"
                        class="lp-btn" data-testid="outlet-sheet-more-7"
                        @if ($daysBack >= 60) disabled @endif>
                    + Show 7 more days
                </button>
            </div>
        </div>
    </div>

    @if ($products->isEmpty())
        <section class="lp-dash-card" data-testid="outlet-sheet-empty"
                 style="padding: 32px 24px; text-align: center;">
            <h2 class="text-[17px]" style="color: var(--color-ink); font-weight: 500;">
                No stock-tracked products at this outlet yet.
            </h2>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                Add a product from the outlet page first, then come back to see the daily book.
            </p>
        </section>
    @else
        <section class="lp-dash-card" data-testid="outlet-sheet"
                 style="padding: 0; overflow: hidden;">
            <div style="overflow-x: auto; max-height: calc(100vh - 260px); overflow-y: auto;">
                <table class="lp-sheet-matrix" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                    <thead>
                        {{-- Row 1: product name spans 5 sub-columns each --}}
                        <tr>
                            <th class="lp-sheet-corner"
                                style="position: sticky; left: 0; top: 0; z-index: 30;
                                       background: var(--color-card); border-bottom: 1px solid var(--color-line);
                                       min-width: 100px; padding: 10px 12px; text-align: left;
                                       font-size: 11.5px; font-weight: 600;
                                       color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.04em;">
                                Date
                            </th>
                            @foreach ($products as $p)
                                <th colspan="5"
                                    style="position: sticky; top: 0; z-index: 20;
                                           background: var(--color-card); border-bottom: 1px solid var(--color-line);
                                           border-left: 2px solid var(--color-line);
                                           padding: 10px 12px; text-align: center;
                                           font-family: 'Fraunces', ui-serif, Georgia, serif;
                                           font-size: 13.5px; font-weight: 600; color: var(--color-ink);
                                           white-space: nowrap;">
                                    {{ $p->name }}
                                    @if ($p->sku)
                                        <span style="font-family: inherit; font-weight: 400; font-size: 11px; color: var(--color-ink-mute);">
                                            &middot; {{ $p->sku }}
                                        </span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                        {{-- Row 2: sub-column headers Opening / New / Total / Sold / Closing --}}
                        <tr>
                            <th style="position: sticky; left: 0; top: 39px; z-index: 30;
                                       background: var(--color-card); border-bottom: 1px solid var(--color-line);
                                       padding: 6px 12px;"></th>
                            @foreach ($products as $p)
                                @foreach (['Opening', 'New', 'Total', 'Sold', 'Closing'] as $sub)
                                    <th style="position: sticky; top: 39px; z-index: 20;
                                               background: var(--color-card); border-bottom: 1px solid var(--color-line);
                                               @if ($loop->first) border-left: 2px solid var(--color-line); @endif
                                               padding: 6px 8px; text-align: center; min-width: 60px;
                                               font-size: 10.5px; font-weight: 600; color: var(--color-ink-mute);
                                               text-transform: uppercase; letter-spacing: 0.05em;">
                                        {{ $sub }}
                                    </th>
                                @endforeach
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dates as $day)
                            @php $dateKey = $day->toDateString(); $isToday = $dateKey === $today; @endphp
                            <tr @if ($isToday) style="background: #FFF8E7;" @endif
                                data-testid="outlet-sheet-row-{{ $dateKey }}">
                                <th scope="row"
                                    style="position: sticky; left: 0; z-index: 10;
                                           background: {{ $isToday ? '#FFF8E7' : 'var(--color-card)' }};
                                           border-bottom: 1px solid var(--color-line-soft);
                                           padding: 10px 12px; text-align: left;
                                           font-size: 12px; font-weight: 500; color: var(--color-ink);
                                           white-space: nowrap;">
                                    <div>{{ $day->format('d/m/y') }}</div>
                                    <div style="font-size: 10.5px; color: var(--color-ink-mute); font-weight: 400;">
                                        {{ $day->format('D') }}
                                    </div>
                                </th>
                                @foreach ($products as $p)
                                    @php
                                        $cell = $cells[$dateKey][$p->id] ?? null;
                                        $draftKey = $dateKey.':'.$p->id;
                                    @endphp
                                    <td style="border-left: 2px solid var(--color-line);
                                               border-bottom: 1px solid var(--color-line-soft);
                                               padding: 6px 6px; text-align: center; font-size: 13px;
                                               color: var(--color-ink);">
                                        {{ $cell['opening'] ?? 0 }}
                                    </td>
                                    <td style="border-bottom: 1px solid var(--color-line-soft);
                                               padding: 6px 6px; text-align: center; font-size: 13px;
                                               color: {{ ($cell['new'] ?? 0) > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                        {{ ($cell['new'] ?? 0) > 0 ? $cell['new'] : '-' }}
                                    </td>
                                    <td style="border-bottom: 1px solid var(--color-line-soft);
                                               padding: 6px 6px; text-align: center; font-size: 13px;
                                               color: var(--color-ink); font-weight: 500;">
                                        {{ $cell['total'] ?? 0 }}
                                    </td>
                                    <td style="border-bottom: 1px solid var(--color-line-soft);
                                               padding: 6px 6px; text-align: center; font-size: 13px;
                                               color: {{ ($cell['sold'] ?? 0) > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                        {{ ($cell['sold'] ?? 0) > 0 ? $cell['sold'] : '-' }}
                                    </td>
                                    <td style="border-bottom: 1px solid var(--color-line-soft);
                                               padding: 6px 6px; text-align: center; font-size: 13px;
                                               color: var(--color-ink); font-weight: 600;">
                                        {{ number_format($cell['closing'] ?? 0) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 10px 16px; font-size: 11.5px; color: var(--color-ink-mute);
                        border-top: 1px solid var(--color-line);">
                Closing = Opening + New &minus; Sold, derived from the ledger.
                Page auto-refreshes every 15 seconds so POS sales land here without a reload.
            </div>
        </section>
    @endif
</div>
