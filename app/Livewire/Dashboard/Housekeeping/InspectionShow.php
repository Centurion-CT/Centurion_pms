<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Housekeeping;

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SignOffSupervisorInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Operator;
use App\Models\RoomInspection;
use App\Models\RoomInspectionItem;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One-inspection view where each signer does their part:
 *
 *   - HouseCleaner: tick items, note damage, submit.
 *   - Porter: read-only checklist + verify / reject.
 *   - Supervisor: read-only checklist + sign off / reject.
 *   - Manager: can do any of the above.
 */
#[Layout('layouts.tenant-auth')]
class InspectionShow extends Component
{
    #[Locked]
    public int $inspectionId;

    public ?string $summaryNote = null;

    public function mount(RoomInspection $inspection): void
    {
        $this->inspectionId = $inspection->id;
        $this->summaryNote = $inspection->summary_note;
    }

    public function toggleItem(int $itemId): void
    {
        $item = RoomInspectionItem::query()
            ->where('room_inspection_id', $this->inspectionId)
            ->find($itemId);
        if ($item === null) {
            return;
        }
        $inspection = $this->inspection();
        // Ticking is a housekeeper action, porter + supervisor read
        // the checklist as-is when it's their turn. Manager can act
        // as any role. Any stage past supervisor sign-off locks the
        // whole thing regardless.
        $op = auth('web')->user();
        $role = $op instanceof Operator ? $op->role : null;
        $rolePermitted = in_array($role, [
            OperatorRole::HouseCleaner,
            OperatorRole::Supervisor,
            OperatorRole::Manager,
        ], true);
        if (! $rolePermitted
            || ! in_array($inspection->status, [
                RoomInspectionStatus::Pending, RoomInspectionStatus::CleaningDone,
            ], true)
        ) {
            $this->notifyError('Checklist locked', 'You can no longer edit this inspection.');

            return;
        }

        $item->forceFill([
            'checked' => ! $item->checked,
            'checked_at' => now(),
            'checked_by_operator_id' => auth('web')->id(),
        ])->save();
    }

    /**
     * Tick every unchecked item on the sheet at once — same role +
     * inspection-stage gates as toggleItem, just applied in bulk.
     * Damage notes and already-ticked items are left alone; the
     * housekeeper can still untick anything after if needed.
     */
    public function markAllDone(): void
    {
        $inspection = $this->inspection();
        $op = auth('web')->user();
        $role = $op instanceof Operator ? $op->role : null;
        $rolePermitted = in_array($role, [
            OperatorRole::HouseCleaner,
            OperatorRole::Supervisor,
            OperatorRole::Manager,
        ], true);
        if (! $rolePermitted
            || ! in_array($inspection->status, [
                RoomInspectionStatus::Pending, RoomInspectionStatus::CleaningDone,
            ], true)
        ) {
            $this->notifyError('Checklist locked', 'You can no longer edit this inspection.');
            return;
        }

        $now = now();
        $count = RoomInspectionItem::query()
            ->where('room_inspection_id', $this->inspectionId)
            ->where('checked', false)
            ->update([
                'checked' => true,
                'checked_at' => $now,
                'checked_by_operator_id' => $op->id,
                'updated_at' => $now,
            ]);

        $this->notifyOk(
            $count > 0 ? 'All items marked done' : 'Already all done',
            $count > 0 ? "Ticked {$count} item(s)." : 'Nothing left to tick.',
        );
    }

    public function updateDamageNote(int $itemId, ?string $note): void
    {
        $item = RoomInspectionItem::query()
            ->where('room_inspection_id', $this->inspectionId)
            ->find($itemId);
        if ($item === null) {
            return;
        }
        $inspection = $this->inspection();
        if (! in_array($inspection->status, [
            RoomInspectionStatus::Pending, RoomInspectionStatus::CleaningDone,
        ], true)) {
            return;
        }

        $clean = trim((string) $note);
        $item->forceFill([
            'damage_note' => $clean === '' ? null : $clean,
        ])->save();
    }

    public function submitCleaning(SubmitHousekeeperCleaning $action): void
    {
        $this->run(
            fn () => $action->handle($this->inspection(), $this->operator(), $this->summaryNote),
            'Cleaning submitted',
            'Porter can verify it now.',
        );
    }

    public function porterVerify(VerifyPorterInspection $action, bool $accept = true): void
    {
        $this->run(
            fn () => $action->handle($this->inspection(), $this->operator(), $accept, $this->summaryNote),
            $accept ? 'Verified' : 'Rejected',
            $accept ? 'Sent to supervisor for sign-off.' : 'Open a fresh inspection to retry.',
        );
    }

    public function supervisorSignOff(SignOffSupervisorInspection $action, bool $accept = true): void
    {
        $this->run(
            fn () => $action->handle($this->inspection(), $this->operator(), $accept, $this->summaryNote),
            $accept ? 'Room cleared' : 'Rejected',
            $accept ? 'Guests can be checked in.' : 'Housekeeping will need to redo the room.',
        );
    }

    public function render(OpenRoomInspection $snapshotter): View
    {
        // Lazy auto-sync: an inspection that opened before its
        // category picked up a bundle would otherwise stay empty
        // forever. Fire snapshotItems on view when the sheet is
        // still in the housekeeping stage (Pending / CleaningDone)
        //, past that the checklist is frozen for sign-off.
        // Idempotent: only writes labels not already on the sheet.
        $rawInspection = RoomInspection::query()->findOrFail($this->inspectionId);
        if (in_array($rawInspection->status, [
            RoomInspectionStatus::Pending, RoomInspectionStatus::CleaningDone,
        ], true)) {
            $snapshotter->snapshotItems($rawInspection);
        }

        $inspection = RoomInspection::query()
            ->with([
                'room.category',
                'items',
                'triggeredByBooking',
            ])
            ->findOrFail($this->inspectionId);

        $op = auth('web')->user();
        $role = $op instanceof Operator ? $op->role : null;
        $isManager = $role === OperatorRole::Manager;

        $canTick = in_array($inspection->status, [
            RoomInspectionStatus::Pending, RoomInspectionStatus::CleaningDone,
        ], true) && in_array($role, [
            OperatorRole::HouseCleaner, OperatorRole::Supervisor, OperatorRole::Manager,
        ], true);

        $canSubmit = $canTick;
        $canPorter = $inspection->status === RoomInspectionStatus::CleaningDone
            && in_array($role, [OperatorRole::Porter, OperatorRole::Supervisor, OperatorRole::Manager], true);
        $canSupervisor = $inspection->status === RoomInspectionStatus::PorterVerified
            && in_array($role, [OperatorRole::Supervisor, OperatorRole::Manager], true);

        return view('livewire.dashboard.housekeeping.inspection-show', [
            'inspection' => $inspection,
            'canTick' => $canTick,
            'canSubmit' => $canSubmit,
            'canPorter' => $canPorter,
            'canSupervisor' => $canSupervisor,
            'isManager' => $isManager,
        ]);
    }

    private function inspection(): RoomInspection
    {
        return RoomInspection::query()->findOrFail($this->inspectionId);
    }

    private function operator(): Operator
    {
        $op = auth('web')->user();
        if (! $op instanceof Operator) {
            throw new RuntimeException('You must be signed in.');
        }

        return $op;
    }

    private function run(callable $fn, string $successTitle, ?string $successMessage = null): void
    {
        try {
            $fn();
            $this->notifyOk($successTitle, $successMessage);
        } catch (\Throwable $e) {
            $this->notifyError('Could not complete', $e->getMessage());
        }
    }

    private function notifyOk(string $title, ?string $message = null): void
    {
        $this->dispatch('notify', title: $title, message: $message ?? '', kind: 'ok');
    }

    private function notifyError(string $title, ?string $message = null): void
    {
        $this->dispatch('notify', title: $title, message: $message ?? '', kind: 'err');
    }
}
