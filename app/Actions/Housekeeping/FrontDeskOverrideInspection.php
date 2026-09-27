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
 * Front-desk safety valve: pass the open housekeeping inspection so
 * the room is immediately eligible for check-in, and log who did it.
 *
 * The normal chain is housekeeper → porter → supervisor. In the
 * field, the front desk sometimes has to do that work themselves —
 * the housekeeper is off shift, the porter didn't show, whatever.
 * Rather than teach the whole system to accept "no inspection
 * needed," we let front-desk staff pass the inspection with an
 * explicit override event and a required reason, so the audit trail
 * makes clear the front desk (not housekeeping) signed off.
 *
 * Stamps every sign-off slot (housekeeper/porter/supervisor) with
 * the overriding operator so downstream reports still have someone
 * to attribute the room's readiness to.
 */
class FrontDeskOverrideInspection
{
    /**
     * Pass the current open inspection for `$room`. Returns the
     * inspection row, or throws if the room already cleared
     * housekeeping (nothing to override) or the operator's role
     * isn't allowed to run this override.
     */
    public function handle(Room $room, Operator $operator, string $reason): RoomInspection
    {
        if (! in_array($operator->role, [OperatorRole::Frontdesk, OperatorRole::Manager], true)) {
            throw new RuntimeException('Only front-desk staff or managers can override the housekeeping chain.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new RuntimeException('Add a reason so the override is auditable.');
        }

        $inspection = RoomInspection::query()
            ->where('room_id', $room->id)
            ->whereNotIn('status', [
                RoomInspectionStatus::Passed->value,
                RoomInspectionStatus::Failed->value,
            ])
            ->latest('id')
            ->first();

        if ($inspection === null) {
            throw new RuntimeException('This room has no open inspection — nothing to override.');
        }

        $now = Carbon::now();
        $previousStatus = $inspection->status;

        // Fill every sign-off timestamp/operator column with the
        // overriding operator + now so the room reads as fully
        // cleared. summary_note carries the reason so the audit
        // trail is visible on the inspection detail page.
        $inspection->forceFill([
            'status' => RoomInspectionStatus::Passed,
            'housekeeper_at' => $inspection->housekeeper_at ?? $now,
            'housekeeper_by_operator_id' => $inspection->housekeeper_by_operator_id ?? $operator->id,
            'porter_at' => $inspection->porter_at ?? $now,
            'porter_by_operator_id' => $inspection->porter_by_operator_id ?? $operator->id,
            'supervisor_at' => $now,
            'supervisor_by_operator_id' => $operator->id,
            'summary_note' => 'Front-desk override: '.$trimmedReason,
        ])->save();

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event('frontdesk_override')
            ->withProperties([
                'reason' => $trimmedReason,
                'previous_status' => $previousStatus->value,
                'room_id' => $room->id,
                'room_number' => $room->room_number,
            ])
            ->log(sprintf(
                'Front desk %s marked room %s clean (override from %s) — reason: %s',
                $operator->name,
                $room->room_number,
                $previousStatus->value,
                $trimmedReason,
            ));

        return $inspection->refresh();
    }
}
