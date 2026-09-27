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
 * Manager-only "the flag is resolved, put this room back in service"
 * control. Creates a fresh Passed RoomInspection so the check-in gate
 * (which reads the latest inspection only) stops blocking. The flag
 * itself stays in history — clearing writes a new row rather than
 * mutating the Failed one, so the audit trail preserves both events.
 *
 * Refuses when the room isn't currently flagged so a stray click
 * can't accidentally overwrite an in-flight housekeeping inspection
 * with a fake Passed row.
 */
class ClearRoomFlag
{
    public function handle(Room $room, Operator $operator, ?string $note = null): RoomInspection
    {
        if ($operator->role !== OperatorRole::Manager) {
            throw new RuntimeException('Only managers can clear a room flag.');
        }

        $latest = RoomInspection::query()
            ->where('room_id', $room->id)
            ->latest('id')
            ->first();

        if ($latest === null || $latest->status !== RoomInspectionStatus::Failed) {
            throw new RuntimeException('This room is not currently flagged.');
        }

        $now = Carbon::now();
        $trimmedNote = trim((string) $note);
        $summary = $trimmedNote !== ''
            ? 'Manager cleared flag: '.$trimmedNote
            : 'Manager cleared flag.';

        $inspection = RoomInspection::query()->create([
            'tenant_id' => $room->tenant_id,
            'room_id' => $room->id,
            'room_category_id' => $room->room_category_id,
            'status' => RoomInspectionStatus::Passed->value,
            'housekeeper_at' => $now,
            'housekeeper_by_operator_id' => $operator->id,
            'porter_at' => $now,
            'porter_by_operator_id' => $operator->id,
            'supervisor_at' => $now,
            'supervisor_by_operator_id' => $operator->id,
            'readiness_score' => 100,
            'summary_note' => $summary,
        ]);

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event('room_flag_cleared')
            ->withProperties([
                'note' => $trimmedNote !== '' ? $trimmedNote : null,
                'previous_flag_inspection_id' => $latest->id,
                'room_id' => $room->id,
                'room_number' => $room->room_number,
            ])
            ->log(sprintf(
                'Manager %s cleared the flag on room %s.',
                $operator->name,
                $room->room_number,
            ));

        return $inspection;
    }
}
