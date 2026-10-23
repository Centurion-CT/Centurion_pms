<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Staff;

use App\Enums\RoomInspectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\Property;
use App\Models\RoomInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in (for the greeting) and their week (for Me).
 */
class SessionController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        /** @var Operator $me */
        $me = $request->user();
        $now = StaffPayload::now();
        $propertyId = $me->accessibleBranchIds()[0] ?? null;
        $property = $propertyId !== null
            ? Property::query()->find($propertyId)
            : Property::query()->orderBy('id')->first();

        return response()->json(['data' => [
            'operator' => StaffPayload::operator($me),
            'property' => $property === null ? null : ['id' => $property->id, 'name' => $property->name],
            'shift_label' => match (true) {
                $now->hour >= 5 && $now->hour < 12 => 'Morning shift',
                $now->hour >= 12 && $now->hour < 18 => 'Afternoon shift',
                default => 'Night shift',
            },
            'date_label' => $now->format('l j M'),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Operator $me */
        $me = $request->user();
        $weekStart = StaffPayload::now()->startOfWeek()->utc();

        $cleaned = RoomInspection::query()
            ->where('housekeeper_by_operator_id', $me->id)
            ->where('housekeeper_at', '>=', $weekStart)
            ->whereNot('status', RoomInspectionStatus::Pending->value)
            ->get(['id', 'started_at', 'housekeeper_at', 'send_back_count']);

        $minutes = collect();
        foreach ($cleaned as $i) {
            if ($i->started_at !== null && $i->housekeeper_at !== null && $i->housekeeper_at->gte($i->started_at)) {
                $minutes->push($i->started_at->diffInMinutes($i->housekeeper_at));
            }
        }

        return response()->json(['data' => [
            'operator' => StaffPayload::operator($me),
            'week' => [
                'rooms_cleaned' => $cleaned->count(),
                'rooms_checked' => RoomInspection::query()
                    ->where('porter_by_operator_id', $me->id)
                    ->where('porter_at', '>=', $weekStart)
                    ->count(),
                'avg_minutes' => $minutes->isEmpty() ? null : (int) round((float) $minutes->avg()),
                'sent_back' => $cleaned->where('send_back_count', '>', 0)->count(),
            ],
        ]]);
    }
}
