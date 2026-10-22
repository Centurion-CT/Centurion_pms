<div class="cx-gp">
    @include('partials.public.guest-page-styles')
    @php $back = '/guest/booking/'.$booking->booking_ref.'?token='.$token; @endphp
    <div class="cx-gp-card cx-gp-card--wide">
        <a href="{{ $back }}" wire:navigate class="cx-gp-back">← Back to your booking</a>
        <div>
            <h1 class="cx-gp-title">Extras for your stay</h1>
            <p class="cx-gp-kicker" style="margin-top: 6px;">Booking {{ $booking->booking_ref }}, {{ $booking->guest_name }}</p>
        </div>

        @error('package')
            <p class="cx-gp-note cx-gp-note--bad" data-testid="guest-packages-error">{{ $message }}</p>
        @enderror

        <section data-testid="selected-packages">
            <h2 class="cx-gp-h2">On your booking</h2>
            @if ($selected->isEmpty())
                <p class="cx-gp-text">No extras yet.</p>
            @else
                <ul class="cx-gp-list">
                    @foreach ($selected as $line)
                        <li data-testid="selected-line-{{ $line->id }}">
                            <span>{{ $line->name_snapshot }}<small>Qty {{ $line->quantity }}@if ($line->added_at === 'post_booking'), added after booking @endif</small></span>
                            <strong @if ($line->is_complimentary_snapshot) style="color: var(--emerald);" @endif>{{ $line->is_complimentary_snapshot ? 'Complimentary' : '₦'.number_format($line->line_total) }}</strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section data-testid="available-packages">
            <h2 class="cx-gp-h2">Add to your stay</h2>
            @if ($available->isEmpty())
                <p class="cx-gp-text">There are no more extras to add right now.</p>
            @else
                <ul class="cx-gp-list">
                    @foreach ($available as $pkg)
                        <li data-testid="available-package-{{ $pkg->id }}" style="align-items: center;">
                            <span style="flex: 1;">
                                {{ $pkg->name }}
                                @if ($pkg->description)<small>{{ $pkg->description }}</small>@endif
                                <small>
                                    @if ($pkg->is_complimentary)
                                        <span style="color: var(--emerald); font-weight: 600;">Complimentary</span>
                                    @else
                                        <strong style="color: var(--ink);">₦{{ number_format($pkg->effectivePrice()) }}</strong>, added to your balance
                                    @endif
                                </small>
                            </span>
                            <button type="button" wire:click="add({{ $pkg->id }})" wire:loading.attr="disabled"
                                    data-testid="add-package-{{ $pkg->id }}" class="cx-btn" style="min-height: 40px;">
                                <span wire:loading.remove wire:target="add({{ $pkg->id }})">Add</span>
                                <span wire:loading wire:target="add({{ $pkg->id }})">Adding…</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($outstandingTotal > 0)
            <div class="cx-gp-note cx-gp-note--warn" style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px; align-items: center;">
                <strong>Balance due: ₦{{ number_format($outstandingTotal) }}</strong>
                <a href="/guest/booking/{{ $booking->booking_ref }}/balance?token={{ $token }}" wire:navigate style="font-weight: 600; color: inherit;">Pay balance</a>
            </div>
        @endif
    </div>
</div>
