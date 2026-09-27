<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Shared quick-search state for the two calendar surfaces (dashboard
 * Home heatmap + Calendar\PropertyView monthly grid). Both filter
 * "which rooms are visible" the same way:
 *
 *   - roomSearch     , case-insensitive substring match on room_number
 *   - availFrom/availTo + onlyAvailable, hide rows that have any block
 *                        (Booked / Blocked) touching [availFrom, availTo)
 *
 * State is URL-bound so a filter view is shareable / bookmarkable.
 * Both dates default to tonight → tomorrow on first paint via
 * initCalendarSearch(), matching the most common front-desk lookup
 * ("what's free for a walk-in tonight?").
 */
trait HasCalendarSearch
{
    #[Url(as: 'q')]
    public string $roomSearch = '';

    #[Url(as: 'af')]
    public string $availFrom = '';

    #[Url(as: 'at')]
    public string $availTo = '';

    #[Url(as: 'oa')]
    public bool $onlyAvailable = false;

    /**
     * Seed the date pickers on first paint. Call from mount() in the
     * consuming component AFTER Livewire has hydrated URL state, so
     * bookmarked links win over defaults.
     */
    public function initCalendarSearch(): void
    {
        if ($this->availFrom === '') {
            $this->availFrom = Carbon::today()->toDateString();
        }
        if ($this->availTo === '') {
            $this->availTo = Carbon::today()->addDay()->toDateString();
        }
    }

    public function clearCalendarSearch(): void
    {
        $this->roomSearch = '';
        $this->availFrom = Carbon::today()->toDateString();
        $this->availTo = Carbon::today()->addDay()->toDateString();
        $this->onlyAvailable = false;
    }

    /**
     * Whether ANY filter is currently narrowing the visible rooms.
     * Drives the "Showing X of Y rooms" hint + Clear-pill visibility.
     */
    public function calendarSearchActive(): bool
    {
        return trim($this->roomSearch) !== '' || $this->onlyAvailable;
    }

    /**
     * Return the set of room ids that pass the current filters.
     *
     * @param  Collection<int, Room>  $rooms  the candidate rooms already
     *                                        scoped to property + Active
     * @return array<int, true>  keyed for O(1) contains checks
     */
    public function calendarSearchAllowedRoomIds(Collection $rooms): array
    {
        if (! $this->calendarSearchActive()) {
            return array_fill_keys($rooms->pluck('id')->all(), true);
        }

        $needle = trim(mb_strtolower($this->roomSearch));

        // Step 1, room-number substring filter.
        $numberMatched = $needle === ''
            ? $rooms
            : $rooms->filter(fn (Room $r) => str_contains(
                mb_strtolower((string) $r->room_number),
                $needle,
            ));

        // Step 2, "only available" hides any room with a block that
        // touches [availFrom, availTo). One query for the whole set.
        if ($this->onlyAvailable
            && $this->availFrom !== '' && $this->availTo !== ''
        ) {
            try {
                $from = Carbon::parse($this->availFrom)->startOfDay();
                $to = Carbon::parse($this->availTo)->startOfDay();
            } catch (\Throwable) {
                return array_fill_keys($numberMatched->pluck('id')->all(), true);
            }
            if ($to->lessThanOrEqualTo($from)) {
                // Zero-night range, treat as no availability filter,
                // just return the room-number matches.
                return array_fill_keys($numberMatched->pluck('id')->all(), true);
            }

            $tenantId = (int) Tenant::current()?->id;
            $conflictRoomIds = AvailabilityBlock::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('room_id', $numberMatched->pluck('id')->all())
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<', $to->toDateString())
                ->pluck('room_id')
                ->unique()
                ->all();
            $conflictSet = array_fill_keys($conflictRoomIds, true);

            $numberMatched = $numberMatched->reject(
                fn (Room $r) => isset($conflictSet[$r->id]),
            );
        }

        return array_fill_keys($numberMatched->pluck('id')->all(), true);
    }

    /**
     * Convenience count for the "Showing 8 of 24 rooms" hint. Cheap -
     * caller has already computed the allowed set.
     */
    public function calendarSearchRoomStatus(int $shown, int $total): ?string
    {
        if (! $this->calendarSearchActive() || $shown === $total) {
            return null;
        }

        return "Showing {$shown} of {$total} rooms";
    }

    /**
     * Marker so a consuming component can call `RoomStatus::Active` in
     * its own render() without importing separately. Purely a symbol
     * reference to keep static analysers happy.
     */
    private function roomStatusActive(): string
    {
        return RoomStatus::Active->value;
    }
}
