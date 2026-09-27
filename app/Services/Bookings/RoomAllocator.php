<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\Availability\AvailabilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Picks a specific physical Room for a category-level booking.
 *
 * Phase 3 introduces the "book any Deluxe — system picks the unit"
 * flow on the public side. The Livewire CategoryDetail component
 * never lets the guest pick "Room 105" directly; it calls
 * {@see RoomAllocator::allocate()} just before {@see CreateBooking}
 * to resolve a concrete room. Calendar + reservations stay per-room
 * (front desk operates on physical units).
 *
 * Allocation strategy: lowest `room_number` ASC that is currently
 * free for [checkin, checkout). Stable + predictable; an operator
 * can always look at the calendar and know which unit will be
 * picked next for a given category.
 *
 * Race safety: this method is best-effort. Between the allocator's
 * `isAvailable()` check and the {@see CreateBooking} transaction,
 * a concurrent booking could take the picked room. CreateBooking's
 * unique-night constraint will then refuse the commit, and the
 * caller is expected to retry by re-allocating. The retry costs
 * one extra `isAvailable()` scan; given Naira-rate booking volume,
 * that's fine.
 */
class RoomAllocator
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Returns the first available room in the category, or null when
     * every active unit in the category is booked/blocked for the
     * range.
     */
    public function allocate(RoomCategory $category, Carbon $checkin, Carbon $checkout): ?Room
    {
        $rooms = $category->rooms()
            ->where('status', RoomStatus::Active->value)
            ->orderBy('room_number')
            ->get();

        foreach ($rooms as $room) {
            if ($this->availability->isAvailable($room, $checkin, $checkout)) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Counts active rooms in the category that are free for the range.
     * Drives the "X of Y available" badge on the browse + detail pages.
     */
    public function countAvailable(RoomCategory $category, Carbon $checkin, Carbon $checkout): int
    {
        $rooms = $category->rooms()
            ->where('status', RoomStatus::Active->value)
            ->get();

        $count = 0;
        foreach ($rooms as $room) {
            if ($this->availability->isAvailable($room, $checkin, $checkout)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Total active room count in this category — denominator of the
     * "X of Y available" badge. Constant for the call, so we cache
     * it implicitly via the same loop above when the caller wants
     * both numerator + denominator.
     */
    public function activeCount(RoomCategory $category): int
    {
        return $category->rooms()
            ->where('status', RoomStatus::Active->value)
            ->count();
    }

    /**
     * All active rooms in the category that are free for the range,
     * optionally excluding a specific room id. Used by the reassign
     * UI on the reservations console — the operator sees a dropdown
     * of swap candidates (every active room in the same category
     * that's free for the booking's existing date range, minus the
     * booking's current room).
     *
     * @return Collection<int, Room>
     */
    public function availableRooms(
        RoomCategory $category,
        Carbon $checkin,
        Carbon $checkout,
        ?int $excludeRoomId = null,
    ): Collection {
        $query = $category->rooms()
            ->where('status', RoomStatus::Active->value)
            ->orderBy('room_number');

        if ($excludeRoomId !== null) {
            $query->where('id', '!=', $excludeRoomId);
        }

        return $query->get()->filter(
            fn (Room $room): bool => $this->availability->isAvailable($room, $checkin, $checkout)
        )->values();
    }

    /**
     * Every active room across all categories in a property that is
     * free for the given date range. Used by cross-category room
     * reassignment (front desk needs to see every free room, not just
     * the guest's originally-booked tier).
     *
     * @return Collection<int, Room>
     */
    public function availableRoomsInProperty(
        int $propertyId,
        Carbon $checkin,
        Carbon $checkout,
        ?int $excludeRoomId = null,
    ): Collection {
        $query = Room::query()
            ->where('property_id', $propertyId)
            ->where('status', RoomStatus::Active->value)
            ->with('category')
            ->orderBy('room_category_id')
            ->orderBy('room_number');

        if ($excludeRoomId !== null) {
            $query->where('id', '!=', $excludeRoomId);
        }

        return $query->get()->filter(
            fn (Room $room): bool => $this->availability->isAvailable($room, $checkin, $checkout)
        )->values();
    }
}
