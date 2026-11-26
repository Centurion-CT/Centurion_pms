{{-- Quick-search strip shared between the dashboard heatmap and the
     property calendar. Filters which rooms render in the grid below.

     Uses the HasCalendarSearch trait's URL-bound state so the search
     survives page reloads and can be shared as a link. Live-bound
     inputs so the operator sees the grid narrow as they type / tab. --}}

@props(['visibleCount' => null, 'totalCount' => null])

@php
    $active = $this->calendarSearchActive();
    $status = ($visibleCount !== null && $totalCount !== null)
        ? $this->calendarSearchRoomStatus((int) $visibleCount, (int) $totalCount)
        : null;
@endphp

<section data-testid="calendar-search"
         class="lp-cal-search">
    <div class="lp-cal-search-row">
        {{-- Room # --}}
        <div class="lp-cal-search-field lp-cal-search-field--room">
            <label for="cal-room-search" class="lp-rate-sub-label">Room</label>
            <input id="cal-room-search"
                   type="text"
                   wire:model.live.debounce.300ms="roomSearch"
                   placeholder="e.g. 101"
                   data-testid="cal-room-search"
                   class="lp-input"
                   >
        </div>

        {{-- Available window --}}
        <div class="lp-cal-search-field">
            <label for="cal-avail-from" class="lp-rate-sub-label">Free from</label>
            <input id="cal-avail-from"
                   type="date"
                   wire:model.live="availFrom"
                   data-testid="cal-avail-from"
                   class="lp-input"
                   >
        </div>
        <div class="lp-cal-search-field">
            <label for="cal-avail-to" class="lp-rate-sub-label">to</label>
            <input id="cal-avail-to"
                   type="date"
                   wire:model.live="availTo"
                   data-testid="cal-avail-to"
                   class="lp-input"
                   >
        </div>

        {{-- Only available as slide toggle to match the design system. --}}
        <label class="lp-rate-toggle lp-cal-search-toggle" style="cursor: pointer;">
            <input type="checkbox"
                   wire:model.live="onlyAvailable"
                   data-testid="cal-only-available">
            <span class="lp-rate-toggle-track" aria-hidden></span>
            <span class="lp-rate-toggle-label">Only available</span>
        </label>

        {{-- Clear pill, only rendered when a filter is active so the
             strip stays quiet when nothing's set. --}}
        @if ($active)
            <button type="button"
                    wire:click="clearCalendarSearch"
                    data-testid="cal-clear"
                    class="lp-res-action lp-res-action--ghost"
                    >
                Clear
            </button>
        @endif
    </div>

    @if ($status !== null)
        <p class="lp-mono text-[11px]"
           data-testid="cal-search-status"
           style="color: var(--color-ink-mute); margin: 8px 0 0;">
            {{ $status }}
        </p>
    @endif
</section>
