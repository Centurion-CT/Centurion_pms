<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\RecordManualPayment;
use App\Enums\BookingSource;
use App\Enums\OperatorRole;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\AvailableRoomResource;
use App\Http\Resources\Mobile\ReservationResource;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Bookings\RoomAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Manual booking creation from the mobile app — the walk-in flow.
 * Two endpoints:
 *
 *   GET /rooms/available?checkin=&checkout=&adults=&children=
 *       Returns the rooms available for the date range, filtered by
 *       category capacity (adults + children fit). Each row carries
 *       enough info for the mobile UI to render a pick-list + rate
 *       + breakfast option without further round-trips.
 *
 *   POST /bookings
 *       Creates the booking via the existing CreateBooking action.
 *       Body mirrors CreateBookingRequest with one extra field
 *       (settle_now) that captures a same-time deposit/full payment
 *       — the same convenience the web ManualCreate offers. Every
 *       validation the action does (min-nights, availability,
 *       package validity, discount code, breakfast tier) fires
 *       here too.
 *
 * Reservation Officer / Frontdesk / Manager can create bookings.
 * Other roles get 403.
 */
class BookingsController extends Controller
{
    private const ALLOWED_ROLES = [
        OperatorRole::Manager,
        OperatorRole::ReservationOfficer,
        OperatorRole::Frontdesk,
    ];

    public function availableRooms(Request $request, RoomAllocator $allocator): AnonymousResourceCollection
    {
        $data = $request->validate([
            'checkin' => ['required', 'date_format:Y-m-d'],
            'checkout' => ['required', 'date_format:Y-m-d', 'after:checkin'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        $this->guardRole($this->operator($request));

        $property = Tenant::current()?->property;
        if ($property === null) {
            return AvailableRoomResource::collection(collect());
        }

        $checkin = Carbon::parse($data['checkin']);
        $checkout = Carbon::parse($data['checkout']);
        $adults = (int) ($data['adults'] ?? 1);
        $children = (int) ($data['children'] ?? 0);

        // RoomAllocator returns every available room; filter down to
        // ones whose category actually fits the party. Rooms with no
        // category (edge case) fall through as unfittable.
        $rooms = $allocator->availableRoomsInProperty(
            $property->id,
            $checkin,
            $checkout,
        )->filter(function ($room) use ($adults, $children) {
            $cat = $room->category;
            if ($cat === null) return false;
            return (int) $cat->capacity_adults >= $adults
                && (int) $cat->capacity_children >= $children;
        })->values();

        return AvailableRoomResource::collection($rooms);
    }

    public function create(Request $request, CreateBooking $create, RecordManualPayment $recordPayment): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_email' => ['required', 'email', 'max:180'],
            'guest_phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{8,}$/'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['required', 'integer', 'min:0', 'max:20'],
            'checkin' => ['required', 'date_format:Y-m-d'],
            'checkout' => ['required', 'date_format:Y-m-d', 'after:checkin'],
            'payment_type' => ['required', Rule::in(['full', 'deposit'])],
            'source' => ['nullable', 'string'],
            'source_note' => ['nullable', 'string', 'max:120'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'breakfast_included' => ['nullable', 'boolean'],
            'discount_code' => ['nullable', 'string', 'max:60'],
            // Settle at the same time as create. Optional; when set,
            // record a manual payment against the fresh booking so
            // the operator doesn't have to hop screens.
            'settle_now' => ['nullable', 'array'],
            'settle_now.amount' => ['required_with:settle_now', 'integer', 'min:1'],
            'settle_now.method' => ['required_with:settle_now', Rule::in(['cash', 'transfer', 'card', 'pos', 'other'])],
            'settle_now.reference_note' => ['nullable', 'string', 'max:200'],
        ]);

        $operator = $this->operator($request);
        $this->guardRole($operator);

        $room = \App\Models\Room::query()->findOrFail($data['room_id']);

        $req = new CreateBookingRequest(
            guestName: $data['guest_name'],
            guestEmail: $data['guest_email'],
            guestPhone: $data['guest_phone'],
            adults: $data['adults'],
            children: $data['children'],
            checkin: Carbon::parse($data['checkin']),
            checkout: Carbon::parse($data['checkout']),
            paymentType: $data['payment_type'] === 'full' ? PaymentType::Full : PaymentType::Deposit,
            specialRequests: $data['special_requests'] ?? null,
            source: $this->resolveSource($data['source'] ?? null),
            sourceNote: $data['source_note'] ?? null,
            discountCode: $data['discount_code'] ?? null,
            breakfastIncluded: (bool) ($data['breakfast_included'] ?? false),
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'create_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        // Optional same-time payment. Failures here don't undo the
        // booking (it stays on the ledger, unsettled — matches web
        // ManualCreate behaviour) so the operator can retry from the
        // reservation detail.
        if (! empty($data['settle_now'])) {
            try {
                $recordPayment->handle(
                    booking: $booking,
                    operator: $operator,
                    amount: (int) $data['settle_now']['amount'],
                    method: (string) $data['settle_now']['method'],
                    referenceNote: $data['settle_now']['reference_note'] ?? null,
                );
            } catch (\Throwable $e) {
                // Log and continue — booking still returned.
                report($e);
            }
        }

        $fresh = $booking->fresh(['room.category', 'customer', 'charges', 'payments']);

        return response()->json([
            'status' => 'ok',
            'reservation' => new ReservationResource($fresh),
        ]);
    }

    // ── helpers ──

    private function guardRole(Operator $operator): void
    {
        if (! in_array($operator->role, self::ALLOWED_ROLES, true)) {
            abort(response()->json(['error' => 'forbidden'], 403));
        }
    }

    private function operator(Request $request): Operator
    {
        $op = $request->user();
        if (! $op instanceof Operator) {
            abort(401);
        }

        return $op;
    }

    private function resolveSource(?string $raw): BookingSource
    {
        return match ($raw) {
            'walk_in' => BookingSource::WalkIn,
            'phone' => BookingSource::Phone,
            'whatsapp' => BookingSource::WhatsApp,
            'other' => BookingSource::Other,
            default => BookingSource::WalkIn,
        };
    }
}
