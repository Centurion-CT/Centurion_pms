<div wire:poll.60s data-testid="dash-alerts-card">
    @php
        $totalCount = $counts['store_request'] + $counts['low_stock'];
    @endphp

    @if ($totalCount === 0)
        {{-- Silent on quiet days — the topbar bell still carries any
             stray signal without eating dashboard real estate here. --}}
    @else
        <section class="lp-dash-card lp-attention" data-testid="dash-alerts-card-panel">
            <div class="lp-attention-head">
                <div class="lp-attention-title-wrap">
                    <span class="lp-dash-card-title">Needs attention</span>
                    <span class="lp-attention-count" aria-label="{{ $totalCount }} items">
                        {{ $totalCount }}
                    </span>
                    @if ($counts['store_request'] > 0)
                        <a href="{{ route('tenant.store.index') }}" wire:navigate
                           class="lp-attention-pill lp-attention-pill--info"
                           data-testid="dash-alerts-tile-store-request">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 5h12v9H2zM5 5V3h6v2M6 9h4"/></svg>
                            <span><strong>{{ $counts['store_request'] }}</strong> store request{{ $counts['store_request'] === 1 ? '' : 's' }}</span>
                        </a>
                    @endif
                    @if ($counts['low_stock'] > 0)
                        <span class="lp-attention-pill lp-attention-pill--warn"
                              data-testid="dash-alerts-tile-low-stock">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 4l4-2 4 2 4-2v10l-4 2-4-2-4 2z"/></svg>
                            <span><strong>{{ $counts['low_stock'] }}</strong> low stock</span>
                        </span>
                    @endif
                </div>
                <span class="lp-dash-card-meta">Real-time signals routed to your role</span>
            </div>

            @if ($items->isNotEmpty())
                {{-- Item list in the ledger card-stack style — same
                     marker dot / body / meta rhythm as the reservation
                     ledger, so front-desk parses these rows with the
                     same eye-movement they use elsewhere. --}}
                <ul class="lp-ledger lp-attention-list">
                    @foreach ($items as $item)
                        @php
                            $isStore = $item['kind'] === 'store_request';
                            $markerTone = $isStore ? 'info' : 'warn';
                            $kindLabel = $item['kind_label'];
                        @endphp
                        <li class="lp-ledger-item">
                            <a href="{{ $item['url'] }}" wire:navigate
                               data-testid="dash-alerts-item"
                               class="lp-attention-item-inner">
                                <span class="lp-ledger-marker lp-ledger-marker--{{ $markerTone }}" aria-hidden></span>
                                <div class="lp-ledger-body">
                                    <p class="lp-ledger-desc">{{ $item['title'] }}</p>
                                    <p class="lp-ledger-meta">
                                        <span class="lp-attention-kind lp-attention-kind--{{ $isStore ? 'info' : 'warn' }}">{{ $kindLabel }}</span>
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ $item['subtitle'] }}
                                    </p>
                                </div>
                                <div class="lp-ledger-right">
                                    <span class="lp-mono" style="font-size: 10.5px; color: var(--color-ink-mute); white-space: nowrap;">
                                        {{ $item['at']?->diffForHumans(short: true) }}
                                    </span>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
