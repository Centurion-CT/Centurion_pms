<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Room;
use RuntimeException;

/**
 * Supervisor / manager manual override of a room's housekeeping chip
 * (Mark clean / Mark dirty on the Housekeeping board). The auto-flow
 * (checkout -> Dirty, inspection lifecycle) is untouched.
 *
 * Shared by the web Housekeeping board and the host app
 * (POST host/rooms/{id}/status) so both apply the same rule.
 */
class SetRoomHousekeepingStatus
{
    /** Roles allowed to flip the chip by hand (web: isManagerOrSupervisor). */
    public static function canChange(?Operator $operator): bool
    {
        return $operator instanceof Operator
            && in_array($operator->role, [OperatorRole::Manager, OperatorRole::Supervisor], true);
    }

    public function handle(Room $room, ?Operator $operator, HousekeepingStatus $status): Room
    {
        if (! self::canChange($operator)) {
            throw new RuntimeException('Only managers and supervisors can change a room status.');
        }

        $label = $status->label();
        $room->forceFill(['housekeeping_status' => $status->value])->save();

        activity('housekeeping_assignment')
            ->on($room)
            ->causedBy($operator)
            ->withProperties(['status' => $status->value])
            ->log("Rm {$room->room_number} marked {$label}.");

        return $room;
    }
}
