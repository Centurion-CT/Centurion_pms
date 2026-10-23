<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Assign cleaner" on the Housekeeping board: make sure the room has
 * an open inspection (reusing {@see OpenRoomInspection} so bundle
 * snapshotting behaves like the checkout auto-flow), stamp the cleaner
 * on it and flip the room to Dirty.
 *
 * Shared by the web Housekeeping board and the host app
 * (POST host/rooms/{id}/assign-cleaner).
 */
class AssignRoomCleaner
{
    /** Roles shown in the assign-cleaner roster (web modal list). */
    public const ROSTER_ROLES = [
        OperatorRole::HouseCleaner,
        OperatorRole::Porter,
        OperatorRole::Supervisor,
        OperatorRole::Manager,
    ];

    /** Inspection states that count as "in flight" for a room. */
    public const OPEN_STATUSES = [
        RoomInspectionStatus::Pending,
        RoomInspectionStatus::CleaningDone,
    ];

    public function __construct(private readonly OpenRoomInspection $open) {}

    /**
     * Operators who can be assigned a room (tenant scoped by the
     * model's global scope), ordered by name.
     *
     * @return Builder<Operator>
     */
    public static function rosterQuery(): Builder
    {
        return Operator::query()
            ->whereIn('role', array_map(fn (OperatorRole $r) => $r->value, self::ROSTER_ROLES))
            ->orderBy('name');
    }

    public static function isRosterRole(Operator $operator): bool
    {
        return in_array($operator->role, self::ROSTER_ROLES, true);
    }

    public function handle(Room $room, Operator $cleaner, ?Operator $assignedBy): RoomInspection
    {
        // Only one inspection should be in flight per room; the open
        // action is idempotent against re-opens.
        $inspection = RoomInspection::query()
            ->where('room_id', $room->id)
            ->whereIn('status', array_map(fn (RoomInspectionStatus $s) => $s->value, self::OPEN_STATUSES))
            ->orderByDesc('id')
            ->first();

        if ($inspection === null) {
            $inspection = $this->open->handle($room);
        }

        $inspection->forceFill([
            'housekeeper_by_operator_id' => $cleaner->id,
        ])->save();

        // Assigning implies the room needs a clean.
        if ($room->housekeeping_status !== HousekeepingStatus::Dirty) {
            $room->forceFill(['housekeeping_status' => HousekeepingStatus::Dirty->value])->save();
        }

        activity('housekeeping_assignment')
            ->on($inspection)
            ->causedBy($assignedBy)
            ->withProperties(['room_id' => $room->id, 'cleaner_id' => $cleaner->id])
            ->log("Assigned {$cleaner->name} to clean Rm {$room->room_number}.");

        return $inspection;
    }
}
