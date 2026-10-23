<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Staff;

use App\Actions\Housekeeping\SendRoomBack;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\RoomInspection;
use App\Support\Housekeeping\ChecklistPictures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Porter side of the staff app: rooms waiting for a check, one room's
 * check, "Room is ready" and "Send back".
 */
class ChecksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $this->porter($request);

        $waiting = StaffPayload::inspectionsFor($me)
            ->where('status', RoomInspectionStatus::CleaningDone->value)
            ->get();
        $arriving = StaffPayload::arrivingFor($waiting);

        $waiting = $waiting->sortBy([
            fn ($a, $b) => isset($arriving[$b->room_id]) <=> isset($arriving[$a->room_id]),
            fn ($a, $b) => $a->housekeeper_at <=> $b->housekeeper_at,
        ])->values();

        $checked = StaffPayload::inspectionsFor($me)
            ->where('porter_by_operator_id', $me->id)
            ->where('porter_at', '>=', StaffPayload::todayStart())
            ->count();

        return response()->json(['data' => [
            'progress' => ['checked' => $checked, 'total' => $checked + $waiting->count()],
            'rooms' => $waiting->map(fn (RoomInspection $i) => StaffPayload::check($i, $arriving))->all(),
        ]]);
    }

    public function show(Request $request, int $inspection): JsonResponse
    {
        $me = $this->porter($request);
        $i = $this->find($me, $inspection);

        return response()->json(['data' => StaffPayload::check($i, StaffPayload::arrivingFor(collect([$i]))) + [
            'items' => $i->items
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($item) => [
                    'label' => $item->label,
                    'icon' => ChecklistPictures::icon($item->label),
                    'checked' => (bool) $item->checked,
                ])->values()->all(),
        ]]);
    }

    public function ready(Request $request, int $inspection, VerifyPorterInspection $verify): JsonResponse
    {
        $me = $this->porter($request);
        $i = $this->find($me, $inspection);

        try {
            $verify->handle($i, $me, accept: true);
        } catch (RuntimeException) {
            $this->wrongState(sprintf('Room %s has already been checked.', $i->room?->room_number ?? ''));
        }

        return response()->json(['status' => 'ok']);
    }

    public function sendBack(Request $request, int $inspection, SendRoomBack $sendBack): JsonResponse
    {
        $me = $this->porter($request);
        $data = $request->validate([
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['string', Rule::in(array_keys(ChecklistPictures::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $i = $this->find($me, $inspection);

        try {
            $sendBack->handle($i, $me, array_values($data['reasons']), $data['note'] ?? null);
        } catch (RuntimeException) {
            $this->wrongState(sprintf('Room %s has already been checked.', $i->room?->room_number ?? ''));
        }

        return response()->json(['status' => 'ok']);
    }

    private function find(Operator $me, int $id): RoomInspection
    {
        $i = StaffPayload::inspectionsFor($me)->whereKey($id)->first();
        if ($i === null) {
            abort(response()->json(['error' => 'not_found', 'detail' => 'That room is no longer waiting for a check.'], 404));
        }

        return $i;
    }

    private function porter(Request $request): Operator
    {
        /** @var Operator $op */
        $op = $request->user();
        if ($op->role !== OperatorRole::Porter) {
            abort(response()->json(['error' => 'porters_only', 'detail' => 'Only porters check rooms in the app.'], 403));
        }

        return $op;
    }

    private function wrongState(string $detail): never
    {
        abort(response()->json(['error' => 'wrong_state', 'detail' => $detail], 409));
    }
}
