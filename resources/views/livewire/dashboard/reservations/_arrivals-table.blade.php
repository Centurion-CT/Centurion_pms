{{--
    Card-stack arrivals list for the Reservations page. `bookings`
    is a Collection<Booking> (Confirmed/OnHold on a target date);
    `empty` is the copy shown when nothing's on the list.

    Whole card is clickable, dispatches `arrival-panel-open` with
    the booking id; the shared Alpine arrival panel (rendered once
    in index.blade.php) opens with the pre-hydrated payload.
    Actions live in the panel — the card stays scannable.
--}}
@if ($bookings->isEmpty())
    @include('livewire.dashboard._partials._empty', [
        'icon'    => 'calendar',
        'title'   => $empty,
        'sub'     => 'Reserve a room to add someone to the list.',
        'ctaLabel'=> 'Reserve room',
        'ctaEvent'=> ['reservations-modal-open', ['name' => 'reserve']],
    ])
@else
    <ul class="lp-arrivals" data-testid="arrivals-list">
        @foreach ($bookings as $b)
            @php
                $nights = $b->checkin_date && $b->checkout_date
                    ? max(0, (int) $b->checkin_date->diffInDays($b->checkout_date))
                    : 0;
                $statusVal = $b->booking_status?->value ?? '';
                $markerTone = match ($statusVal) {
                    'confirmed' => 'positive',
                    'on_hold'   => 'warn',
                    'pending'   => 'muted',
                    default     => 'muted',
                };
                [$chipBg, $chipFg] = match ($statusVal) {
                    'confirmed'  => ['#DCFCE7', '#166534'],
                    'on_hold'    => ['#FEF3C7', '#92400E'],
                    'pending'    => ['rgba(20,23,26,0.06)', 'var(--color-ink-soft)'],
                    default      => ['rgba(20,23,26,0.06)', 'var(--color-ink-soft)'],
                };
            @endphp
            <li class="lp-arrivals-item"
                data-testid="arrival-row-{{ $b->booking_ref }}"
                x-on:click="$dispatch('arrival-panel-open', { id: {{ $b->id }} })"
                tabindex="0"
                x-on:keydown.enter.prevent="$dispatch('arrival-panel-open', { id: {{ $b->id }} })">
                <span class="lp-ledger-marker lp-ledger-marker--{{ $markerTone === 'positive' ? 'paid' : ($markerTone === 'warn' ? 'warn' : 'info') }}" aria-hidden></span>
                <div class="lp-arrivals-body">
                    <div class="lp-arrivals-primary">
                        <span class="lp-arrivals-name">{{ $b->guest_name }}</span>
                        <span class="lp-mono lp-arrivals-ref">{{ $b->booking_ref }}</span>
                    </div>
                    <p class="lp-arrivals-meta">
                        @if ($b->room)
                            Rm {{ $b->room->room_number }}
                            @if ($b->room->category)
                                <span style="color: var(--color-ink-mute);">·</span> {{ $b->room->category->name }}
                            @endif
                        @elseif ($b->room_category_id)
                            {{ $b->roomCategory?->name ?? 'Category' }}
                            <span class="lp-tag" style="font-size: 9.5px; padding: 1px 6px; margin-left: 4px;
                                                        background: var(--color-surface-2);
                                                        color: var(--color-ink-mute);
                                                        text-transform: uppercase; letter-spacing: 0.05em;">
                                Unassigned
                            </span>
                        @endif
                        <span style="color: var(--color-ink-mute);">·</span>
                        {{ $b->checkin_date?->format('D, j M') ?? '-' }}
                        <span style="color: var(--color-ink-mute);">→</span>
                        {{ $b->checkout_date?->format('D, j M') ?? '-' }}
                        <span style="color: var(--color-ink-mute);">·</span>
                        {{ $nights }}n
                        @if ($b->corporate)
                            <span style="color: var(--color-ink-mute);">·</span>
                            {{ $b->corporate->name }}
                        @endif
                    </p>
                </div>
                <div class="lp-arrivals-right">
                    <span class="lp-mono lp-arrivals-status"
                          style="background: {{ $chipBg }}; color: {{ $chipFg }};">
                        {{ $b->booking_status?->label() ?? '-' }}
                    </span>
                </div>
            </li>
        @endforeach
    </ul>
@endif
