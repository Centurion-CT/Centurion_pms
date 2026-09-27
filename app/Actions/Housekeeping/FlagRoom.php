<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Manager-only "put this room out of service" control. Creates a
 * fresh RoomInspection in the Failed state carrying the reason on
 * `summary_note`; MarkCheckedIn's inspection gate reads the LATEST
 * inspection so a fresh Failed row immediately blocks new check-ins
 * on the room until the flag is cleared (see {@see ClearRoomFlag}).
 */
class FlagRoom
{
    public function handle(Room $room, Operator $operator, string $reason): RoomInspection
    {
        if ($operator->role !== OperatorRole::Manager) {
            throw new RuntimeException('Only managers can flag a room out of service.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new RuntimeException('Add a reason so the flag is auditable.');
        }
        if (mb_strlen($trimmedReason) > 500) {
            throw new RuntimeException('Keep the reason under 500 characters.');
        }

        $now = Carbon::now();
        $inspection = RoomInspection::query()->create([
            'tenant_id' => $room->tenant_id,
            'room_id' => $room->id,
            'room_category_id' => $room->room_category_id,
            'status' => RoomInspectionStatus::Failed->value,
            'supervisor_at' => $now,
            'supervisor_by_operator_id' => $operator->id,
            'summary_note' => 'Manager flag: '.$trimmedReason,
        ]);

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event('room_flagged')
            ->withProperties([
                'reason' => $trimmedReason,
                'room_id' => $room->id,
                'room_number' => $room->room_number,
            ])
            ->log(sprintf(
                'Manager %s flagged room %s out of service — reason: %s',
                $operator->name,
                $room->room_number,
                $trimmedReason,
            ));

        return $inspection;
    }
}
