<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SignOffSupervisorInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\InspectionResource;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use App\Models\RoomInspectionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read + write for the housekeeping chain from the phone. Every write
 * runs through the same action classes the web dashboard uses so the
 * business rules (role gates, status transitions, readiness scoring,
 * check-in unlock) can't fork between surfaces.
 *
 * `filter=queue` returns the role-specific queue used on the
 * housekeeping home dashboard:
 *   HouseCleaner → Pending
 *   Porter       → CleaningDone
 *   Supervisor   → PorterVerified
 *   Manager      → every open inspection
 */
class InspectionsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['queue', 'open', 'today'])],
        ]);
        $filter = $data['filter'] ?? 'queue';

        $operator = $this->operator($request);
        $role = $operator->role;

        $query = RoomInspection::query()->with(['room.category', 'items', 'triggeredByBooking:id,booking_ref,guest_name']);

        if ($filter === 'queue') {
            $status = match ($role) {
                OperatorRole::HouseCleaner => RoomInspectionStatus::Pending->value,
                OperatorRole::Porter => RoomInspectionStatus::CleaningDone->value,
                OperatorRole::Supervisor => RoomInspectionStatus::PorterVerified->value,
                default => null,
            };
            if ($status !== null) {
                $query->where('status', $status);
            } else {
                // Manager: every non-terminal inspection.
                $query->whereNotIn('status', [
                    RoomInspectionStatus::Passed->value,
                    RoomInspectionStatus::Failed->value,
                ]);
            }
        } elseif ($filter === 'open') {
            $query->whereNotIn('status', [RoomInspectionStatus::Passed->value]);
        } elseif ($filter === 'today') {
            $query->whereDate('created_at', now()->toDateString());
        }

        $inspections = $query->orderBy('created_at')->limit(100)->get();

        return InspectionResource::collection($inspections);
    }

    public function show(Request $request, int $inspection): InspectionResource
    {
        // Auto-sync on view — same behaviour as web InspectionShow so
        // the app never lands on an empty checklist right after a
        // bundle got attached mid-shift.
        $ins = RoomInspection::query()->findOrFail($inspection);
        if (in_array($ins->status, [
            RoomInspectionStatus::Pending, RoomInspectionStatus::CleaningDone,
        ], true)) {
            app(OpenRoomInspection::class)->snapshotItems($ins);
        }
        $ins->load(['room.category', 'items', 'triggeredByBooking:id,booking_ref,guest_name']);

        return new InspectionResource($ins);
    }

    /**
     * Housekeeper's Submit-for-porter. Body: {items: [{id, checked,
     * damage_note?}]}. Server applies the ticks then calls
     * SubmitHousekeeperCleaning (same action as web) which enforces
     * role + status guards, snapshots the sign-off timestamp, and
     * bumps the room's housekeeping_status.
     */
    public function submit(Request $request, int $inspection, SubmitHousekeeperCleaning $action): JsonResponse
    {
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.checked' => ['required', 'boolean'],
            'items.*.damage_note' => ['nullable', 'string', 'max:500'],
            'summary_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $operator = $this->operator($request);
        $ins = RoomInspection::query()->findOrFail($inspection);

        // Persist ticks + damage notes first — order matters: the
        // action reads item state to compute readiness_score.
        foreach ($data['items'] ?? [] as $itemPayload) {
            $item = RoomInspectionItem::query()
                ->where('room_inspection_id', $ins->id)
                ->find((int) $itemPayload['id']);
            if ($item === null) {
                continue;
            }
            $damage = $itemPayload['damage_note'] ?? null;
            $item->forceFill([
                'checked' => (bool) $itemPayload['checked'],
                'checked_at' => $itemPayload['checked'] ? now() : null,
                'checked_by_operator_id' => $itemPayload['checked'] ? $operator->id : null,
                'damage_note' => $damage !== null && trim($damage) !== '' ? trim($damage) : null,
            ])->save();
        }

        try {
            $action->handle($ins, $operator, $data['summary_note'] ?? null);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'submit_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'inspection' => new InspectionResource($ins->fresh(['room.category', 'items'])),
        ]);
    }

    /**
     * Porter's Verify (accept=true) / Reject (accept=false). Body:
     * {accept: bool, summary_note?: string}. Delegates to the same
     * VerifyPorterInspection action web uses.
     */
    public function verify(Request $request, int $inspection, VerifyPorterInspection $action): JsonResponse
    {
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'summary_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->runSignoffStage(
            fn () => $action->handle(
                RoomInspection::query()->findOrFail($inspection),
                $this->operator($request),
                (bool) $data['accept'],
                $data['summary_note'] ?? null,
            ),
            $inspection,
        );
    }

    /**
     * Supervisor's final Sign off (accept=true) / Reject (accept=false).
     * Body identical to verify.
     */
    public function signOff(Request $request, int $inspection, SignOffSupervisorInspection $action): JsonResponse
    {
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'summary_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->runSignoffStage(
            fn () => $action->handle(
                RoomInspection::query()->findOrFail($inspection),
                $this->operator($request),
                (bool) $data['accept'],
                $data['summary_note'] ?? null,
            ),
            $inspection,
        );
    }

    /**
     * Last 20 inspections for a room — supports the room-history
     * screen. Includes items so the app can show good/bad breakdowns
     * without a per-row hydration round-trip.
     */
    public function roomHistory(Request $request, int $room): AnonymousResourceCollection
    {
        // Guard: room must belong to the current tenant (BelongsToTenant
        // scope handles this, but explicit findOrFail makes the 404
        // response clean when a phone hits a stale/wrong ID).
        Room::query()->findOrFail($room);

        return InspectionResource::collection(self::roomHistoryInspections($room));
    }

    /**
     * Last 20 inspections of a room, newest first. Shared with the host
     * app (GET host/rooms/{id}/history) so both apps show the same list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RoomInspection>
     */
    public static function roomHistoryInspections(int $roomId): \Illuminate\Database\Eloquent\Collection
    {
        return RoomInspection::query()
            ->where('room_id', $roomId)
            ->with(['items', 'triggeredByBooking:id,booking_ref,guest_name', 'room.category'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * Shared plumbing for the verify + signOff endpoints — call the
     * action, catch business rule violations as 422s, return the fresh
     * inspection so the app can update the queue optimistically.
     */
    private function runSignoffStage(callable $handler, int $inspectionId): JsonResponse
    {
        try {
            $handler();
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'stage_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        $fresh = RoomInspection::query()->with(['room.category', 'items'])->findOrFail($inspectionId);

        return response()->json([
            'status' => 'ok',
            'inspection' => new InspectionResource($fresh),
        ]);
    }

    private function operator(Request $request): Operator
    {
        $op = $request->user();
        if (! $op instanceof Operator) {
            abort(401);
        }

        return $op;
    }
}
