<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Enums\AvailabilityBlockType;
use App\Models\AvailabilityBlock;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Authoritative availability oracle (PRD §6.2.3 / §11).
 *
 * Interval convention: HALF-OPEN [checkin, checkout). The checkout date is
 * NOT occupied by the departing booking — that's industry standard hospitality
 * pricing (you pay for nights, not days) and it makes back-to-back bookings
 * trivial: guest A checks out morning of the 5th, guest B checks in afternoon
 * of the 5th, both bookings hold the room without colliding.
 *
 * Persistence: one row per occupied night in `availability_blocks`. The unique
 * (tenant_id, room_id, date) constraint is the single source of truth that
 * makes double-booking physically impossible — a concurrent INSERT that races
 * past `isAvailable()` will hit the unique violation and the surrounding
 * transaction rolls back.
 */
class AvailabilityService
{
    /**
     * Is the room free for the entire half-open range [checkin, checkout)?
     */
    public function isAvailable(Room $room, Carbon $checkin, Carbon $checkout): bool
    {
        if ($checkout->lessThanOrEqualTo($checkin)) {
            return false;
        }

        return ! AvailabilityBlock::query()
            ->where('room_id', $room->id)
            ->whereDate('date', '>=', $checkin->toDateString())
            ->whereDate('date', '<', $checkout->toDateString())
            ->exists();
    }

    /**
     * Calendar-friendly view of which dates inside a given month carry blocks.
     *
     * @return Collection<int, AvailabilityBlock>
     */
    public function getBlocksForMonth(Room $room, Carbon $monthAnchor): Collection
    {
        return AvailabilityBlock::query()
            ->where('room_id', $room->id)
            // whereDate() extracts the date part on both sides — safe whether
            // the column is stored as DATE (MySQL) or TEXT 'Y-m-d H:i:s' (SQLite).
            ->whereDate('date', '>=', $monthAnchor->copy()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $monthAnchor->copy()->endOfMonth()->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Same shape as {@see getBlocksForMonth} but accepts an arbitrary
     * date range — used by the rolling-window calendar that spans the
     * boundary between two months.
     *
     * @return Collection<int, AvailabilityBlock>
     */
    public function getBlocksForRange(Room $room, Carbon $from, Carbon $to): Collection
    {
        return AvailabilityBlock::query()
            ->where('room_id', $room->id)
            ->whereDate('date', '>=', $from->copy()->startOfDay()->toDateString())
            ->whereDate('date', '<=', $to->copy()->startOfDay()->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Insert one Blocked row per night in [checkin, checkout). Throws if the
     * range collides with existing blocks (booked OR blocked).
     *
     * The whole batch runs in a transaction so partial inserts never persist.
     */
    public function blockRange(Room $room, Carbon $checkin, Carbon $checkout, ?string $reason = null): void
    {
        $this->upsertRange($room, $checkin, $checkout, AvailabilityBlockType::Blocked, $reason, null);
    }

    /**
     * Used by the booking engine (§11). Same insert path as blockRange, but
     * stamps type=booked and the originating booking_id for audit.
     */
    public function reserveRangeForBooking(
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        int $bookingId,
    ): void {
        $this->upsertRange($room, $checkin, $checkout, AvailabilityBlockType::Booked, null, $bookingId);
    }

    /**
     * Remove operator-created Blocked rows inside [checkin, checkout). Booked
     * rows are explicitly preserved — only the booking-cancellation path
     * (§11) should release reserved nights.
     */
    public function unblockRange(Room $room, Carbon $checkin, Carbon $checkout): int
    {
        return AvailabilityBlock::query()
            ->where('room_id', $room->id)
            ->where('type', AvailabilityBlockType::Blocked->value)
            ->whereDate('date', '>=', $checkin->toDateString())
            ->whereDate('date', '<', $checkout->toDateString())
            ->delete();
    }

    /**
     * Category-level availability check. Returns the smallest number of
     * FREE units across every night in [checkin, checkout) for the given
     * category - i.e., how many more bookings we can accept without
     * overselling on any single date.
     *
     * A "free unit" on a given date = (rooms in the category that are
     * Active) minus (rooms whose room_id-blocks land on that date)
     * minus (category-level blocks - room_id NULL, category_id set -
     * for that date). Category-level bookings occupy a slot without
     * ever pinning a specific room.
     */
    public function categoryFreeUnits(
        \App\Models\RoomCategory $category,
        Carbon $checkin,
        Carbon $checkout,
    ): int {
        if ($checkout->lessThanOrEqualTo($checkin)) return 0;

        // Active rooms in this category = the "total units" ceiling.
        $totalUnits = (int) \App\Models\Room::query()
            ->where('room_category_id', $category->id)
            ->where('status', \App\Enums\RoomStatus::Active->value)
            ->count();
        if ($totalUnits === 0) return 0;

        // Walk the requested range one night at a time; on each night
        // sum specific-room + category-level blocks + compare to
        // totalUnits. Return the smallest surplus we saw.
        $minFree = $totalUnits;
        $cursor = $checkin->copy()->startOfDay();
        $end = $checkout->copy()->startOfDay();
        while ($cursor->lessThan($end)) {
            $blockedSpecific = (int) AvailabilityBlock::query()
                ->whereNotNull('room_id')
                ->whereIn('room_id', function ($q) use ($category): void {
                    $q->select('id')
                        ->from('rooms')
                        ->where('room_category_id', $category->id);
                })
                ->whereDate('date', $cursor->toDateString())
                ->count();

            $blockedCategory = (int) AvailabilityBlock::query()
                ->whereNull('room_id')
                ->where('room_category_id', $category->id)
                ->whereDate('date', $cursor->toDateString())
                ->count();

            $freeThisNight = max(0, $totalUnits - $blockedSpecific - $blockedCategory);
            if ($freeThisNight < $minFree) $minFree = $freeThisNight;

            $cursor->addDay();
        }

        return $minFree;
    }

    /**
     * Same shape as reserveRangeForBooking but for a category-level
     * hold - inserts one row per night with room_id NULL +
     * room_category_id set. Guarded by categoryFreeUnits() inside a
     * transaction; the caller (CreateBooking's category path) locks
     * the category row FOR UPDATE before calling in so a concurrent
     * category-level booking can't sneak past the count.
     */
    public function reserveCategoryRangeForBooking(
        \App\Models\RoomCategory $category,
        Carbon $checkin,
        Carbon $checkout,
        int $bookingId,
    ): void {
        if ($checkout->lessThanOrEqualTo($checkin)) {
            throw new InvalidArgumentException('checkout must be at least one day after checkin');
        }
        $rows = [];
        $cursor = $checkin->copy()->startOfDay();
        $end = $checkout->copy()->startOfDay();
        $now = Carbon::now();
        while ($cursor->lessThan($end)) {
            $rows[] = [
                'tenant_id' => $category->tenant_id,
                'room_id' => null,
                'room_category_id' => $category->id,
                'date' => $cursor->format('Y-m-d H:i:s'),
                'type' => AvailabilityBlockType::Booked->value,
                'reason' => null,
                'booking_id' => $bookingId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $cursor->addDay();
        }
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                AvailabilityBlock::query()->insert($row);
            }
        });
    }

    /**
     * When the front desk assigns a real room to a category-level
     * booking at check-in, we swap every category-level block on
     * that booking into a specific-room block on the picked room.
     * Fails cleanly if the target room is already booked for any
     * night in the range - caller (AssignRoomToBooking action) then
     * surfaces "that room is taken for those dates".
     */
    public function reassignCategoryBookingToRoom(
        int $bookingId,
        Room $room,
    ): void {
        DB::transaction(function () use ($bookingId, $room): void {
            $blocks = AvailabilityBlock::query()
                ->where('booking_id', $bookingId)
                ->whereNull('room_id')
                ->get();
            if ($blocks->isEmpty()) {
                throw new InvalidArgumentException('No category-level blocks for that booking.');
            }
            foreach ($blocks as $block) {
                // Check the target room is free for this night.
                $conflict = AvailabilityBlock::query()
                    ->where('room_id', $room->id)
                    ->whereDate('date', $block->date->toDateString())
                    ->exists();
                if ($conflict) {
                    throw new RangeNotAvailableException(
                        "Room {$room->room_number} is already booked on {$block->date->toDateString()}."
                    );
                }
                $block->forceFill([
                    'room_id' => $room->id,
                    'room_category_id' => $room->room_category_id,
                ])->save();
            }
        });
    }

    private function upsertRange(
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        AvailabilityBlockType $type,
        ?string $reason,
        ?int $bookingId,
    ): void {
        if ($checkout->lessThanOrEqualTo($checkin)) {
            throw new InvalidArgumentException('checkout must be at least one day after checkin');
        }

        $rows = [];
        $cursor = $checkin->copy()->startOfDay();
        $end = $checkout->copy()->startOfDay();
        $now = Carbon::now();

        while ($cursor->lessThan($end)) {
            $rows[] = [
                'tenant_id' => $room->tenant_id,
                'room_id' => $room->id,
                // 'Y-m-d H:i:s' matches Eloquent's default date serialization.
                // MySQL/Postgres DATE columns truncate the time; SQLite stores
                // DATE as TEXT, so the consistent format is what makes the
                // (tenant_id, room_id, date) unique constraint actually trip
                // for rows written via two code paths (factory save vs raw insert).
                'date' => $cursor->format('Y-m-d H:i:s'),
                'type' => $type->value,
                'reason' => $reason,
                'booking_id' => $bookingId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $cursor->addDay();
        }

        DB::transaction(function () use ($rows): void {
            try {
                // Per-row inserts (rather than a single batch) give deterministic
                // unique-violation behaviour across SQLite + MySQL + Postgres.
                // The surrounding transaction rolls back any partial nights.
                foreach ($rows as $row) {
                    AvailabilityBlock::query()->insert($row);
                }
            } catch (QueryException $e) {
                throw new RangeNotAvailableException(
                    'One or more nights in the requested range are already booked or blocked.',
                    previous: $e,
                );
            }
        });
    }
}
