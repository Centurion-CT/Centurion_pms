<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Staff;

use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\RoomInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Housekeeper side of the staff app: my rooms, one room's checklist,
 * start, tick, and "Done, send to porter".
 *
 * A cleaner sees open rooms assigned to them plus unassigned ones
 * (nobody assigns rooms yet; starting or ticking one claims it), and
 * keeps today's finished rooms in the list for the progress bar.
 */
class RoomsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $this->housekeeper($request);

        $open = StaffPayload::inspectionsFor($me)
            ->where('status', RoomInspectionStatus::Pending->value)
            ->where(fn ($q) => $q->whereNull('assigned_operator_id')->orWhere('assigned_operator_id', $me->id))
            ->get();

        $done = StaffPayload::inspectionsFor($me)
            ->whereIn('status', [
                RoomInspectionStatus::CleaningDone->value,
                RoomInspectionStatus::PorterVerified->value,
                RoomInspectionStatus::Passed->value,
            ])
            ->where('housekeeper_by_operator_id', $me->id)
            ->where('housekeeper_at', '>=', StaffPayload::todayStart())
            ->orderByDesc('housekeeper_at')
            ->get();

        $arriving = StaffPayload::arrivingFor($open->concat($done));

        // Guest arriving today first, then sent back, then the room that
        // has waited longest.
        $open = $open->sortBy([
            fn ($a, $b) => isset($arriving[$b->room_id]) <=> isset($arriving[$a->room_id]),
            fn ($a, $b) => ($b->sent_back_at !== null) <=> ($a->sent_back_at !== null),
            fn ($a, $b) => $a->created_at <=> $b->created_at,
        ])->values();

        $rooms = [];
        foreach ($open as $idx => $i) {
            $rooms[] = StaffPayload::room($i, $me, $arriving, $idx === 0 && isset($arriving[$i->room_id]));
        }
        foreach ($done as $i) {
            $rooms[] = StaffPayload::room($i, $me, $arriving);
        }

        $ready = $done->whereIn('status', [RoomInspectionStatus::PorterVerified, RoomInspectionStatus::Passed])->count();

        return response()->json(['data' => [
            'progress' => ['ready' => $ready, 'total' => $open->count() + $done->count()],
            'counts' => [
                'to_clean' => $open->count(),
                'with_porter' => $done->where('status', RoomInspectionStatus::CleaningDone)->count(),
            ],
            'rooms' => $rooms,
        ]]);
    }

    public function show(Request $request, int $inspection): JsonResponse
    {
        $me = $this->housekeeper($request);
        $i = $this->find($me, $inspection);
        $arriving = StaffPayload::arrivingFor(collect([$i]));

        return response()->json(['data' => StaffPayload::room($i, $me, $arriving) + [
            'groups' => StaffPayload::groups($i),
            'note' => $i->summary_note,
        ]]);
    }

    public function start(Request $request, int $inspection): JsonResponse
    {
        $me = $this->housekeeper($request);
        $i = $this->find($me, $inspection);
        $this->claim($i, $me);

        return response()->json(['status' => 'ok', 'room' => $this->roomPayload($i->refresh(), $me)]);
    }

    public function tick(Request $request, int $inspection, int $item): JsonResponse
    {
        $me = $this->housekeeper($request);
        $data = $request->validate(['checked' => ['required', 'boolean']]);
        $i = $this->find($me, $inspection);
        $this->claim($i, $me);

        $row = $i->items()->whereKey($item)->firstOrFail();
        $checked = (bool) $data['checked'];
        $row->forceFill([
            'checked' => $checked,
            'checked_at' => $checked ? Carbon::now() : null,
            'checked_by_operator_id' => $checked ? $me->id : null,
        ])->save();

        return response()->json(['status' => 'ok', 'item' => ['id' => $row->id, 'checked' => $checked]]);
    }

    public function finish(Request $request, int $inspection, SubmitHousekeeperCleaning $submit): JsonResponse
    {
        $me = $this->housekeeper($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $i = $this->find($me, $inspection);

        if ($i->status === RoomInspectionStatus::Pending) {
            $this->claim($i, $me);
        } elseif (! ($i->status === RoomInspectionStatus::CleaningDone && $i->housekeeper_by_operator_id === $me->id)) {
            $this->wrongState('This room has already been checked.');
        }

        try {
            $fresh = $submit->handle($i, $me, isset($data['note']) ? trim((string) $data['note']) ?: null : null);
        } catch (RuntimeException $e) {
            $this->wrongState($e->getMessage());
        }

        return response()->json(['status' => 'ok', 'room' => $this->roomPayload($fresh->load(['room.category', 'category', 'items']), $me)]);
    }

    /** @return array<string, mixed> */
    private function roomPayload(RoomInspection $i, Operator $me): array
    {
        return StaffPayload::room($i, $me, StaffPayload::arrivingFor(collect([$i])));
    }

    /**
     * Pending rooms only. Takes an unassigned room for this cleaner and
     * stamps the start time once; a room someone else holds is refused.
     */
    private function claim(RoomInspection $i, Operator $me): void
    {
        if ($i->status !== RoomInspectionStatus::Pending) {
            $this->wrongState(sprintf('Room %s has already been sent to the porter.', $i->room?->room_number ?? ''));
        }
        if ($i->assigned_operator_id !== null && $i->assigned_operator_id !== $me->id) {
            $this->wrongState('Someone else is cleaning this room.');
        }

        $i->forceFill([
            'assigned_operator_id' => $me->id,
            'started_at' => $i->started_at ?? Carbon::now(),
        ])->save();
    }

    private function find(Operator $me, int $id): RoomInspection
    {
        $i = StaffPayload::inspectionsFor($me)->whereKey($id)->first();
        if ($i === null) {
            abort(response()->json(['error' => 'not_found', 'detail' => 'That room is no longer on your list.'], 404));
        }

        return $i;
    }

    private function housekeeper(Request $request): Operator
    {
        /** @var Operator $op */
        $op = $request->user();
        if ($op->role !== OperatorRole::HouseCleaner) {
            abort(response()->json(['error' => 'housekeepers_only', 'detail' => 'Only housekeepers clean rooms in the app.'], 403));
        }

        return $op;
    }

    private function wrongState(string $detail): never
    {
        abort(response()->json(['error' => 'wrong_state', 'detail' => $detail], 409));
    }
}
