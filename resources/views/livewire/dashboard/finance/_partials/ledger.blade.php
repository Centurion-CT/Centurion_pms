<section class="lp-dash-card" data-testid="finance-ledger" style="padding: 14px 16px;">
    <div class="lp-dash-card-head" style="margin-bottom: 12px;">
        <span class="lp-dash-card-title">Recent transactions</span>
        <span class="lp-dash-card-meta">
            latest {{ count($ledger) }} ·
            <a href="{{ route('tenant.payments.index') }}" wire:navigate style="color: var(--color-accent-deep); text-decoration: underline;">
                full ledger
            </a>
        </span>
    </div>

    @if (empty($ledger))
        @include('livewire.dashboard._partials._empty', [
            'icon'  => 'wallet',
            'title' => 'No settled transactions in this window.',
            'sub'   => 'Payments and refunds land here as they hit the till.',
        ])
    @else
        <ul class="lp-ledger">
            @foreach ($ledger as $row)
                @php
                    $isRefund = $row['status'] === 'refunded';
                    $paidAt = $row['paid_at'] ? \Illuminate\Support\Carbon::parse($row['paid_at']) : null;
                    $tone = $isRefund ? 'warn' : 'paid';
                @endphp
                <li class="lp-ledger-item lp-channel-row"
                    data-testid="finance-ledger-row-{{ $row['id'] }}"
                    x-on:click="$dispatch('finance-breakdown-open', { id: 'ledger:{{ $row['id'] }}' })"
                    tabindex="0"
                    x-on:keydown.enter.prevent="$dispatch('finance-breakdown-open', { id: 'ledger:{{ $row['id'] }}' })"
                    x-on:keydown.space.prevent="$dispatch('finance-breakdown-open', { id: 'ledger:{{ $row['id'] }}' })">
                    <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                    <div class="lp-ledger-body">
                        <div class="lp-gr-primary">
                            @if ($row['booking_ref'])
                                <span class="lp-ledger-desc" style="font-weight: 500; color: var(--color-ink);">
                                    {{ $row['guest_name'] ?? 'Unnamed guest' }}
                                </span>
                                <span class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px;">
                                    {{ $row['booking_ref'] }}
                                </span>
                            @else
                                <span class="lp-ledger-desc" style="color: var(--color-ink-mute); font-style: italic;">
                                    Unattached
                                </span>
                            @endif
                            <span class="lp-category-type">{{ $isRefund ? 'refund' : $row['type'] }}</span>
                        </div>
                        <p class="lp-ledger-meta">
                            {{ $paidAt?->format('M j · g:i A') ?? '-' }}
                            @if ($row['source_label'])
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $row['source_label'] }}
                            @endif
                        </p>
                    </div>
                    <div class="lp-ledger-right">
                        <span class="lp-invoice-total" style="color: {{ $isRefund ? '#B85C42' : 'var(--color-ink)' }};">
                            {{ $isRefund ? '−' : '' }}₦{{ number_format($row['amount']) }}
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
