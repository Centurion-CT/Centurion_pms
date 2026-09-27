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
 * Porter verifies the housekeeper's pass. Advances CleaningDone →
 * PorterVerified. Porter can reject → status flips to Failed and a
 * supervisor / manager has to open a fresh inspection.
 */
class VerifyPorterInspection
{
    public function handle(RoomInspection $inspection, Operator $operator, bool $accept = true, ?string $reason = null): RoomInspection
    {
        if (! in_array($operator->role, [OperatorRole::Porter, OperatorRole::Supervisor, OperatorRole::Manager], true)) {
            throw new RuntimeException('Only porters, supervisors, or managers can verify at this stage.');
        }
        if ($inspection->status !== RoomInspectionStatus::CleaningDone) {
            throw new RuntimeException(sprintf(
                'Inspection isn\'t awaiting porter (currently %s).',
                $inspection->status->value,
            ));
        }

        $newStatus = $accept ? RoomInspectionStatus::PorterVerified : RoomInspectionStatus::Failed;

        $inspection->forceFill([
            'status' => $newStatus,
            'porter_at' => Carbon::now(),
            'porter_by_operator_id' => $operator->id,
            'summary_note' => $reason ?: $inspection->summary_note,
        ])->save();

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event($accept ? 'porter_verified' : 'porter_rejected')
            ->log(sprintf(
                'Porter %s %s inspection #%d.',
                $operator->name,
                $accept ? 'verified' : 'rejected',
                $inspection->id,
            ));

        return $inspection->refresh();
    }
}
