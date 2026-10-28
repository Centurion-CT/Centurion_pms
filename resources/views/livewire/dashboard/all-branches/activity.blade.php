{{-- Live activity on the All branches page; styles come from the page (.cx-ab). --}}
<section class="ab-card ab-pad" wire:poll.30s data-testid="ab-activity">
    <div class="ab-list-head">
        <h2 class="ab-h2">Live activity</h2>
        <span class="ab-soft" style="display: inline-flex; align-items: center; gap: 7px; font-size: 13px;">
            <span aria-hidden="true" style="width: 8px; height: 8px; border-radius: 50%; background: #1F7A50; box-shadow: 0 0 0 4px rgba(31,122,80,.16);"></span>
            Updates every 30 seconds
        </span>
    </div>
    @forelse ($items as $item)
        <div class="ab-item is-feed">
            <span class="ab-mute" style="font-size: 13px; padding-top: 1px;" title="{{ $item['at']->format('j M Y, g:i a') }}">
                {{ $item['at']->isToday() ? $item['at']->format('g:i a') : $item['at']->format('j M') }}
            </span>
            <div><div class="ab-t">{{ $item['title'] }}</div><div class="ab-s">{{ $item['subtitle'] }}</div></div>
            <div class="ab-item-end">
                <span class="ab-tag">{{ $item['branch'] }}</span>
                @if ($item['amount'] !== null)
                    <div class="ab-num" style="margin-top: 6px; font-weight: 700; color: {{ $item['amount'] >= 0 ? '#0F8A5F' : 'var(--color-ink)' }};">
                        {{ $item['amount'] >= 0 ? '+' : '−' }}₦{{ number_format(abs($item['amount'])) }}
                    </div>
                @endif
            </div>
        </div>
    @empty
        <p class="ab-empty">Nothing yet. Payments, bookings, check-ins and guest requests from every branch show here as they happen.</p>
    @endforelse
</section>
