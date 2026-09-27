<section class="lp-dash-card" data-testid="finance-channels" style="padding: 14px 16px;">
    <div class="lp-dash-card-head" style="margin-bottom: 12px;">
        <span class="lp-dash-card-title">Channel performance</span>
        <span class="lp-dash-card-meta">which platform is bringing in the money</span>
    </div>

    @if (empty($channels))
        @include('livewire.dashboard._partials._empty', [
            'icon'  => 'wallet',
            'title' => 'No channel revenue in this window.',
            'sub'   => 'Widen the range or check back after the next confirmed booking.',
        ])
    @else
        @php
            $topRevenue = collect($channels)->max('revenue') ?: 1;
        @endphp
        <ul class="lp-ledger lp-channel-stack">
            @foreach ($channels as $row)
                @php
                    $delta = $row['delta'];
                    if ($delta === null) {
                        $deltaLabel = 'new';
                        $deltaTone  = 'muted';
                    } else {
                        $deltaLabel = ($delta >= 0 ? '+' : '').$delta.'%';
                        $deltaTone  = $delta >= 0 ? 'paid' : 'warn';
                    }
                    $isLeader = (int) $row['revenue'] === (int) $topRevenue && (int) $topRevenue > 0;
                    $markerTone = $isLeader ? 'paid' : 'info';
                @endphp
                <li class="lp-ledger-item lp-channel-row"
                    data-testid="finance-channel-row-{{ $row['source'] }}"
                    x-on:click="$dispatch('finance-breakdown-open', { id: 'channel:{{ $row['source'] }}' })"
                    tabindex="0"
                    role="button"
                    x-on:keydown.enter.prevent="$dispatch('finance-breakdown-open', { id: 'channel:{{ $row['source'] }}' })"
                    x-on:keydown.space.prevent="$dispatch('finance-breakdown-open', { id: 'channel:{{ $row['source'] }}' })">
                    <span class="lp-ledger-marker lp-ledger-marker--{{ $markerTone }}" aria-hidden></span>
                    <div class="lp-ledger-body">
                        <div class="lp-gr-primary">
                            <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                {{ $row['label'] }}
                            </span>
                            @if ($isLeader)
                                <span class="lp-tag lp-tag--confirmed" style="font-size: 9.5px;">Top</span>
                            @endif
                            <span class="lp-channel-delta lp-channel-delta--{{ $deltaTone }}"
                                  aria-label="vs prior window">{{ $deltaLabel }}</span>
                        </div>
                        <p class="lp-ledger-meta">
                            <span class="lp-mono">{{ number_format($row['bookings']) }}</span>
                            {{ Str::plural('booking', (int) $row['bookings']) }}
                            <span style="color: var(--color-ink-mute);">·</span>
                            avg <span class="lp-mono">₦{{ number_format($row['avgValue']) }}</span>
                            <span style="color: var(--color-ink-mute);">·</span>
                            <span class="lp-channel-bar" aria-hidden>
                                <span class="lp-channel-bar-fill" style="width: {{ $row['share'] }}%;"></span>
                            </span>
                            <span class="lp-mono" style="color: var(--color-ink-soft);">{{ $row['share'] }}%</span>
                            share
                        </p>
                    </div>
                    <div class="lp-ledger-right">
                        <span class="lp-invoice-total">₦{{ number_format($row['revenue']) }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
