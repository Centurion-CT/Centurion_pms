<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Actions\Inventory\ApplyHousekeepingTurnover;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\RoomInspectionStatus;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\RoomInspection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Housekeeper submits their pass. Advances Pending → CleaningDone.
 * Score is derived: 100 - 10 per unchecked item - 5 per damage note.
 * Floors at 0. A housekeeper can re-submit while still Pending or
 * CleaningDone (edit their notes); porter/supervisor stages lock it.
 *
 * On the FIRST transition into CleaningDone, we auto-deduct amenity /
 * linen usage from the property's Housekeeping supply outlet via
 * {@see ApplyHousekeepingTurnover}. Re-submissions (still in
 * CleaningDone) never re-deduct — the `if (! $wasAlreadyCleaningDone)`
 * guard keeps consumption idempotent per inspection. Deduction is
 * best-effort: an inventory error is logged to activity_log but does
 * NOT roll back the cleaning submission, the room is still clean even
 * if we can't record what soap was used.
 */
class SubmitHousekeeperCleaning
{
    public function __construct(
        private readonly ApplyHousekeepingTurnover $applyTurnover,
    ) {}

    public function handle(RoomInspection $inspection, Operator $operator, ?string $summaryNote = null): RoomInspection
    {
        if (! in_array($operator->role, [OperatorRole::HouseCleaner, OperatorRole::Supervisor, OperatorRole::Manager], true)) {
            throw new RuntimeException('Only housekeepers, supervisors, or managers can submit cleaning.');
        }
        if (in_array($inspection->status, [RoomInspectionStatus::Passed, RoomInspectionStatus::Failed], true)) {
            throw new RuntimeException('Inspection is closed — open a fresh one.');
        }

        $wasAlreadyCleaningDone = $inspection->status === RoomInspectionStatus::CleaningDone;

        $items = $inspection->items()->get();
        $total = $items->count();
        $unchecked = $items->where('checked', false)->count();
        $damaged = $items->whereNotNull('damage_note')
            ->reject(fn ($i) => trim((string) $i->damage_note) === '')
            ->count();

        $score = 100 - ($unchecked * 10) - ($damaged * 5);
        $score = max(0, min(100, $score));

        $inspection->forceFill([
            'status' => RoomInspectionStatus::CleaningDone,
            'housekeeper_at' => Carbon::now(),
            'housekeeper_by_operator_id' => $operator->id,
            'readiness_score' => $score,
            'summary_note' => $summaryNote,
        ])->save();

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($inspection)
            ->event('cleaning_submitted')
            ->withProperties([
                'score' => $score,
                'total_items' => $total,
                'unchecked' => $unchecked,
                'damaged' => $damaged,
            ])
            ->log(sprintf(
                'Housekeeper %s submitted cleaning for inspection #%d (score %d/100).',
                $operator->name,
                $inspection->id,
                $score,
            ));

        if (! $wasAlreadyCleaningDone) {
            $this->autoDeductAmenities($inspection, $operator);
        }

        return $inspection->refresh();
    }

    /**
     * Best-effort amenity deduction on first cleaning submission. Picks
     * the first active Housekeeping outlet on this property (single-outlet
     * shops need zero setup; multi-outlet shops get the deterministic
     * lowest-id one — we can add a per-inspection-source picker if that
     * becomes real). Failures land in activity_log; the cleaning still
     * stands.
     */
    private function autoDeductAmenities(RoomInspection $inspection, Operator $operator): void
    {
        try {
            $room = $inspection->room()->with('property')->first();
            $propertyId = $room?->property_id;
            if ($propertyId === null) {
                return;
            }

            $outlet = Outlet::query()
                ->where('type', OutletType::Housekeeping)
                ->where('property_id', $propertyId)
                ->where('active', true)
                ->orderBy('id')
                ->first();

            if ($outlet === null) {
                return; // Property has no housekeeping supply outlet — nothing to deduct from.
            }

            $roomLabel = $room?->name ?? "Room #{$inspection->room_id}";
            $summary = $this->applyTurnover->handle(
                outlet:       $outlet,
                roomsCleaned: 1,
                noteRef:      "Inspection #{$inspection->id} · {$roomLabel}",
                operator:     $operator,
            );

            activity('housekeeping')
                ->causedBy($operator)
                ->performedOn($inspection)
                ->event('amenities_deducted')
                ->withProperties([
                    'outlet_id'   => $outlet->id,
                    'lines_moved' => $summary['lines_moved'],
                    'total_units' => $summary['total_units'],
                ])
                ->log(sprintf(
                    'Auto-deducted %d unit(s) across %d supply line(s) for inspection #%d.',
                    $summary['total_units'],
                    $summary['lines_moved'],
                    $inspection->id,
                ));
        } catch (Throwable $e) {
            activity('housekeeping')
                ->causedBy($operator)
                ->performedOn($inspection)
                ->event('amenities_deduction_failed')
                ->withProperties(['error' => $e->getMessage()])
                ->log(sprintf(
                    'Amenity auto-deduction failed for inspection #%d: %s',
                    $inspection->id,
                    $e->getMessage(),
                ));
        }
    }
}
