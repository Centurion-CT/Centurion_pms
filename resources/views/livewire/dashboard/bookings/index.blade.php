@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + primary + export ─────────────────── --}}
    <section class="lp-res-idx-hero" data-testid="bookings-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Bookings
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    @php $total = $bookings->total() ?? $bookings->count(); @endphp
                    {{ $total }} record{{ $total === 1 ? '' : 's' }} across all rooms. Search, filter, or add a walk-in.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button"
                            x-on:click="$dispatch('quick-reserve-modal-open')"
                            class="lp-res-action lp-res-action--primary"
                            data-testid="manual-booking-link">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Add manual booking
                    </button>
                    <a href="{{ route('tenant.bookings.export', [
                            'q' => $search, 'status' => $statusFilter, 'room' => $roomFilter,
                            'from' => $dateFrom, 'to' => $dateTo,
                        ]) }}"
                       data-testid="csv-export-link"
                       class="lp-res-action lp-res-action--ghost">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 11v2.5h10V11M8 3v8M5 8l3 3 3-3"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Filters bar ───────────────────────────────────────────── --}}
    <section>
        <form wire:submit.prevent class="lp-filter-row">
            <input id="bookings-search" type="search" aria-label="Search bookings"
                   wire:model.live.debounce.400ms="search"
                   data-testid="bookings-search"
                   class="lp-input is-search"
                   placeholder="Guest name, email or reference" />
            <select id="bookings-status" aria-label="Status" wire:model.live="statusFilter"
                    data-testid="bookings-status" class="lp-input">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                @endforeach
            </select>
            <select id="bookings-room" aria-label="Room" wire:model.live="roomFilter"
                    data-testid="bookings-room" class="lp-input">
                <option value="">All rooms</option>
                @foreach ($rooms as $room)
                    <option value="{{ $room->id }}">{{ $room->name }}</option>
                @endforeach
            </select>
            <input id="bookings-from" type="date" aria-label="From" wire:model.live="dateFrom"
                   data-testid="bookings-from" class="lp-input" />
            <input id="bookings-to" type="date" aria-label="To" wire:model.live="dateTo"
                   data-testid="bookings-to" class="lp-input" />
            <div class="lp-seg" data-testid="corp-filter">
                @foreach ([['', 'All'], ['only', 'Corporate'], ['exclude', 'Walk-ins']] as [$value, $label])
                    <button type="button"
                            wire:click="$set('corporateFilter', '{{ $value }}')"
                            class="{{ $corporateFilter === $value ? 'is-on' : '' }}"
                            data-testid="corp-filter-{{ $value ?: 'all' }}">{{ $label }}</button>
                @endforeach
            </div>
            <button type="button" wire:click="clearFilters" class="lp-filter-clear">Clear</button>
        </form>

        {{-- Active deep-link filters - set from the dashboard's Needs
             attention tile. Shown as removable chips so the operator
             can see (and clear) the scope without hunting for it. --}}
        @if ($outstandingFilter === '1' || $overdueFilter === '1')
            <div class="flex flex-wrap gap-2 mt-4" data-testid="bookings-scope-chips">
                @if ($outstandingFilter === '1')
                    <button type="button" wire:click="$set('outstandingFilter', '')"
                            class="lp-tag"
                            style="background: #FFF4E5; border-color: #F5D9A8; padding: 6px 12px; cursor: pointer;"
                            data-testid="scope-outstanding">
                        Outstanding balance only &times;
                    </button>
                @endif
                @if ($overdueFilter === '1')
                    <button type="button" wire:click="$set('overdueFilter', '')"
                            class="lp-tag"
                            style="background: #FDECEA; border-color: #F5B7B0; padding: 6px 12px; cursor: pointer;"
                            data-testid="scope-overdue">
                        Overdue arrivals (confirm or mark no-show) &times;
                    </button>
                @endif
            </div>
        @endif
    </section>

    {{-- ── Bookings list ───────────────────────────────────── --}}
    @if ($bookings->isEmpty())
        <section class="lp-dash-card" data-testid="no-bookings">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'clipboard',
                'title'     => 'No bookings match your filters yet.',
                'sub'       => 'Adjust the filters above or add a walk-in reservation.',
                'ctaLabel'  => 'Add manual booking',
                'ctaClickJs'=> "\$dispatch('quick-reserve-modal-open')",
            ])
        </section>
    @else
        <section class="lp-dash-card lp-table-card" data-testid="bookings-panel">
            {{-- Dense table replaces the ledger card-stack — much
                 easier to scan at a property with a large book. Row
                 click is handled by resources/js/rowclick.js via
                 data-row-href, so the row-wide click target still
                 works without any inline handler. --}}
            <div class="overflow-x-auto" data-testid="bookings-list">
                <table class="lp-table" data-testid="bookings-table">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Check in</th>
                            <th>Check out</th>
                            <th>Status</th>
                            <th style="text-align: right;">Total</th>
                            <th style="text-align: right;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($bookings as $booking)
                        @php
                            $statusValue = $booking->booking_status->value;
                            $statusClass = match ($statusValue) {
                                'confirmed'    => 'lp-tag--confirmed',
                                'on_hold'      => 'lp-tag--on-hold',
                                'pending'      => 'lp-tag--pending',
                                'cancelled'    => 'lp-tag--cancelled',
                                'checked_in'   => 'lp-tag--checked-in',
                                'checked_out'  => 'lp-tag--checked-out',
                                default        => '',
                            };
                            $statusLabel = ucfirst(str_replace('_', ' ', $statusValue));
                            $settled = in_array($statusValue, ['cancelled', 'no_show'], true);
                            $owed = (int) ($booking->balance_due ?? 0) + (int) ($booking->unsettled_charges ?? 0)
                                + ($statusValue === 'checked_in' ? $booking->unchargedOverstayAmount() : 0);
                        @endphp
                        <tr data-testid="booking-row"
                            data-booking-ref="{{ $booking->booking_ref }}"
                            data-row-href="{{ route('tenant.bookings.show', $booking->booking_ref) }}"
                            tabindex="0"
                            aria-label="Open booking {{ $booking->booking_ref }}">
                            <td>
                                <a href="{{ route('tenant.bookings.show', $booking->booking_ref) }}"
                                   wire:navigate
                                   data-testid="booking-open-{{ $booking->id }}"
                                   class="lp-row-title">{{ $booking->guest_name }}</a>
                                <p class="lp-row-sub">{{ $booking->booking_ref }}</p>
                                @if ($booking->corporate)
                                    <p class="lp-row-sub" data-testid="corp-tag-{{ $booking->id }}">{{ $booking->corporate->name }}</p>
                                @endif
                            </td>
                            <td>
                                @if ($booking->room)
                                    <span style="color: var(--color-ink);">{{ $booking->room->name }}</span>
                                @elseif ($booking->room_category_id)
                                    <div style="min-width: 0;">
                                        <p style="margin: 0; color: var(--color-ink);">
                                            {{ $booking->roomCategory?->name ?? 'Category' }}
                                        </p>
                                        <span class="lp-tag"
                                              data-testid="booking-unassigned-{{ $booking->id }}"
                                              style="font-size: 9.5px; padding: 1px 6px; margin-top: 3px; display: inline-block;
                                                     background: var(--color-surface-2); color: var(--color-ink-mute);">
                                            Unassigned
                                        </span>
                                    </div>
                                @else
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                            <td>{{ $booking->checkin_date->format('D j M') }}</td>
                            <td>{{ $booking->checkout_date?->format('D j M') }}</td>
                            <td>
                                <span class="lp-tag {{ $statusClass }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="lp-num" style="text-align: right; font-weight: 600;">
                                ₦{{ number_format($booking->total_amount) }}
                            </td>
                            <td class="lp-num" style="text-align: right; font-weight: 600; color: {{ $owed > 0 && ! $settled ? '#8F5300' : 'var(--color-ink-mute)' }};">
                                @if ($settled)
                                    -
                                @elseif ($owed > 0)
                                    ₦{{ number_format($owed) }}
                                @else
                                    Paid
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="text-[13px]" style="color: var(--color-ink-soft);">
            {{ $bookings->links() }}
        </div>
    @endif

    {{-- Shared quick-reserve action sheet + form fields partial. The
         reserve-sheet listens for the generic `quick-reserve-modal-open`
         event dispatched by the header button, and hosts the phone-first
         form + wallet payment + availability filter, same code path as
         the Reservations page's + Reserve button. Same sheet also carries
         an "Advanced" link to the full ManualCreate page. --}}
    <style>
        .lp-sheet-backdrop {
            position: fixed; inset: 0;
            background: rgba(20, 20, 30, 0.55);
            display: flex; align-items: flex-end; justify-content: center;
            z-index: 900;
        }
        .lp-sheet-panel {
            background: var(--color-card); width: 100%; max-width: 560px;
            border-radius: 16px 16px 0 0; padding: 20px 20px 24px;
            max-height: 88vh; overflow-y: auto;
            box-shadow: 0 -20px 60px rgba(0, 0, 0, 0.20);
        }
        .lp-sheet-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; margin-bottom: 12px;
        }
        .lp-sheet-title {
            font-family: var(--font-display);
            font-size: 18px; font-weight: 600; color: var(--color-ink);
            margin: 0;
        }
        .lp-sheet-close {
            background: none; border: 0; font-size: 24px; line-height: 1;
            color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;
        }
        @media (min-width: 640px) {
            .lp-sheet-backdrop { align-items: center; padding: 24px; }
            .lp-sheet-panel { border-radius: 16px; }
        }
    </style>
    @include('livewire.dashboard.reservations._modals.reserve-sheet')
</div>
