{{-- Arrivals and departures (mockup 3A). Lives inside the widget-today
     section, so `openId` (the guest folio panel) is in scope. Guest names
     open the panel; the right-hand button is the next thing to do. --}}
@php
    $adArrivals = $today['arrivals']->concat($unassignedToday)->values();
    $adLeaving = $today['departures']->values();
    $adInHouse = ($today['inHouse'] ?? collect())->values();
    $adTab = $adArrivals->isNotEmpty() ? 'arriving' : ($adLeaving->isNotEmpty() ? 'leaving' : 'inhouse');
    $adLimit = 8;
    $adNights = fn ($b) => ($n = (int) ($b->nights ?? 0)) === 1 ? '1 night' : $n.' nights';
    $adRoom = function ($b): string {
        if ($b->room) {
            return e($b->room->room_number).' <span class="cx-ad-muted">'.e($b->room->category?->name ?? '').'</span>';
        }
        return '<span class="cx-ad-muted">Unassigned, '.e($b->roomCategory?->name ?? 'no category').'</span>';
    };
@endphp
<div x-data="{ tab: @js($adTab) }">
    <div class="cx-ad-head">
        <h2 class="cx-h2">Arrivals and departures</h2>
        <div class="cx-tabs" role="tablist" aria-label="Today's guests">
            <button type="button" role="tab" class="cx-tab" :aria-selected="tab === 'arriving'" @click="tab = 'arriving'" data-testid="ad-tab-arriving">Arriving {{ $adArrivals->count() }}</button>
            <button type="button" role="tab" class="cx-tab" :aria-selected="tab === 'leaving'" @click="tab = 'leaving'" data-testid="ad-tab-leaving">Leaving {{ $adLeaving->count() }}</button>
            <button type="button" role="tab" class="cx-tab" :aria-selected="tab === 'inhouse'" @click="tab = 'inhouse'" data-testid="ad-tab-inhouse">In-house {{ $adInHouse->count() }}</button>
        </div>
    </div>

    {{-- Arriving --}}
    <div x-show="tab === 'arriving'" @if ($adTab !== 'arriving') x-cloak @endif role="tabpanel">
        @if ($adArrivals->isEmpty())
            <p class="cx-ad-empty">No arrivals today.</p>
        @else
            <div class="cx-ad-scroll">
                <table class="cx-ad-table">
                    <thead><tr><th>Guest</th><th>Room</th><th>Stay</th><th class="is-num">Balance</th><th><span class="sr-only">Action</span></th></tr></thead>
                    <tbody data-testid="widget-today-schedule">
                        @foreach ($adArrivals->take($adLimit) as $b)
                            @php $due = (int) ($b->balance_due ?? 0); @endphp
                            <tr data-testid="schedule-row-{{ $b->booking_ref }}">
                                <td>
                                    <button type="button" class="cx-ad-guest" @click="openId = {{ $b->id }}" data-testid="schedule-row-open-{{ $b->booking_ref }}">
                                        <strong>{{ $b->guest_name }}</strong>
                                        <span class="cx-ad-sub">{{ $b->booking_ref }}@if ($b->booking_status === \App\Enums\BookingStatus::OnHold), deposit paid @elseif ($b->booking_status === \App\Enums\BookingStatus::Pending), awaiting payment @endif</span>
                                    </button>
                                </td>
                                <td>{!! $adRoom($b) !!}</td>
                                <td>{{ $adNights($b) }}</td>
                                <td class="is-num">@if ($due > 0)<span class="cx-due">{{ money($due) }} due</span>@else Paid @endif</td>
                                <td>
                                    @if (! $b->room)
                                        <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}" wire:navigate class="cx-btn-sm">Assign room</a>
                                    @elseif ($due > 0)
                                        <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}" wire:navigate class="cx-btn-sm">Take payment</a>
                                    @else
                                        <button type="button" class="cx-btn-sm cx-btn-sm--primary"
                                                @click="window.kevernaConfirm({ message: @js('Check in '.$b->guest_name.' now?') }).then((ok) => { if (ok) $wire.quickCheckIn({{ $b->id }}) })"
                                                wire:loading.attr="disabled" wire:target="quickCheckIn({{ $b->id }})"
                                                data-testid="ad-checkin-{{ $b->booking_ref }}">Check in</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Leaving --}}
    <div x-show="tab === 'leaving'" @if ($adTab !== 'leaving') x-cloak @endif role="tabpanel">
        @if ($adLeaving->isEmpty())
            <p class="cx-ad-empty">Nobody is due to check out today.</p>
        @else
            <div class="cx-ad-scroll">
                <table class="cx-ad-table">
                    <thead><tr><th>Guest</th><th>Room</th><th>Stay</th><th class="is-num">Balance</th><th><span class="sr-only">Action</span></th></tr></thead>
                    <tbody>
                        @foreach ($adLeaving->take($adLimit) as $b)
                            @php $owed = (int) $b->outstandingTotal(); @endphp
                            <tr data-testid="schedule-row-{{ $b->booking_ref }}">
                                <td>
                                    <button type="button" class="cx-ad-guest" @click="openId = {{ $b->id }}" data-testid="schedule-row-open-{{ $b->booking_ref }}">
                                        <strong>{{ $b->guest_name }}</strong>
                                        <span class="cx-ad-sub">{{ $b->booking_ref }}</span>
                                    </button>
                                </td>
                                <td>{!! $adRoom($b) !!}</td>
                                <td>Leaves today</td>
                                <td class="is-num">@if ($owed > 0)<span class="cx-due">{{ money($owed) }} due</span>@else Paid @endif</td>
                                <td>
                                    @if ($owed > 0)
                                        <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}" wire:navigate class="cx-btn-sm">Settle</a>
                                    @else
                                        <button type="button" class="cx-btn-sm cx-btn-sm--primary"
                                                @click="window.kevernaConfirm({ message: @js('Check out '.$b->guest_name.' now?') }).then((ok) => { if (ok) $wire.quickCheckOut({{ $b->id }}) })"
                                                wire:loading.attr="disabled" wire:target="quickCheckOut({{ $b->id }})"
                                                data-testid="ad-checkout-{{ $b->booking_ref }}">Check out</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- In-house --}}
    <div x-show="tab === 'inhouse'" @if ($adTab !== 'inhouse') x-cloak @endif role="tabpanel">
        @if ($adInHouse->isEmpty())
            <p class="cx-ad-empty">Nobody is in-house right now.</p>
        @endif
        <div class="cx-ad-scroll" @if ($adInHouse->isEmpty()) hidden @endif>
            <table class="cx-ad-table">
                <thead><tr><th>Guest</th><th>Room</th><th>Stay</th><th class="is-num">Balance</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody data-testid="widget-today-inhouse">
                    @foreach ($adInHouse->take($adLimit) as $g)
                        @php
                            $rowOutstanding = (int) $g->outstandingTotal();
                            $nightsLeft = $g->checkout_date
                                ? (int) \Illuminate\Support\Carbon::today()->diffInDays($g->checkout_date, false)
                                : null;
                            $leaves = match (true) {
                                $nightsLeft === null => '-',
                                $nightsLeft < 0 => 'Overstayed, due '.$g->checkout_date->format('j M'),
                                $nightsLeft === 0 => 'Leaves today',
                                $nightsLeft === 1 => 'Leaves tomorrow',
                                default => 'Leaves '.$g->checkout_date->format('D j M'),
                            };
                            $confirmEarly = 'Early checkout - '.$g->guest_name."'s booked checkout is "
                                .($g->checkout_date?->format('j M') ?? '-').'. Continue?';
                        @endphp
                        <tr data-testid="widget-today-inhouse-row-{{ $g->booking_ref }}">
                            <td>
                                <button type="button" class="cx-ad-guest" @click="openId = {{ $g->id }}" data-testid="widget-today-inhouse-open-{{ $g->booking_ref }}">
                                    <strong>{{ $g->guest_name }}</strong>
                                    <span class="cx-ad-sub">{{ $g->booking_ref }}</span>
                                </button>
                            </td>
                            <td>{!! $adRoom($g) !!}</td>
                            <td @if ($nightsLeft !== null && $nightsLeft < 0) class="cx-due" @endif>{{ $leaves }}</td>
                            <td class="is-num">@if ($rowOutstanding > 0)<span class="cx-due">{{ money($rowOutstanding) }} due</span>@else Paid @endif</td>
                            <td>
                                <span class="cx-ad-actions">
                                    <button type="button" class="cx-btn-sm" wire:click="openExtendModal({{ $g->id }})"
                                            data-testid="widget-today-inhouse-extend-{{ $g->booking_ref }}">Extend</button>
                                    @if ($rowOutstanding <= 0)
                                        <button type="button" class="cx-btn-sm cx-btn-sm--primary"
                                                wire:click="quickCheckOut({{ $g->id }})"
                                                @if ($nightsLeft !== null && $nightsLeft > 0) wire:confirm="{{ $confirmEarly }}" @else wire:confirm="Check out {{ $g->guest_name }} now?" @endif
                                                wire:loading.attr="disabled" wire:target="quickCheckOut({{ $g->id }})"
                                                data-testid="widget-today-inhouse-checkout-{{ $g->booking_ref }}">Check out</button>
                                    @else
                                        <a href="{{ route('tenant.reservations.show', $g->booking_ref) }}" wire:navigate class="cx-btn-sm"
                                           data-testid="widget-today-inhouse-settle-{{ $g->booking_ref }}">Settle</a>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="cx-ad-foot">
        <a href="{{ route('tenant.reservations.index') }}" wire:navigate>See all reservations</a>
        <a href="{{ route('tenant.in-house.index') }}" wire:navigate data-testid="widget-today-inhouse-all">Everyone in-house</a>
    </div>
</div>
