<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Operator;
use App\Models\RoomInspection;
use App\Support\Housekeeping\ChecklistPictures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Porter says "not ready" from the staff app: the room goes back to the
 * cleaner who did it instead of failing the inspection. CleaningDone →
 * Pending, assigned to that cleaner, with the reasons and note kept on
 * the row and send_back_count bumped. Ticks the reasons cover are
 * cleared (towels → towel items, bathroom → the bathroom group, …);
 * reasons that name no item (smell, missing, other) keep every tick.
 *
 * The web reject (VerifyPorterInspection with accept=false → Failed)
 * stays as it is: that closes the inspection for a supervisor to reopen.
 */
class SendRoomBack
{
    /**
     * @param  list<string>  $reasons  keys of ChecklistPictures::REASONS
     */
    public function handle(RoomInspection $inspection, Operator $operator, array $reasons, ?string $note = null): RoomInspection
    {
        if (! in_array($operator->role, [OperatorRole::Porter, OperatorRole::Supervisor, OperatorRole::Manager], true)) {
            throw new RuntimeException('Only porters, supervisors, or managers can send a room back.');
        }
        if ($inspection->status !== RoomInspectionStatus::CleaningDone) {
            throw new RuntimeException(sprintf(
                'Inspection isn\'t awaiting porter (currently %s).',
                $inspection->status->value,
            ));
        }

        $reasons = array_values(array_intersect(array_keys(ChecklistPictures::REASONS), $reasons));
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        DB::transaction(function () use ($inspection, $operator, $reasons, $note): void {
            foreach ($inspection->items()->where('checked', true)->get() as $item) {
                foreach ($reasons as $reason) {
                    if (ChecklistPictures::reasonCovers($reason, $item->label)) {
                        $item->forceFill(['checked' => false, 'checked_at' => null, 'checked_by_operator_id' => null])->save();
                        break;
                    }
                }
            }

            $inspection->forceFill([
                'status' => RoomInspectionStatus::Pending,
                'assigned_operator_id' => $inspection->housekeeper_by_operator_id ?? $inspection->assigned_operator_id,
                'sent_back_at' => Carbon::now(),
                'sent_back_by_operator_id' => $operator->id,
                'sent_back_reasons' => $reasons,
                'sent_back_note' => $note,
                'send_back_count' => (int) $inspection->send_back_count + 1,
            ])->save();
        });

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event('porter_sent_back')
            ->withProperties(['reasons' => $reasons, 'note' => $note])
            ->log(sprintf(
                'Porter %s sent inspection #%d back to the cleaner (%s).',
                $operator->name,
                $inspection->id,
                ChecklistPictures::reasonsLabel($reasons) ?: 'no reason given',
            ));

        return $inspection->refresh();
    }
}
