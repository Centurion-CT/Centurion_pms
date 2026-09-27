<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Housekeeping;

use App\Actions\Housekeeping\MarkDamageResolved;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\RoomInspectionItem;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Housekeeping ops board, rebuilt around per-room accountability
 * instead of the simple status flag it used to show.
 *
 * Sections:
 *   - KPI strip, 7d/30d issues, rooms needing attention, open damage.
 *   - Rooms grid, one card per room with its LAST inspection
 *     (score, cleaner, porter, supervisor timestamps + open damage
 *     count). Rows click into a per-room history page.
 *   - "Needs replacement" list, every unresolved damage flag across
 *     the property with a manager tick to mark resolved.
 *   - Collapsible "Checklist templates", moved out of the way; the
 *     category card view is expanded only when the manager needs to
 *     author.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    /**
     * Collapsed by default so the ops board is the first thing an
     * operator sees. Manager expands to add / edit item lists.
     */
    public bool $templatesOpen = false;

    public function toggleTemplates(): void
    {
        $this->templatesOpen = ! $this->templatesOpen;
    }

    /* ── Checklist bundles (manager-only) ──────────────────────── */

    // Per-bundle new-item text buffer. Keyed by checklist id so
    // multiple bundles can have simultaneous drafts in the UI.
    public array $newItemLabels = [];

    // New-bundle draft state (name + attach-to-category dropdown).
    public string $newBundleName = '';

    public function createBundle(): void
    {
        if (! $this->isManager()) {
            return;
        }
        $tenant = \App\Models\Tenant::current();
        if ($tenant === null) {
            return;
        }
        $name = trim($this->newBundleName);
        if ($name === '' || mb_strlen($name) > 120) {
            $this->dispatch('notify', title: 'Done', message: 'Give the bundle a name (up to 120 characters).', kind: 'ok');

            return;
        }

        HousekeepingChecklist::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'active' => true,
        ]);

        $this->newBundleName = '';
        $this->dispatch('notify', title: 'Done', message: 'Created bundle "'.$name.'". Add items + attach categories below.', kind: 'ok');
    }

    public function renameBundle(int $checklistId, string $name): void
    {
        if (! $this->isManager()) {
            return;
        }
        $clean = trim($name);
        if ($clean === '' || mb_strlen($clean) > 120) {
            return;
        }
        HousekeepingChecklist::query()->find($checklistId)?->forceFill(['name' => $clean])->save();
        $this->dispatch('notify', title: 'Done', message: 'Renamed bundle.', kind: 'ok');
    }

    public function deleteBundle(int $checklistId): void
    {
        if (! $this->isManager()) {
            return;
        }
        $checklist = HousekeepingChecklist::query()->find($checklistId);
        if ($checklist === null) {
            return;
        }
        // Cascade drops items + pivot rows via FK constraints.
        $name = $checklist->name;
        $checklist->delete();
        $this->dispatch('notify', title: 'Done', message: 'Deleted bundle "'.$name.'".', kind: 'ok');
    }

    public function attachBundleToCategory(int $checklistId, int $categoryId): void
    {
        if (! $this->isManager()) {
            return;
        }
        $checklist = HousekeepingChecklist::query()->find($checklistId);
        $category = RoomCategory::query()->find($categoryId);
        if ($checklist === null || $category === null) {
            return;
        }
        $checklist->categories()->syncWithoutDetaching([$category->id]);

        // Auto-populate any inspections that are already open on
        // rooms in this category, otherwise a manager who attaches
        // a bundle mid-shift has to remember to also refresh every
        // pending inspection, which nobody remembers.
        $written = app(\App\Actions\Housekeeping\OpenRoomInspection::class)
            ->syncOpenInspectionsForCategory($category->id);

        $this->dispatch('notify', title: 'Done', message: $written > 0
            ? 'Attached "'.$checklist->name.'" to '.$category->name.' and refreshed '.$written.' open inspection item'.($written === 1 ? '' : 's').'.'
            : 'Attached "'.$checklist->name.'" to '.$category->name.'.', kind: 'ok');
    }

    public function detachBundleFromCategory(int $checklistId, int $categoryId): void
    {
        if (! $this->isManager()) {
            return;
        }
        $checklist = HousekeepingChecklist::query()->find($checklistId);
        if ($checklist === null) {
            return;
        }
        $checklist->categories()->detach($categoryId);
        $this->dispatch('notify', title: 'Done', message: 'Detached bundle from category.', kind: 'ok');
    }

    public function addChecklistItem(int $checklistId): void
    {
        if (! $this->isManager()) {
            return;
        }
        $checklist = HousekeepingChecklist::query()->find($checklistId);
        if ($checklist === null) {
            return;
        }

        $raw = $this->newItemLabels[$checklistId] ?? '';
        $clean = trim((string) $raw);
        if ($clean === '' || mb_strlen($clean) > 200) {
            return;
        }

        $existing = HousekeepingChecklistItem::query()
            ->where('housekeeping_checklist_id', $checklist->id)
            ->pluck('label')
            ->map(fn ($l) => mb_strtolower($l))
            ->all();
        if (in_array(mb_strtolower($clean), $existing, true)) {
            $this->newItemLabels[$checklistId] = '';

            return;
        }

        $nextSort = (int) HousekeepingChecklistItem::query()
            ->where('housekeeping_checklist_id', $checklist->id)
            ->max('sort_order');
        HousekeepingChecklistItem::query()->create([
            'tenant_id' => $checklist->tenant_id,
            'housekeeping_checklist_id' => $checklist->id,
            'label' => $clean,
            'sort_order' => $nextSort + 1,
        ]);
        $this->newItemLabels[$checklistId] = '';
        $this->dispatch('notify', title: 'Done', message: 'Added "'.$clean.'".', kind: 'ok');
    }

    public function removeChecklistItem(int $itemId): void
    {
        if (! $this->isManager()) {
            return;
        }
        HousekeepingChecklistItem::query()->find($itemId)?->delete();
    }

    public function moveChecklistItem(int $itemId, int $direction): void
    {
        if (! $this->isManager()) {
            return;
        }
        $item = HousekeepingChecklistItem::query()->find($itemId);
        if ($item === null) {
            return;
        }
        $neighbour = HousekeepingChecklistItem::query()
            ->where('housekeeping_checklist_id', $item->housekeeping_checklist_id)
            ->where('sort_order', $direction < 0 ? '<' : '>', $item->sort_order)
            ->orderBy('sort_order', $direction < 0 ? 'desc' : 'asc')
            ->first();
        if ($neighbour === null) {
            return;
        }
        $mySort = $item->sort_order;
        $item->forceFill(['sort_order' => $neighbour->sort_order])->save();
        $neighbour->forceFill(['sort_order' => $mySort])->save();
    }

    /* ── Damage resolution ──────────────────────────────────────── */

    public function markDamageResolved(int $itemId, MarkDamageResolved $action): void
    {
        $op = auth('web')->user();
        if (! $op instanceof Operator) {
            return;
        }
        $item = RoomInspectionItem::query()->find($itemId);
        if ($item === null) {
            return;
        }
        try {
            $action->handle($item, $op, resolved: true);
            $this->dispatch('notify', title: 'Done', message: 'Marked resolved.', kind: 'ok');
        } catch (\Throwable $e) {
            $this->dispatch('notify', title: 'Done', message: $e->getMessage(), kind: 'ok');
        }
    }

    public function render(): View
    {
        $tenant = Tenant::current();
        // Housekeeping cards belong to the branch the operator is
        // currently working in. Everything downstream (rooms,
        // inspections, occupancy) scopes through the rooms list.
        $property = app(\App\Services\Properties\PropertyContext::class)->current()
            ?? $tenant?->property;

        // ── Rooms, every active room. Each card renders its LAST
        //    inspection so the operator sees "when + who + score" at
        //    a glance without drilling in.
        $rooms = $property === null
            ? collect()
            : $property->rooms()
                ->where('status', RoomStatus::Active->value)
                ->with('category:id,name')
                ->orderBy('room_category_id')
                ->orderBy('room_number')
                ->get();

        $roomIds = $rooms->pluck('id')->all();

        // Preload every room's most-recent inspection (any status) in
        // one query per room via subselect, small N, cheap.
        $latestInspectionIds = RoomInspection::query()
            ->whereIn('room_id', $roomIds)
            ->selectRaw('room_id, MAX(id) as max_id')
            ->groupBy('room_id')
            ->pluck('max_id', 'room_id');
        $latestInspections = RoomInspection::query()
            ->whereIn('id', $latestInspectionIds->values()->all())
            ->with([
                'items' => fn ($q) => $q->orderBy('sort_order'),
            ])
            ->get()
            ->keyBy('room_id');

        // Cheap operator-name lookup for per-signer labels, one
        // query total, keyed by id.
        $operatorNames = Operator::query()
            ->whereIn('id', array_filter(array_unique(array_merge(
                $latestInspections->pluck('housekeeper_by_operator_id')->all(),
                $latestInspections->pluck('porter_by_operator_id')->all(),
                $latestInspections->pluck('supervisor_by_operator_id')->all(),
            ))))
            ->pluck('name', 'id');

        // Current occupancy, one query, keyed by room_id → the
        // CheckedIn booking on that room. The room card should surface
        // "Occupied" instead of a stale "Ready for guest" chip while
        // a guest is actually in the room.
        $currentOccupants = Booking::query()
            ->whereIn('room_id', $roomIds)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->get(['id', 'room_id', 'guest_name', 'booking_ref', 'checkout_date'])
            ->keyBy('room_id');

        // Per-room card payload, the view iterates this once.
        $roomsBoard = $rooms->map(function (Room $r) use ($latestInspections, $operatorNames, $currentOccupants) {
            $ins = $latestInspections->get($r->id);
            $openDamage = $ins
                ? $ins->items->filter(fn ($i) => $i->isOpenDamage())->count()
                : 0;

            return (object) [
                'room' => $r,
                'inspection' => $ins,
                'occupiedBy' => $currentOccupants->get($r->id),
                'housekeeper' => $ins?->housekeeper_by_operator_id
                    ? ($operatorNames[$ins->housekeeper_by_operator_id] ?? '-') : null,
                'porter' => $ins?->porter_by_operator_id
                    ? ($operatorNames[$ins->porter_by_operator_id] ?? '-') : null,
                'supervisor' => $ins?->supervisor_by_operator_id
                    ? ($operatorNames[$ins->supervisor_by_operator_id] ?? '-') : null,
                'openDamage' => $openDamage,
            ];
        });

        // ── KPI strip.
        $now = Carbon::now();
        $issuesLast7 = RoomInspectionItem::query()
            ->whereIn('room_inspection_id', RoomInspection::query()
                ->whereIn('room_id', $roomIds)
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->pluck('id'))
            ->whereNotNull('damage_note')
            ->where('damage_note', '!=', '')
            ->count();
        $issuesLast30 = RoomInspectionItem::query()
            ->whereIn('room_inspection_id', RoomInspection::query()
                ->whereIn('room_id', $roomIds)
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->pluck('id'))
            ->whereNotNull('damage_note')
            ->where('damage_note', '!=', '')
            ->count();

        $openInspectionCount = RoomInspection::query()
            ->whereIn('room_id', $roomIds)
            ->whereNotIn('status', [
                RoomInspectionStatus::Passed->value,
                RoomInspectionStatus::Failed->value,
            ])->count();

        // ── Needs replacement, every unresolved damage flag across
        //    the property. Manager can tick each resolved from here.
        $needsReplacement = RoomInspectionItem::query()
            ->whereIn('room_inspection_id', RoomInspection::query()
                ->whereIn('room_id', $roomIds)
                ->pluck('id'))
            ->whereNotNull('damage_note')
            ->where('damage_note', '!=', '')
            ->whereNull('resolved_at')
            ->with(['inspection.room.category'])
            ->orderByDesc('created_at')
            ->get();

        $kpis = [
            'issues_7d' => $issuesLast7,
            'issues_30d' => $issuesLast30,
            'open_inspections' => $openInspectionCount,
            'needs_replacement' => $needsReplacement->count(),
        ];

        // ── Checklist bundles (collapsible, manager-only editor).
        //     Bundles are reusable across categories now; render each
        //     with its items + attached-category chips + which
        //     categories are still available to attach.
        $categories = $property === null
            ? collect()
            : RoomCategory::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get();

        $bundles = $property === null
            ? collect()
            : HousekeepingChecklist::query()
                ->with(['items', 'categories:id,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (HousekeepingChecklist $c) => (object) [
                    'checklist' => $c,
                    'items' => $c->items,
                    'attached' => $c->categories,
                    'available' => $categories->reject(
                        fn (RoomCategory $cat) => $c->categories->contains('id', $cat->id),
                    )->values(),
                ]);

        // Live per-room status counts driven by the new occupancy +
        // inspection model. The legacy room.housekeeping_status column
        // is set to Dirty on every checkout and never cleared, so
        // reading it here would report every room ever slept in as
        // Dirty forever.
        $occupiedRoomIds = $currentOccupants->keys()->all();
        $readyCount = 0;
        $turnoverCount = 0;
        foreach ($rooms as $r) {
            if (in_array($r->id, $occupiedRoomIds, true)) {
                continue;
            }
            $ins = $latestInspections->get($r->id);
            if ($ins !== null && $ins->status === RoomInspectionStatus::Passed) {
                $readyCount++;
            } elseif ($ins !== null && $ins->status !== RoomInspectionStatus::Failed) {
                $turnoverCount++;
            }
        }
        $roomStatusCounts = [
            'occupied' => count($occupiedRoomIds),
            'ready' => $readyCount,
            'turnover_pending' => $turnoverCount,
        ];

        return view('livewire.dashboard.housekeeping.index', [
            'roomsBoard' => $roomsBoard,
            'kpis' => $kpis,
            'needsReplacement' => $needsReplacement,
            'roomStatusCounts' => $roomStatusCounts,
            'bundles' => $bundles,
            'categories' => $categories,
            'isManager' => $this->isManager(),
            'canResolveDamage' => $this->isManagerOrSupervisor(),
        ]);
    }

    private function isManager(): bool
    {
        $op = auth('web')->user();

        return $op instanceof Operator && $op->role === OperatorRole::Manager;
    }

    private function isManagerOrSupervisor(): bool
    {
        $op = auth('web')->user();

        return $op instanceof Operator
            && in_array($op->role, [OperatorRole::Manager, OperatorRole::Supervisor], true);
    }
}
