<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Housekeeping;

use App\Actions\Housekeeping\ClearRoomFlag;
use App\Actions\Housekeeping\FlagRoom;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Per-room housekeeping history, the full timeline of inspections
 * for a single room. Answers "when was room 306 last cleaned, who
 * signed off, what did they flag, and what's still open?".
 */
#[Layout('layouts.tenant-auth')]
class RoomHistory extends Component
{
    #[Locked]
    public int $roomId;

    /** Manager-only "put this room out of service" reason input. */
    public string $flagReason = '';

    public function mount(Room $room): void
    {
        $this->roomId = $room->id;
    }

    public function flagRoom(FlagRoom $action): void
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) {
            return;
        }

        $room = Room::query()->findOrFail($this->roomId);

        try {
            $action->handle($room, $operator, $this->flagReason);
        } catch (Throwable $e) {
            $this->dispatch('notify',
                title: 'Could not flag room',
                message: $e->getMessage(),
                kind: 'error',
            );

            return;
        }

        $this->flagReason = '';
        $this->dispatch('notify',
            title: 'Room flagged',
            message: 'Rm '.$room->room_number.' is out of service. Check-in is blocked until the flag is cleared.',
            kind: 'warn',
        );
    }

    public function clearRoomFlag(ClearRoomFlag $action): void
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) {
            return;
        }

        $room = Room::query()->findOrFail($this->roomId);

        try {
            $action->handle($room, $operator);
        } catch (Throwable $e) {
            $this->dispatch('notify',
                title: 'Could not clear flag',
                message: $e->getMessage(),
                kind: 'error',
            );

            return;
        }

        $this->dispatch('notify',
            title: 'Flag cleared',
            message: 'Rm '.$room->room_number.' is back in service.',
            kind: 'ok',
        );
    }

    public function render(\App\Actions\Housekeeping\OpenRoomInspection $snapshotter): View
    {
        $room = Room::query()->with('category')->findOrFail($this->roomId);

        // Lazy auto-sync: any open inspection with 0 items gets
        // populated from currently-attached bundles before we
        // render. Same idempotent snapshot the inspection show page
        // fires, safe to call twice, second run is a no-op.
        $openIds = RoomInspection::query()
            ->where('room_id', $room->id)
            ->whereIn('status', [
                \App\Enums\RoomInspectionStatus::Pending->value,
                \App\Enums\RoomInspectionStatus::CleaningDone->value,
            ])
            ->pluck('id');
        foreach ($openIds as $id) {
            $ins = RoomInspection::query()->find($id);
            if ($ins !== null) {
                $snapshotter->snapshotItems($ins);
            }
        }

        $inspections = RoomInspection::query()
            ->where('room_id', $room->id)
            ->with([
                'items' => fn ($q) => $q->orderBy('sort_order'),
                'triggeredByBooking:id,booking_ref,guest_name',
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        // Operator-name lookup for the signer rows on each inspection.
        $operatorIds = collect();
        foreach ($inspections as $ins) {
            $operatorIds = $operatorIds->merge([
                $ins->housekeeper_by_operator_id,
                $ins->porter_by_operator_id,
                $ins->supervisor_by_operator_id,
            ]);
        }
        $operatorNames = Operator::query()
            ->whereIn('id', $operatorIds->filter()->unique()->all())
            ->pluck('name', 'id');

        // Small summary card at the top.
        $now = Carbon::now();
        $summary = [
            'total' => $inspections->count(),
            'issues_7d' => (int) $inspections->filter(fn ($ins) => $ins->created_at->gte($now->copy()->subDays(7)))
                ->sum(fn ($ins) => $ins->items->filter(fn ($i) => trim((string) $i->damage_note) !== '')->count()),
            'issues_30d' => (int) $inspections->filter(fn ($ins) => $ins->created_at->gte($now->copy()->subDays(30)))
                ->sum(fn ($ins) => $ins->items->filter(fn ($i) => trim((string) $i->damage_note) !== '')->count()),
            'open_damage' => (int) $inspections->sum(fn ($ins) => $ins->items->filter(fn ($i) => $i->isOpenDamage())->count()),
            'last_passed' => $inspections->firstWhere('status.value', 'passed')?->supervisor_at,
        ];

        // Current occupant, if any. The inspection timeline shows
        // historical chip states (which are accurate) but without this
        // signal the page reads as "cleared for guests" when a guest
        // is actually already in the room.
        $currentOccupant = Booking::query()
            ->where('room_id', $room->id)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->orderByDesc('id')
            ->first(['id', 'booking_ref', 'guest_name', 'checkin_date', 'checkout_date']);

        // Flag state, derived from the latest inspection. The
        // banner + Manager-only flag/clear buttons key off these.
        $latest = $inspections->first();
        $isFlagged = $latest?->status === RoomInspectionStatus::Failed;
        $flagReasonNote = null;
        if ($isFlagged && $latest !== null) {
            $note = (string) ($latest->summary_note ?? '');
            // Strip the "Manager flag: " / "Rejected: " prefix if
            // present so the banner shows just the reason.
            $flagReasonNote = preg_replace('/^(Manager flag|Rejected|Front-desk override):\s*/', '', $note);
            if ($flagReasonNote === '' || $flagReasonNote === null) {
                $flagReasonNote = null;
            }
        }
        $op = auth('web')->user();
        $isManager = $op instanceof Operator && $op->role === OperatorRole::Manager;

        return view('livewire.dashboard.housekeeping.room-history', [
            'room' => $room,
            'inspections' => $inspections,
            'operatorNames' => $operatorNames,
            'summary' => $summary,
            'currentOccupant' => $currentOccupant,
            'isFlagged' => $isFlagged,
            'flagReasonNote' => $flagReasonNote,
            'isManager' => $isManager,
        ]);
    }
}
