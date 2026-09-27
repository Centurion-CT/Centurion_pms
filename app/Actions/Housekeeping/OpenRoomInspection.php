<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\RoomInspectionItem;
use Illuminate\Support\Facades\DB;

/**
 * Open a fresh RoomInspection for a room and snapshot the union of
 * every attached category checklist onto it. Called from
 * MarkCheckedOut so every guest departure triggers a turnover pass,
 * and (optionally) from a supervisor's manual "open inspection"
 * button.
 *
 * Categories can now attach multiple reusable checklist bundles
 * (see HousekeepingChecklist::categories many-to-many). All active
 * bundles contribute items; label collisions across bundles collapse
 * to a single snapshot row (case-insensitive) so a common item like
 * "Sweep floor" appearing in both a "Standard cleaning" and "Deep
 * cleaning" bundle doesn't double up on the sheet.
 *
 * Idempotent — a room already has at most one open inspection
 * (status != Passed && != Failed). Returns the existing one if
 * there's one pending.
 */
class OpenRoomInspection
{
    public function handle(Room $room, ?Booking $triggeredBy = null): RoomInspection
    {
        return DB::transaction(function () use ($room, $triggeredBy): RoomInspection {
            // Reuse the current open inspection if one exists — an
            // early checkout followed by another shouldn't create two.
            $existing = RoomInspection::query()
                ->where('room_id', $room->id)
                ->whereNotIn('status', [
                    RoomInspectionStatus::Passed->value,
                    RoomInspectionStatus::Failed->value,
                ])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            // Every active bundle attached to this room's category
            // contributes items. Nothing attached → empty checklist.
            $category = RoomCategory::query()
                ->with([
                    'checklists' => fn ($q) => $q->where('active', true),
                    'checklists.items',
                ])
                ->find($room->room_category_id);

            $checklists = $category?->checklists ?? collect();

            $inspection = RoomInspection::query()->create([
                'tenant_id' => $room->tenant_id,
                'room_id' => $room->id,
                'room_category_id' => $room->room_category_id,
                // Store the *first* attached bundle as the "primary"
                // pointer for audit — RoomInspection.housekeeping_checklist_id
                // is a soft reference. The full snapshot comes from
                // the union below, not from this field.
                'housekeeping_checklist_id' => $checklists->first()?->id,
                'triggered_by_booking_id' => $triggeredBy?->id,
                'status' => RoomInspectionStatus::Pending,
                'readiness_score' => 0,
            ]);

            $this->snapshotItems($inspection);

            return $inspection;
        });
    }

    /**
     * Snapshot the union of every active checklist bundle attached to
     * the inspection's room category onto the inspection itself.
     * Skips items whose label collision (case-insensitive) already
     * exists on the inspection — safe to call repeatedly to top up
     * an inspection whose category picked up a new bundle after
     * open time.
     *
     * Wrapped in a transaction with a SELECT ... FOR UPDATE lock on
     * the inspection row so two concurrent auto-sync callers (e.g.
     * two managers viewing the empty inspection at the same time)
     * serialise cleanly and don't insert duplicate rows.
     *
     * Returns the number of new items written.
     */
    public function snapshotItems(RoomInspection $inspection): int
    {
        return DB::transaction(function () use ($inspection): int {
            // Serialise against concurrent snapshotters on the same
            // inspection. Cheap — one indexed row lock.
            RoomInspection::query()->whereKey($inspection->id)->lockForUpdate()->first();

            $category = RoomCategory::query()
                ->with([
                    'checklists' => fn ($q) => $q->where('active', true),
                    'checklists.items',
                ])
                ->find($inspection->room_category_id);

            $checklists = $category?->checklists ?? collect();

            // Existing snapshot labels (case-insensitive) — don't
            // re-insert items that are already on the sheet.
            $existing = RoomInspectionItem::query()
                ->where('room_inspection_id', $inspection->id)
                ->pluck('label')
                ->map(fn ($l) => mb_strtolower(trim((string) $l)))
                ->all();

            $seen = array_flip($existing);
            $writeQueue = [];
            foreach ($checklists as $checklist) {
                foreach ($checklist->items as $item) {
                    $key = mb_strtolower(trim((string) $item->label));
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $writeQueue[] = $item;
                }
            }

            // Continue numbering from wherever the inspection is now so
            // existing items keep their position.
            $order = (int) RoomInspectionItem::query()
                ->where('room_inspection_id', $inspection->id)
                ->max('sort_order');
            foreach ($writeQueue as $item) {
                $order++;
                RoomInspectionItem::query()->create([
                    'tenant_id' => $inspection->tenant_id,
                    'room_inspection_id' => $inspection->id,
                    'housekeeping_checklist_item_id' => $item->id,
                    'label' => $item->label,
                    'sort_order' => $order,
                    'checked' => false,
                ]);
            }

            return count($writeQueue);
        });
    }

    /**
     * Convenience: auto-sync every currently-open inspection for
     * rooms in the given category. Fired when a manager attaches a
     * bundle to a category so any empty inspections that predate the
     * attach get the items right away, no manual re-visit needed.
     *
     * "Open" = still in the housekeeping stage (Pending /
     * CleaningDone). Past that, the checklist is frozen for porter +
     * supervisor sign-off.
     */
    public function syncOpenInspectionsForCategory(int $roomCategoryId): int
    {
        $inspections = RoomInspection::query()
            ->whereIn('status', [
                RoomInspectionStatus::Pending->value,
                RoomInspectionStatus::CleaningDone->value,
            ])
            ->whereHas('room', fn ($q) => $q->where('room_category_id', $roomCategoryId))
            ->get();

        $written = 0;
        foreach ($inspections as $inspection) {
            $written += $this->snapshotItems($inspection);
        }

        return $written;
    }
}
