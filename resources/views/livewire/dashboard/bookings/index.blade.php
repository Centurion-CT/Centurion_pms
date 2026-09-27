@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + primary + export ─────────────────── --}}
    <section class="lp-res-idx-hero" data-testid="bookings-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate</span>
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
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
            <button type="button" wire:click="clearFilters"
                    class="text-[12px]" style="color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px; cursor: pointer; background: transparent; border: 0; font-family: inherit;">
                Clear all
            </button>
        </div>

        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label for="bookings-search" class="lp-form-label">Search</label>
                <input id="bookings-search" type="search"
                       wire:model.live.debounce.400ms="search"
                       data-testid="bookings-search"
                       class="lp-input"
                       placeholder="Guest name, email, or ref" />
            </div>

            <div>
                <label for="bookings-status" class="lp-form-label">Status</label>
                <select id="bookings-status" wire:model.live="statusFilter"
                        data-testid="bookings-status" class="lp-input">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option->value }}">{{ $option->value }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="bookings-room" class="lp-form-label">Room</label>
                <select id="bookings-room" wire:model.live="roomFilter"
                        data-testid="bookings-room" class="lp-input">
                    <option value="">All rooms</option>
                    @foreach ($rooms as $room)
                        <option value="{{ $room->id }}">{{ $room->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:col-span-5">
                <div>
                    <label for="bookings-from" class="lp-form-label">From</label>
                    <input id="bookings-from" type="date" wire:model.live="dateFrom"
                           data-testid="bookings-from" class="lp-input" />
                </div>
                <div>
                    <label for="bookings-to" class="lp-form-label">To</label>
                    <input id="bookings-to" type="date" wire:model.live="dateTo"
                           data-testid="bookings-to" class="lp-input" />
                </div>
            </div>

            <div class="lg:col-span-12">
                <label class="lp-form-label">Corporate</label>
                <div class="flex gap-2 mt-1" data-testid="corp-filter">
                    @foreach ([['', 'All'], ['only', 'Corporate only'], ['exclude', 'Walk-ins only']] as [$value, $label])
                        <button type="button"
                                wire:click="$set('corporateFilter', '{{ $value }}')"
                                class="lp-tag"
                                data-testid="corp-filter-{{ $value ?: 'all' }}"
                                style="cursor: pointer; padding: 6px 12px;
                                       {{ $corporateFilter === $value
                                          ? 'background: var(--color-ink); color: var(--color-card); border-color: var(--color-ink);'
                                          : '' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
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
        <section class="lp-dash-card" data-testid="bookings-panel" style="padding: 14px 16px;">
            {{-- Dense table replaces the ledger card-stack — much
                 easier to scan at a property with a large book. Row
                 click is handled by resources/js/rowclick.js via
                 data-row-href, so the row-wide click target still
                 works without any inline handler. --}}
            <div class="overflow-x-auto" data-testid="bookings-list">
                <table class="lp-table" data-testid="bookings-table">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Stay</th>
                            <th>Status</th>
                            <th style="text-align: right;">Total</th>
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
                            $statusLabel = ucwords(str_replace('_', ' ', $statusValue));
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
                                   class="lp-mono"
                                   style="font-size: 12px; color: var(--color-ink); text-decoration: none;">
                                    {{ $booking->booking_ref }}
                                </a>
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="margin: 0; font-weight: 500; color: var(--color-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        {{ $booking->guest_name }}
                                    </p>
                                    @if ($booking->corporate)
                                        <span class="lp-tag"
                                              data-testid="corp-tag-{{ $booking->id }}"
                                              style="font-size: 9.5px; padding: 1px 6px; margin-top: 3px; display: inline-block;
                                                     background: color-mix(in srgb, var(--color-accent-deep) 12%, transparent);
                                                     color: var(--color-accent-deep); border: 0; letter-spacing: 0.06em; text-transform: uppercase;">
                                            Corp · {{ $booking->corporate->name }}
                                        </span>
                                    @elseif ($booking->guest_email)
                                        <p style="margin: 2px 0 0; font-size: 11.5px; color: var(--color-ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px;">
                                            {{ $booking->guest_email }}
                                        </p>
                                    @endif
                                </div>
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
                                                     background: var(--color-surface-2); color: var(--color-ink-mute);
                                                     text-transform: uppercase; letter-spacing: 0.05em;">
                                            Unassigned
                                        </span>
                                    </div>
                                @else
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="margin: 0; font-size: 12.5px; color: var(--color-ink);">
                                        {{ $booking->checkin_date->format('j M Y') }}
                                    </p>
                                    <p class="lp-mono" style="margin: 2px 0 0; font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.02em;">
                                        {{ $booking->nights }}n
                                    </p>
                                </div>
                            </td>
                            <td>
                                <span class="lp-tag {{ $statusClass }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="lp-num" style="text-align: right; font-weight: 600;">
                                ₦{{ number_format($booking->total_amount) }}
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
            font-family: 'Fraunces', ui-serif, Georgia, serif;
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
