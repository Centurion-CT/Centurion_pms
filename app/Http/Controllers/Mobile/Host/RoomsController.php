<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Actions\Housekeeping\AssignRoomCleaner;
use App\Actions\Housekeeping\ClearRoomFlag;
use App\Actions\Housekeeping\FlagRoom;
use App\Actions\Housekeeping\SetRoomHousekeepingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Mobile\InspectionsController;
use App\Http\Resources\Mobile\InspectionResource;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Host app Rooms board (docs/host-app/PHASE-3.md, 18 / 18b). Branch
 * scoped. Writes follow the web Housekeeping gates exactly: the
 * housekeeping module AND a manager / supervisor role (web
 * isManagerOrSupervisor); out of order / clearing a flag are
 * manager-only like the web room page (FlagRoom / ClearRoomFlag).
 */
class RoomsController extends HostController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['nullable', Rule::in(['all', ...RoomBoard::TILE_STATES])],
        ]);
        $state = $data['state'] ?? 'all';
        $operator = $this->operator($request);

        $tiles = RoomBoard::tiles($this->property($request));
        $rows = $state === 'all' ? $tiles : array_values(array_filter($tiles, fn (array $t) => $t['tile_state'] === $state));

        return response()->json([
            'data' => $rows,
            'counts' => RoomBoard::counts($tiles),
            'can' => [
                'change_status' => self::canChangeRooms($operator),
                'assign_cleaner' => self::canChangeRooms($operator),
                'out_of_order' => self::canChangeRooms($operator) && $operator->role === OperatorRole::Manager,
            ],
        ]);
    }

    public function status(Request $request, int $id, SetRoomHousekeepingStatus $set, FlagRoom $flag, ClearRoomFlag $clear): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(['clean', 'dirty', 'out_of_order'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $operator = $this->operator($request);
        $room = $this->findRoom($request, $id);
        if ($room === null) {
            return $this->notFound();
        }
        if (! self::canChangeRooms($operator)) {
            return $this->forbidden();
        }

        try {
            DB::transaction(function () use ($data, $room, $operator, $set, $flag, $clear): void {
                match ($data['state']) {
                    // Web room page "Flag out of service" (manager, reason required).
                    'out_of_order' => $flag->handle($room, $operator, (string) ($data['reason'] ?? '')),
                    'dirty' => $set->handle($room, $operator, HousekeepingStatus::Dirty),
                    'clean' => $this->markClean($room, $operator, $set, $clear, $data['reason'] ?? null),
                };
            });
        } catch (Throwable $e) {
            return response()->json(['error' => 'status_failed', 'detail' => $e->getMessage()], 422);
        }

        return $this->okRoom($request, $room);
    }

    public function housekeepers(Request $request): JsonResponse
    {
        $property = $this->property($request);
        $roomIds = Room::query()->where('property_id', $property->id)->pluck('id')->all();

        $assigned = $roomIds === [] ? collect() : RoomInspection::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('status', array_map(fn (RoomInspectionStatus $s) => $s->value, AssignRoomCleaner::OPEN_STATUSES))
            ->whereNotNull('housekeeper_by_operator_id')
            ->selectRaw('housekeeper_by_operator_id as op, COUNT(*) as c')
            ->groupBy('housekeeper_by_operator_id')
            ->pluck('c', 'op');

        $rows = AssignRoomCleaner::rosterQuery()
            ->get(['id', 'name', 'role', 'tenant_id'])
            ->filter(fn (Operator $o) => $o->hasAccessToBranch($property))
            ->map(fn (Operator $o) => [
                'id' => (int) $o->id,
                'name' => (string) $o->name,
                'role' => $o->role->value,
                'rooms_today' => (int) ($assigned[$o->id] ?? 0),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function assignCleaner(Request $request, int $id, AssignRoomCleaner $assign): JsonResponse
    {
        $data = $request->validate(['operator_id' => ['required', 'integer']]);
        $operator = $this->operator($request);
        $room = $this->findRoom($request, $id);
        if ($room === null) {
            return $this->notFound();
        }
        if (! self::canChangeRooms($operator)) {
            return $this->forbidden();
        }

        $cleaner = AssignRoomCleaner::rosterQuery()->whereKey((int) $data['operator_id'])->first();
        if ($cleaner === null || ! $cleaner->hasAccessToBranch($this->property($request))) {
            return response()->json([
                'error' => 'invalid_cleaner',
                'detail' => 'Pick someone from the housekeeping list.',
            ], 422);
        }

        try {
            DB::transaction(fn () => $assign->handle($room, $cleaner, $operator));
        } catch (Throwable $e) {
            return response()->json(['error' => 'assign_failed', 'detail' => $e->getMessage()], 422);
        }

        return $this->okRoom($request, $room);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $room = $this->findRoom($request, $id);
        if ($room === null) {
            return $this->notFound();
        }
        // Web: /dashboard/housekeeping/rooms/{room} sits under role:housekeeping.
        if (! $this->operator($request)->canAccessModule('housekeeping')) {
            return $this->forbidden();
        }

        return InspectionResource::collection(InspectionsController::roomHistoryInspections($room->id))
            ->response();
    }

    /** Web Housekeeping gate: module access + manager / supervisor. */
    public static function canChangeRooms(Operator $operator): bool
    {
        return $operator->canAccessModule('housekeeping') && SetRoomHousekeepingStatus::canChange($operator);
    }

    /**
     * "Mark clean": a flagged room (latest inspection Failed) is put
     * back in service through ClearRoomFlag like the web room page,
     * then the chip is set Clean like the web board's Mark clean. An
     * open inspection is left as is (web markRoomClean does the same).
     */
    private function markClean(Room $room, Operator $operator, SetRoomHousekeepingStatus $set, ClearRoomFlag $clear, ?string $note): void
    {
        $latest = RoomInspection::query()->where('room_id', $room->id)->latest('id')->first();
        if ($latest?->status === RoomInspectionStatus::Failed) {
            $clear->handle($room, $operator, $note);
        }
        $set->handle($room, $operator, HousekeepingStatus::Clean);
    }

    private function findRoom(Request $request, int $id): ?Room
    {
        return Room::query()
            ->where('property_id', $this->property($request)->id)
            ->where('status', RoomStatus::Active->value)
            ->whereKey($id)
            ->first();
    }

    private function okRoom(Request $request, Room $room): JsonResponse
    {
        $fresh = Room::query()->whereKey($room->id)->get();
        $tile = RoomBoard::tiles($this->property($request), $fresh)[0] ?? null;

        return response()->json(['status' => 'ok', 'room' => $tile]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'detail' => 'Room not found in this branch.'], 404);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'forbidden', 'detail' => 'Your role can not do this.'], 403);
    }
}
