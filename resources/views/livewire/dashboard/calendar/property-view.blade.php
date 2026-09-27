<div class="lp-dash" wire:poll.20s="refresh">

    {{-- ── Page header ────────────────────────────────────────────── --}}
    @php
        // Window spans one or two months, render the heading
        // accordingly so the operator always sees the month boundary
        // they're looking at.
        $windowLabel = $windowStart->format('M Y') === $windowEnd->format('M Y')
            ? $windowStart->format('F Y')
            : $windowStart->format('M').' → '.$windowEnd->format('M Y');
    @endphp

    {{-- ── Hero: window label + primary window nav ─────────
         Identity block on the left with the current window's
         month range as an editorial title. The window-nav
         cluster on the right carries Today + prev/next as
         .lp-res-action pills and a compact month-range pill. --}}
    <section class="lp-res-idx-hero" data-testid="calendar-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Calendar</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    {{ $windowLabel }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    {{ $windowStart->format('D, j M') }} → {{ $windowEnd->format('D, j M') }}.
                    Click any free day or drag across a range to start a reservation.
                </p>
                <div class="lp-res-idx-hero-actions" data-testid="window-nav">
                    <button type="button" wire:click="goToToday"
                            data-testid="go-today"
                            class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="1.5" fill="currentColor" stroke="none"/></svg>
                        Today
                    </button>
                    <button type="button" wire:click="togglePast"
                            data-testid="toggle-past"
                            class="lp-res-action {{ $showPast ? 'lp-res-action--accent' : 'lp-res-action--ghost' }}">
                        {{ $showPast ? 'Hiding past days' : 'Show past days' }}
                    </button>
                    <div class="lp-rates-month-nav">
                        <button type="button" wire:click="previousWindow"
                                data-testid="prev-window"
                                class="lp-rates-month-btn" aria-label="Previous 30 days">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M7.5 3L4.5 6l3 3"/></svg>
                        </button>
                        <span class="lp-rates-month-label">
                            {{ $windowStart->format('j M') }} – {{ $windowEnd->format('j M') }}
                        </span>
                        <button type="button" wire:click="nextWindow"
                                data-testid="next-window"
                                class="lp-rates-month-btn" aria-label="Next 30 days">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4.5 3l3 3-3 3"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Visible rooms">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Rooms in view</p>
                    <p class="lp-ih-hero-accrued-value" style="color: var(--color-ink);">
                        {{ number_format($visibleRoomCount) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        of {{ number_format($totalRoomCount) }} total ·
                        {{ count(is_array($days) ? $days : $days->all()) }} day window
                    </p>
                </div>
            </aside>
        </div>
    </section>

    @php $todayStr = \Illuminate\Support\Carbon::today()->toDateString(); @endphp

    {{-- Quick-search strip (HasCalendarSearch trait). --}}
    @if ($totalRoomCount > 0)
        @include('livewire.dashboard._partials.calendar-search', [
            'visibleCount' => $visibleRoomCount,
            'totalCount'   => $totalRoomCount,
        ])
    @endif

    @if ($rooms->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-rooms" class="text-[16px]"
               style="color: var(--color-ink); font-weight: 500;">
                @if ($totalRoomCount > 0 && $this->calendarSearchActive())
                    No rooms match the current filter.
                @else
                    Add active rooms to see the calendar.
                @endif
            </p>
        </section>
    @else
        {{-- ── Mobile agenda view ────────────────────────────────────
             Below 640px, the Gantt matrix is unusable (rotated grid,
             tiny cells, no room to scan). Swap in a per-day agenda
             list so an operator on their phone can still triage
             today's arrivals / departures / in-house / overdue at a
             glance. Uses the same $matrix + $chipMeta + $bookingByCell
             data already computed, no new server work. --}}
        @php
            $daysList = is_array($days) ? $days : $days->all();
            $agendaByDate = [];
            foreach ($daysList as $day) {
                $iso = $day->toDateString();
                $agendaByDate[$iso] = [
                    'day' => $day,
                    'arrivals'   => [],
                    'departures' => [],
                    'inHouse'    => 0,
                    'overdue'    => [],
                    'blocked'    => 0,
                ];
            }
            foreach ($rooms as $room) {
                $stateMap = $matrix[$room->id] ?? [];
                $chipsForRoom = $chipMeta[$room->id] ?? [];
                foreach ($daysList as $day) {
                    $iso = $day->toDateString();
                    $state = $stateMap[$iso] ?? null;
                    $chip = $chipsForRoom[$iso] ?? null;
                    if ($state === null) continue;
                    if ($state === 'blocked') {
                        $agendaByDate[$iso]['blocked']++;
                        continue;
                    }
                    if ($chip && in_array($chip['position'], ['start', 'single'], true)) {
                        $agendaByDate[$iso]['arrivals'][] = [
                            'surname' => $chip['surname'],
                            'room'    => $room->room_number ?? ('#'.$room->id),
                            'booking_id' => $chip['booking_id'],
                        ];
                    }
                    if ($chip && in_array($chip['position'], ['end', 'single'], true)) {
                        $agendaByDate[$iso]['departures'][] = [
                            'surname' => $chip['surname'],
                            'room'    => $room->room_number ?? ('#'.$room->id),
                            'booking_id' => $chip['booking_id'],
                        ];
                    }
                    if ($state === 'checkedin' || $state === 'due_out') {
                        $agendaByDate[$iso]['inHouse']++;
                    }
                    if ($state === 'overdue') {
                        $agendaByDate[$iso]['overdue'][] = [
                            'surname' => $chip['surname'] ?? 'Guest',
                            'room'    => $room->room_number ?? ('#'.$room->id),
                            'booking_id' => $chip['booking_id'] ?? null,
                        ];
                    }
                }
            }
        @endphp
        <section class="lp-cal-agenda" data-testid="calendar-agenda"
                 aria-label="Calendar agenda (mobile)">
            @foreach ($agendaByDate as $iso => $entry)
                @php
                    $day = $entry['day'];
                    $isToday = $iso === $todayStr;
                    $isPast = $iso < $todayStr;
                    $isWeekend = in_array($day->dayOfWeek, [0, 6], true);
                    $hasAny = count($entry['arrivals']) + count($entry['departures'])
                            + $entry['inHouse'] + count($entry['overdue']) + $entry['blocked'] > 0;
                @endphp
                <div class="lp-cal-agenda__day @if ($isToday) is-today @endif @if ($isPast) is-past @endif @if ($isWeekend) is-weekend @endif"
                     data-testid="agenda-day-{{ $iso }}">
                    <div class="lp-cal-agenda__date">
                        <span class="lp-cal-agenda__weekday">{{ $day->format('D') }}</span>
                        <span class="lp-cal-agenda__daynum">{{ $day->day }}</span>
                        <span class="lp-cal-agenda__month">{{ $day->format('M') }}</span>
                        @if ($isToday)
                            <span class="lp-cal-agenda__today-badge">Today</span>
                        @endif
                    </div>
                    <div class="lp-cal-agenda__body">
                        @if (! $hasAny)
                            <p class="lp-cal-agenda__quiet">Nothing scheduled.</p>
                        @else
                            @if (count($entry['arrivals']) > 0)
                                <div class="lp-cal-agenda__row lp-cal-agenda__row--arrivals">
                                    <span class="lp-cal-agenda__glyph" aria-hidden>↑</span>
                                    <span>
                                        <strong>{{ count($entry['arrivals']) }}</strong>
                                        {{ count($entry['arrivals']) === 1 ? 'arrival' : 'arrivals' }}
                                    </span>
                                    <span class="lp-cal-agenda__names">
                                        @foreach (array_slice($entry['arrivals'], 0, 3) as $a)
                                            <button type="button" class="lp-cal-agenda__chip"
                                                    x-on:click="$dispatch('booking-sheet-request-open', { id: {{ $a['booking_id'] }}, surname: {{ json_encode($a['surname']) }} });
                                                                $wire.openBookingSheet({{ $a['booking_id'] }})">
                                                {{ $a['surname'] }} <span class="mono">Rm {{ $a['room'] }}</span>
                                            </button>
                                        @endforeach
                                        @if (count($entry['arrivals']) > 3)
                                            <span class="lp-cal-agenda__more">+{{ count($entry['arrivals']) - 3 }} more</span>
                                        @endif
                                    </span>
                                </div>
                            @endif
                            @if (count($entry['departures']) > 0)
                                <div class="lp-cal-agenda__row lp-cal-agenda__row--departures">
                                    <span class="lp-cal-agenda__glyph" aria-hidden>↓</span>
                                    <span>
                                        <strong>{{ count($entry['departures']) }}</strong>
                                        {{ count($entry['departures']) === 1 ? 'departure' : 'departures' }}
                                    </span>
                                    <span class="lp-cal-agenda__names">
                                        @foreach (array_slice($entry['departures'], 0, 3) as $d)
                                            <button type="button" class="lp-cal-agenda__chip"
                                                    x-on:click="$dispatch('booking-sheet-request-open', { id: {{ $d['booking_id'] }}, surname: {{ json_encode($d['surname']) }} });
                                                                $wire.openBookingSheet({{ $d['booking_id'] }})">
                                                {{ $d['surname'] }} <span class="mono">Rm {{ $d['room'] }}</span>
                                            </button>
                                        @endforeach
                                        @if (count($entry['departures']) > 3)
                                            <span class="lp-cal-agenda__more">+{{ count($entry['departures']) - 3 }} more</span>
                                        @endif
                                    </span>
                                </div>
                            @endif
                            @if ($entry['inHouse'] > 0)
                                <div class="lp-cal-agenda__row lp-cal-agenda__row--inhouse">
                                    <span class="lp-cal-agenda__glyph" aria-hidden>●</span>
                                    <span><strong>{{ $entry['inHouse'] }}</strong> in-house</span>
                                </div>
                            @endif
                            @if (count($entry['overdue']) > 0)
                                <div class="lp-cal-agenda__row lp-cal-agenda__row--overdue">
                                    <span class="lp-cal-agenda__glyph" aria-hidden>!</span>
                                    <span>
                                        <strong>{{ count($entry['overdue']) }}</strong>
                                        overdue
                                    </span>
                                    <span class="lp-cal-agenda__names">
                                        @foreach (array_slice($entry['overdue'], 0, 3) as $o)
                                            @if ($o['booking_id'])
                                                <button type="button" class="lp-cal-agenda__chip"
                                                        x-on:click="$dispatch('booking-sheet-request-open', { id: {{ $o['booking_id'] }}, surname: {{ json_encode($o['surname']) }} });
                                                                    $wire.openBookingSheet({{ $o['booking_id'] }})">
                                                    {{ $o['surname'] }} <span class="mono">Rm {{ $o['room'] }}</span>
                                                </button>
                                            @endif
                                        @endforeach
                                    </span>
                                </div>
                            @endif
                            @if ($entry['blocked'] > 0)
                                <div class="lp-cal-agenda__row lp-cal-agenda__row--blocked">
                                    <span class="lp-cal-agenda__glyph" aria-hidden>×</span>
                                    <span><strong>{{ $entry['blocked'] }}</strong> closed for maintenance</span>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </section>

        <section class="lp-dash-card lp-cal-gantt-wrap" style="padding: 22px;">
            <div class="overflow-x-auto">
                <table data-testid="calendar-grid" class="lp-cal-matrix" data-calendar-bind>
                    <thead>
                        <tr>
                            <th style="text-align: left; font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">Room</th>
                            @foreach ($days as $day)
                                @php
                                    $isMonthStart = (int) $day->day === 1;
                                    $isToday = $day->isToday();
                                    // Saturday = 6, Sunday = 0. Carbon's dayOfWeek is 0-indexed
                                    // starting Sunday. Weekends get a subtle tint so the operator
                                    // sees the weekly rhythm at a glance without reading the day.
                                    $isWeekend = in_array($day->dayOfWeek, [0, 6], true);
                                    $weekdayLetter = substr($day->format('D'), 0, 1);
                                @endphp
                                <th data-testid="day-header-{{ $day->toDateString() }}"
                                    @if ($isMonthStart) data-month-start="true" @endif
                                    @if ($isToday) data-today="true" @endif
                                    @if ($isWeekend) data-weekend="true" @endif
                                    style="{{ $isMonthStart ? 'border-left: 1px solid var(--color-line);' : '' }}">
                                    <span class="lp-cal-header-dow" aria-hidden>{{ $weekdayLetter }}</span>
                                    @if ($isMonthStart)
                                        <span class="lp-cal-header-month lp-mono">{{ $day->format('M') }}</span>
                                    @endif
                                    <span class="lp-cal-header-day {{ $isToday ? 'is-today' : '' }}">
                                        {{ $day->day }}
                                    </span>
                                    @if ($isToday)
                                        {{-- Column-length vertical rule so the operator can
                                             sight "where's today" on a 30-day scroll without
                                             re-reading the header numbers. See .lp-cal-matrix
                                             td[data-today] rule for the row-level extension. --}}
                                        <span class="lp-cal-today-marker" aria-hidden="true"></span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grouped as $group)
                            {{-- Category header row, spans the day columns so
                                 the operator scans the calendar in chunks
                                 instead of one row per unit. --}}
                            <tr class="lp-cal-group-head"
                                data-testid="cal-group-{{ $group->category?->id ?? 'uncat' }}">
                                <th class="lp-cal-group-label">
                                    @if ($group->category)
                                        <a href="{{ route('tenant.room-categories.edit', $group->category) }}"
                                           wire:navigate class="lp-cal-group-name lp-display">{{ $group->name }}</a>
                                        @if ($group->type)
                                            <span class="lp-cal-group-type">{{ $group->type }}</span>
                                        @endif
                                    @else
                                        <span class="lp-cal-group-name lp-display">{{ $group->name }}</span>
                                    @endif
                                    <span class="lp-cal-group-count lp-mono">
                                        {{ $group->rooms->count() }} {{ Str::plural('room', $group->rooms->count()) }}
                                    </span>
                                </th>
                                <th colspan="{{ count($days) }}" class="lp-cal-group-spacer"></th>
                            </tr>

                            @foreach ($group->rooms as $room)
                                <tr class="lp-cal-room-row" data-testid="room-row-{{ $room->id }}">
                                    <th class="lp-cal-room-label">
                                        <a href="{{ route('tenant.rooms.calendar', $room) }}" wire:navigate class="lp-mono">
                                            {{ $room->room_number ?? '-' }}
                                        </a>
                                    </th>
                                    @foreach ($days as $day)
                                        @php
                                            $isoDate = $day->toDateString();
                                            $state = $matrix[$room->id][$isoDate] ?? null;
                                            $isPast = $isoDate < $todayStr;
                                            $isMonthStart = (int) $day->day === 1;
                                            $isToday = $isoDate === $todayStr;
                                            $isWeekend = in_array($day->dayOfWeek, [0, 6], true);
                                            $isReservable = $state === null && ! $isPast;
                                            $cellBookingId = $bookingByCell[$room->id][$isoDate] ?? null;
                                            $chip = $chipMeta[$room->id][$isoDate] ?? null;
                                            $cellTitle = match (true) {
                                                $isPast && $state === null    => 'Past day, booking closed',
                                                $isReservable                 => 'Click or drag to reserve',
                                                $state === 'booked'           => $chip ? ($chip['surname'].' · click to view reservation') : 'Booked',
                                                $state === 'pending'          => ($chip ? $chip['surname'].' · ' : '').'Pending, guest is mid-checkout',
                                                $state === 'checkedin'        => ($chip ? $chip['surname'].' · ' : '').'Guest checked in',
                                                $state === 'due_out'          => ($chip ? $chip['surname'].' · ' : '').'Due to check out today',
                                                $state === 'overdue'          => ($chip ? $chip['surname'].' · ' : '').'Overdue, guest past checkout',
                                                $state === 'blocked'          => 'Closed for maintenance',
                                                default                       => '',
                                            };
                                        @endphp
                                        <td data-testid="cell-{{ $room->id }}-{{ $isoDate }}"
                                            data-state="{{ $state ?? 'free' }}"
                                            data-room-id="{{ $room->id }}"
                                            data-day="{{ $day->day }}"
                                            data-date="{{ $isoDate }}"
                                            @if ($isPast) data-past="true" @endif
                                            @if ($isMonthStart) data-month-start="true" @endif
                                            @if ($isToday) data-today="true" @endif
                                            @if ($isWeekend) data-weekend="true" @endif
                                            @if ($isReservable) data-cell="reservable" @endif
                                            @if ($cellBookingId) data-booking-id="{{ $cellBookingId }}" @endif
                                            @if ($chip)
                                                data-chip-position="{{ $chip['position'] }}"
                                                data-chip-booking-id="{{ $chip['booking_id'] }}"
                                                data-chip-checkout="{{ $chip['checkout_iso'] ?? '' }}"
                                                data-chip-last-iso="{{ $chip['last_iso'] ?? '' }}"
                                                data-reassign-drag="true"
                                                data-reassign-booking-id="{{ $chip['booking_id'] }}"
                                                data-reassign-guest="{{ $chip['surname'] }}"
                                            @endif
                                            title="{{ $cellTitle }}"
                                            style="{{ $isMonthStart ? 'border-left: 1px solid var(--color-line);' : '' }}">
                                            @if ($chip && in_array($chip['position'], ['start', 'single'], true))
                                                {{-- Surname bleeds right into the continuation
                                                     cells (they share this booking's background),
                                                     producing a spanning-bar effect without a
                                                     colspan gymnastics + without breaking
                                                     per-day click targets. --}}
                                                <span class="lp-cal-chip-name" aria-label="{{ $chip['surname'] }}">{{ $chip['surname'] }}</span>
                                            @elseif ($state === 'blocked')
                                                {{-- Blocked cells have no booking + no surname,
                                                     keep a compact glyph so an operator glancing
                                                     the row still reads "closed here". --}}
                                                <span class="lp-cal-chip-glyph" aria-hidden="true">X</span>
                                            @endif
                                            @if ($chip && in_array($chip['position'], ['end', 'single'], true))
                                                {{-- Drag handle at the trailing edge of a booking
                                                     chip. Pointer-down + drag right previews the
                                                     extension; pointer-up hands the booking off to
                                                     HasExtendStayModal so the operator picks the
                                                     final date + payment terms in the existing
                                                     modal. Only rendered on `end` + `single`. --}}
                                                <span class="lp-cal-chip-extend-handle"
                                                      data-extend-handle="true"
                                                      data-booking-id="{{ $chip['booking_id'] }}"
                                                      data-room-id="{{ $room->id }}"
                                                      aria-label="Drag to extend {{ $chip['surname'] }}'s stay"
                                                      role="button"
                                                      tabindex="-1"></span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Editorial legend. Compact swatch + mono label pattern
                 so the operator scans the vocabulary as chips instead
                 of a paragraph of prose. --}}
            <ul data-testid="legend" class="lp-cal-legend">
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch" style="background: var(--color-accent-soft);"></span>
                    <span class="lp-cal-legend-label">Available</span>
                    <span class="lp-cal-legend-hint">click or drag to reserve</span>
                </li>
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch" style="background: var(--color-ink);"></span>
                    <span class="lp-cal-legend-label">Booked</span>
                    <span class="lp-cal-legend-hint">bar shows guest surname</span>
                </li>
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch" style="background: #2563EB;"></span>
                    <span class="lp-cal-legend-label">Checked in</span>
                </li>
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch" style="background: #F59E0B;"></span>
                    <span class="lp-cal-legend-label">Due out</span>
                    <span class="lp-cal-legend-hint">today</span>
                </li>
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch" style="background: #DC2626;"></span>
                    <span class="lp-cal-legend-label">Overdue</span>
                    <span class="lp-cal-legend-hint">past checkout</span>
                </li>
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch"
                          style="background: repeating-linear-gradient(45deg, #C99441, #C99441 3px, #B7822F 3px, #B7822F 6px);"></span>
                    <span class="lp-cal-legend-label">Pending</span>
                    <span class="lp-cal-legend-hint">mid-checkout</span>
                </li>
                <li class="lp-cal-legend-item">
                    <span aria-hidden class="lp-cal-legend-swatch" style="background: #DC2626;"></span>
                    <span class="lp-cal-legend-label">Blocked</span>
                    <span class="lp-cal-legend-hint">closed for maintenance</span>
                </li>
            </ul>
        </section>
    @endif

    @include('livewire.dashboard._partials.calendar-sheets')
    {{-- Shared extend-stay modal (from HasExtendStayModal trait) so
         the calendar booking-sheet can trigger an extension inline
         without navigating to the reservation detail page. --}}
    @include('livewire.dashboard._partials.extend-stay-modal')

    {{-- Post-create "Check in now?" prompt — same shape ManualCreate
         and the reservations page carry, wired to this component's
         postCreate* props + confirmPostCreateCheckIn / skip methods. --}}
    @include('livewire.dashboard._partials.checkin-prompt')

    {{-- Drag-to-reassign confirmation. Kept in the DOM at all times
         so Livewire's wire:loading directives bind once and don't
         need re-scanning after each teleport. Visibility is driven
         by Alpine entangled with $showReassignConfirm. --}}
    <template x-teleport="body">
    <div x-data="{ open: @entangle('showReassignConfirm') }"
         x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         @click.self="$wire.cancelReassignConfirm()"
         data-testid="reassign-confirm-backdrop"
         class="lp-modal-backdrop"
         style="z-index: 80;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);
                -webkit-backdrop-filter: blur(2px);">
        <div @click.stop
             data-testid="reassign-confirm-card"
             class="lp-page"
             style="width: 100%; max-width: 460px;
                    background: var(--color-card);
                    border-radius: 16px;
                    border: 1px solid var(--color-line-soft);
                    box-shadow: 0 24px 60px rgba(0,0,0,0.22), 0 2px 6px rgba(0,0,0,0.08);
                    padding: 28px 26px 22px;">
            <div style="display: flex; align-items: center; justify-content: center;
                        width: 44px; height: 44px; margin: 0 0 14px;
                        border-radius: 999px; background: var(--color-card-deep); color: var(--color-ink);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 8h13M14 4l4 4-4 4M20 16H7M10 20l-4-4 4-4"/>
                </svg>
            </div>
            <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin: 0;">
                Move {{ $reassignGuestSurname ?: 'guest' }} from
                room {{ $reassignFromRoomLabel ?: '-' }}
                to room {{ $reassignToRoomLabel ?: '-' }}?
            </h2>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.55;">
                {{ $reassignCheckinLabel ?: '-' }} to {{ $reassignCheckoutLabel ?: '-' }}
                @if ($reassignNights > 0)
                    @if ($reassignRemainingNights < $reassignNights)
                        &middot; {{ $reassignRemainingNights }} remaining
                        {{ $reassignRemainingNights === 1 ? 'night' : 'nights' }}
                        of {{ $reassignNights }}
                        @if ($reassignNights - $reassignRemainingNights > 0)
                            (already stayed {{ $reassignNights - $reassignRemainingNights }})
                        @endif
                    @else
                        &middot; {{ $reassignNights }} {{ $reassignNights === 1 ? 'night' : 'nights' }}
                    @endif
                @endif
            </p>

            @if ($reassignBlocker !== '')
                <div data-testid="reassign-confirm-blocker"
                     style="margin-top: 14px; padding: 10px 14px;
                            background: rgba(184, 92, 66, 0.08);
                            border: 1px solid rgba(184, 92, 66, 0.28);
                            border-radius: 12px;
                            color: #B85C42; font-size: 13px;">
                    {{ $reassignBlocker }}
                </div>
            @else
                <div data-testid="reassign-confirm-rate"
                     style="margin-top: 14px; padding: 12px 14px;
                            background: var(--color-card-deep);
                            border-radius: 12px; font-size: 13px;
                            color: var(--color-ink-soft); line-height: 1.55;">
                    @if ($reassignRateDelta === 0)
                        Same nightly rate ({{ '₦'.number_format($reassignOldRate) }}).
                        No reconciliation needed.
                    @else
                        @php
                            $perNightDelta = $reassignNewRate - $reassignOldRate;
                            $direction = $reassignRateDelta > 0 ? 'more' : 'less';
                            $sign = $reassignRateDelta > 0 ? '+' : '−';
                        @endphp
                        New room is
                        <strong style="color: var(--color-ink);">
                            {{ '₦'.number_format(abs($perNightDelta)) }}
                        </strong>
                        {{ $direction }} per night
                        ({{ '₦'.number_format($reassignOldRate) }} to {{ '₦'.number_format($reassignNewRate) }}).
                        Total delta across
                        {{ $reassignRemainingNights }}
                        {{ $reassignRemainingNights === 1 ? 'night' : 'nights' }}:
                        <strong style="color: var(--color-ink);">
                            {{ $sign.'₦'.number_format(abs($reassignRateDelta)) }}
                        </strong>.
                        @if ($reassignRateDelta > 0)
                            Collect the difference at the till.
                        @else
                            Refund or credit the overpayment on close-out.
                        @endif
                    @endif
                    @if ($reassignCrossCategory)
                        <div style="margin-top: 6px; color: var(--color-ink-mute); font-size: 12.5px;">
                            Cross-category swap, the booking's category is updated too.
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex gap-3 mt-6" style="justify-content: flex-end;">
                <button type="button"
                        wire:click="cancelReassignConfirm"
                        wire:loading.attr="disabled"
                        wire:target="cancelReassignConfirm,confirmReassignFromDrag"
                        data-testid="reassign-confirm-cancel"
                        style="background: var(--color-card-deep);
                               color: var(--color-ink);
                               border: 0; padding: 10px 20px;
                               border-radius: 9px;
                               font-family: inherit; font-size: 13px; font-weight: 500;
                               cursor: pointer;">
                    Cancel
                </button>
                <button type="button"
                        wire:click="confirmReassignFromDrag"
                        wire:loading.attr="disabled"
                        wire:target="confirmReassignFromDrag,cancelReassignConfirm"
                        data-testid="reassign-confirm-submit"
                        @if ($reassignBlocker !== '') disabled @endif
                        style="background: var(--color-accent);
                               color: var(--color-ink);
                               border: 0; padding: 10px 22px;
                               border-radius: 9px;
                               font-family: inherit; font-size: 13px; font-weight: 600;
                               cursor: pointer;
                               min-width: 148px;
                               {{ $reassignBlocker !== '' ? 'opacity: 0.5; cursor: not-allowed;' : '' }}
                               display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                    <span wire:loading.remove wire:target="confirmReassignFromDrag">
                        Move guest
                    </span>
                    <span wire:loading.inline-flex wire:target="confirmReassignFromDrag"
                          style="align-items: center; gap: 8px;">
                        <span aria-hidden style="width: 12px; height: 12px;
                                                 border: 2px solid rgba(20,22,24,0.35);
                                                 border-top-color: var(--color-ink);
                                                 border-radius: 999px;
                                                 animation: kv-reassign-spin 720ms linear infinite;"></span>
                        Moving…
                    </span>
                </button>
            </div>
        </div>
    </div>
    </template>
    <style>
        @@keyframes kv-reassign-spin { to { transform: rotate(360deg); } }
    </style>

    {{-- ── Drag-to-extend on booking chips ────────────────────────────
         Attaches pointerdown → pointermove → pointerup on any
         `[data-extend-handle]` inside a calendar grid. During a drag
         we highlight the free cells that would be reserved and, on
         release, hand off to HasExtendStayModal by calling the
         component's openExtendModal(bookingId) method. The modal owns
         the actual date pick + rate confirmation + payment step.
         Runs alongside the reservation-drag JS in
         livewire.dashboard._partials.calendar-sheets — the handle
         lives above the cell (z-index) so its pointer events don't
         reach the reservation-drag listener. --}}
    <script>
    (function () {
        /* ── Unified chip drag ───────────────────────────────────────
           One handler for the whole booking bar. On pointerup the
           drop target decides which action fires:

              same room + day > booking checkout   → extend to that date
                                                     (openExtendModal with
                                                     the pre-selected new
                                                     checkout ISO)
              different room                       → reassign to that room
                                                     (openReassignConfirm)
              same row + non-free day / no target  → falls through, the
                                                     booked-cell click
                                                     handler opens the
                                                     reservation sheet

           An 8px movement threshold separates "grab + drag" from a
           short tap so click-to-open still works. Real drags stop
           the tail click from firing so we don't accidentally open
           the sheet after committing an extend / reassign.
        */
        function bindChipDrag(root) {
            const grid = root.querySelector('[data-calendar-bind]');
            if (!grid || grid.dataset.kvChipDragBound === '1') return;
            grid.dataset.kvChipDragBound = '1';

            const wireEl = root.closest('[wire\\:id]') || root;
            const wireId = wireEl.getAttribute('wire:id');

            let armed = false;
            let dragging = false;
            let startX = 0, startY = 0;
            let bookingId = null;
            let originRoom = null;
            let chipLastIso = '';        // ISO of the LAST visible chip cell in this row
            let mode = 'idle';           // 'idle' | 'extend' | 'reassign'
            let overRow = null;
            let extendPreviewCells = [];
            let extendTargetIso = '';
            let justDragged = false;

            function reset() {
                armed = false;
                dragging = false;
                bookingId = null;
                originRoom = null;
                chipLastIso = '';
                mode = 'idle';
                clearRowHighlight();
                clearExtendPreview();
                extendTargetIso = '';
                document.body.removeAttribute('data-kv-reassigning');
                grid.removeAttribute('data-extending');
            }

            function clearRowHighlight() {
                if (overRow) {
                    overRow.removeAttribute('data-reassign-target');
                    overRow = null;
                }
            }

            function clearExtendPreview() {
                extendPreviewCells.forEach(c => { delete c.dataset.extendTarget; });
                extendPreviewCells = [];
            }

            function paintReassignRow(row) {
                if (row === overRow) return;
                clearRowHighlight();
                if (!row) return;
                const firstCell = row.querySelector('td[data-room-id]');
                const rowRoomId = firstCell ? firstCell.dataset.roomId : null;
                if (rowRoomId && rowRoomId === originRoom) return;
                row.setAttribute('data-reassign-target', 'true');
                overRow = row;
            }

            function paintExtendPreviewTo(targetCell) {
                clearExtendPreview();
                extendTargetIso = '';
                if (!targetCell) return;
                if (targetCell.dataset.roomId !== originRoom) return;

                const targetIso = targetCell.dataset.date;
                if (!targetIso || !chipLastIso) return;
                // Only paint when the drag target is strictly AFTER
                // the last chip cell. Landing on or before that means
                // the operator is dragging within/behind the chip.
                if (targetIso <= chipLastIso) return;

                // Walk-start = the cell right after the last chip cell
                // in this row. That's the first extendable night, and
                // it correctly handles overdue bookings whose recorded
                // checkout_date is off-window - the chip's trailing
                // edge is what the operator actually sees + drags from.
                const anchorCell = grid.querySelector(
                    `td[data-room-id="${originRoom}"][data-date="${chipLastIso}"]`
                );
                if (!anchorCell) return;
                let cursor = anchorCell.nextElementSibling;

                while (cursor && cursor.dataset && cursor.dataset.roomId === originRoom) {
                    if (cursor.dataset.state !== 'free') break;
                    cursor.dataset.extendTarget = 'true';
                    extendPreviewCells.push(cursor);
                    if (cursor.dataset.date === targetIso) break;
                    cursor = cursor.nextElementSibling;
                }
                // The extend action's "new checkout" is one day PAST
                // the last night we're adding, matching how the modal
                // interprets the datepicker value.
                if (extendPreviewCells.length > 0) {
                    const lastCell = extendPreviewCells[extendPreviewCells.length - 1];
                    const iso = lastCell.dataset.date;
                    extendTargetIso = addIsoDay(iso, 1);
                }
            }

            function addIsoDay(iso, n) {
                const d = new Date(iso + 'T00:00:00');
                if (isNaN(d.getTime())) return iso;
                d.setDate(d.getDate() + n);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${dd}`;
            }

            grid.addEventListener('pointerdown', (e) => {
                if (e.button !== 0) return;
                const chipCell = e.target.closest('td[data-reassign-drag="true"]');
                if (!chipCell) return;

                bookingId = parseInt(chipCell.dataset.reassignBookingId, 10);
                originRoom = chipCell.dataset.roomId;
                chipLastIso = chipCell.dataset.chipLastIso || '';
                if (!bookingId) return;
                armed = true;
                startX = e.clientX;
                startY = e.clientY;
            });

            grid.addEventListener('pointermove', (e) => {
                if (!armed) return;
                if (!dragging) {
                    const dx = e.clientX - startX;
                    const dy = e.clientY - startY;
                    if (dx * dx + dy * dy < 64) return; // 8px threshold
                    dragging = true;
                    document.body.setAttribute('data-kv-reassigning', 'true');
                }

                const overEl = document.elementFromPoint(e.clientX, e.clientY);
                const overCell = overEl?.closest('td[data-room-id][data-day]');
                const overRowEl = overEl?.closest('tr.lp-cal-room-row');

                // Same-room drag → try to preview an extend. If the
                // pointer is not past the checkout day, or the cells
                // aren't free, clearExtendPreview leaves mode='idle'
                // and the drop will be a no-op.
                if (overCell && overCell.dataset.roomId === originRoom) {
                    clearRowHighlight();
                    paintExtendPreviewTo(overCell);
                    mode = extendPreviewCells.length > 0 ? 'extend' : 'idle';
                    grid.dataset.extending = mode === 'extend' ? 'true' : '';
                    return;
                }

                // Different-room drag → reassign preview.
                grid.removeAttribute('data-extending');
                clearExtendPreview();
                paintReassignRow(overRowEl);
                mode = overRow ? 'reassign' : 'idle';
            });

            function commit() {
                if (!armed) return;
                const wasDrag = dragging;
                const finalMode = mode;
                const targetIso = extendTargetIso;
                const targetRow = overRow;
                const bId = bookingId;
                reset();

                if (!wasDrag) return; // short tap, let the click fire

                justDragged = true;
                setTimeout(() => { justDragged = false; }, 60);

                const cmp = window.Livewire?.find(wireId);
                if (!cmp) return;

                if (finalMode === 'extend' && targetIso) {
                    cmp.$call('openExtendModal', bId, targetIso);
                    return;
                }
                if (finalMode === 'reassign' && targetRow) {
                    const firstCell = targetRow.querySelector('td[data-room-id]');
                    const targetRoomId = firstCell ? parseInt(firstCell.dataset.roomId, 10) : null;
                    if (targetRoomId) cmp.$call('openReassignConfirm', bId, targetRoomId);
                }
                // else: no valid target, act as a no-op.
            }

            grid.addEventListener('pointerup', commit);
            grid.addEventListener('pointercancel', reset);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && (armed || dragging)) reset();
            });

            // Suppress the click that would otherwise fire the booked-
            // cell sheet-open right after a drag committed.
            grid.addEventListener('click', (e) => {
                if (!justDragged) return;
                e.stopPropagation();
                e.preventDefault();
            }, true);
        }

        function bindAll() {
            document.querySelectorAll('.lp-dash [data-calendar-bind]').forEach(g => {
                bindChipDrag(g.parentElement || document);
            });
        }

        document.addEventListener('livewire:init', bindAll);
        document.addEventListener('livewire:navigated', bindAll);
        if (window.Livewire) {
            window.Livewire.hook('morph.updated', bindAll);
        }
        bindAll();
    })();
    </script>
</div>
