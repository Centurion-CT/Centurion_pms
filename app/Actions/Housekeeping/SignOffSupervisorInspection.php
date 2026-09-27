<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Operator;
use App\Models\RoomInspection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Supervisor gives the final sign-off. Advances PorterVerified →
 * Passed, which unlocks check-in for the room. Supervisor can also
 * reject → Failed.
 */
class SignOffSupervisorInspection
{
    public function handle(RoomInspection $inspection, Operator $operator, bool $accept = true, ?string $reason = null): RoomInspection
    {
        if (! in_array($operator->role, [OperatorRole::Supervisor, OperatorRole::Manager], true)) {
            throw new RuntimeException('Only supervisors or managers can sign off at this stage.');
        }
        if ($inspection->status !== RoomInspectionStatus::PorterVerified) {
            throw new RuntimeException(sprintf(
                'Inspection isn\'t awaiting supervisor sign-off (currently %s).',
                $inspection->status->value,
            ));
        }

        $newStatus = $accept ? RoomInspectionStatus::Passed : RoomInspectionStatus::Failed;

        $inspection->forceFill([
            'status' => $newStatus,
            'supervisor_at' => Carbon::now(),
            'supervisor_by_operator_id' => $operator->id,
            'summary_note' => $reason ?: $inspection->summary_note,
        ])->save();

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event($accept ? 'supervisor_passed' : 'supervisor_rejected')
            ->log(sprintf(
                'Supervisor %s %s inspection #%d.',
                $operator->name,
                $accept ? 'passed' : 'rejected',
                $inspection->id,
            ));

        return $inspection->refresh();
    }
}
