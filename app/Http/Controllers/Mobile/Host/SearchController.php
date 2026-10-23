<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Room;
use App\Services\Search\DashboardSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Host app search (docs/host-app/PHASE-3.md, 29). Bookings and guests
 * use the web topbar search matching rules ({@see DashboardSearch});
 * rooms match by number prefix in the branch. Max 5 per group.
 */
class SearchController extends HostController
{
    private const LIMIT = 5;

    public function __invoke(Request $request, DashboardSearch $search): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $q = trim((string) ($data['q'] ?? ''));
        $empty = ['guests' => [], 'bookings' => [], 'rooms' => []];
        if (mb_strlen($q) < DashboardSearch::MIN_LENGTH) {
            return response()->json(['data' => $empty]);
        }

        $operator = $this->operator($request);
        $property = $this->property($request);

        // Guests: only when the operator can see the Guests module (web rule).
        $guests = [];
        if ($search->canSeeModule($operator, 'customers', $property)) {
            $guests = $search->customers($q, self::LIMIT)
                ->map(function (Customer $c): array {
                    $bookings = $c->bookings();

                    return [
                        'customer_id' => (int) $c->id,
                        'name' => (string) $c->name,
                        'phone' => $c->phone,
                        'stays_count' => (int) (clone $bookings)->count(),
                        'last_ref' => (clone $bookings)->orderByDesc('checkin_date')->orderByDesc('id')->value('booking_ref'),
                    ];
                })->values()->all();
        }

        // Bookings: same matching as the web search, tied to the branch the
        // same way as host/bookings (its rooms + category-only bookings).
        $bookings = $search->bookings(
            $q,
            (int) $property->id,
            self::LIMIT,
            fn (Builder $query) => BookingPresenter::scopeToBranch($query, $property),
        );
        $bookings->load(['room.category', 'roomCategory', 'corporate']);

        $needle = mb_strtolower($q);
        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active->value)
            ->whereRaw('LOWER(room_number) like ?', [$needle.'%'])
            ->orderBy('room_number')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => [
            'guests' => $guests,
            'bookings' => $bookings->map(fn (Booking $b) => BookingPresenter::card($b))->values()->all(),
            'rooms' => array_map(fn (array $t) => [
                'id' => $t['id'],
                'number' => $t['number'],
                'category' => $t['category'],
                'tile_state' => $t['tile_state'],
            ], RoomBoard::tiles($property, $rooms)),
        ]]);
    }
}
