<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingSource;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Drain endpoint for reservations queued offline in IndexedDB. The
 * client sync worker POSTs each queued payload here; we short-circuit
 * duplicates via the client-generated offline_key (CreateBooking
 * checks it before running the write path), so a re-drive after a
 * flaky network won't create the same booking twice.
 *
 * Payload shape mirrors the reserve modal's field set. Wallet payment
 * is deliberately not accepted here — wallet debits need a live server
 * to atomically check + spend the balance, so the sync worker forces
 * offline reservations to cash / transfer / pending before queuing.
 */
class OfflineReservationSyncController extends Controller
{
    public function __invoke(Request $request, CreateBooking $create): JsonResponse
    {
        $data = $this->validated($request);

        // Duplicate short-circuit BEFORE hitting CreateBooking so we
        // can report 'duplicate' without depending on Eloquent's
        // wasRecentlyCreated flag (which fresh() resets).
        $duplicate = Booking::query()->where('offline_key', $data['offline_key'])->first();
        if ($duplicate !== null) {
            return response()->json([
                'status' => 'duplicate',
                'offline_key' => $data['offline_key'],
                'booking_ref' => $duplicate->booking_ref,
            ]);
        }

        $room = Room::query()->find($data['room_id']);
        if ($room === null) {
            return response()->json([
                'status' => 'error',
                'reason' => 'Room not found.',
                'offline_key' => $data['offline_key'],
            ], 422);
        }

        $req = new CreateBookingRequest(
            guestName: $data['guest_name'],
            guestEmail: $data['guest_email'] ?? '',
            guestPhone: $data['guest_phone'],
            adults: (int) $data['adults'],
            children: (int) ($data['children'] ?? 0),
            checkin: Carbon::parse($data['checkin_date']),
            checkout: Carbon::parse($data['checkout_date']),
            paymentType: ($data['payment_status'] ?? 'confirmed') === 'on_hold'
                ? PaymentType::Deposit
                : PaymentType::Full,
            specialRequests: $data['special_requests'] ?? null,
            source: BookingSource::WalkIn,
            sourceNote: $data['source_note'] ?? null,
            autoApplyDiscounts: true,
            breakfastIncluded: (bool) ($data['breakfast_included'] ?? false),
            manualDiscountType: ($data['manual_discount_type'] ?? 'percent') === 'fixed'
                ? ManualDiscountType::Fixed
                : ManualDiscountType::Percent,
            manualDiscountPercent: (float) ($data['manual_discount_percent'] ?? 0),
            manualDiscountAmount: (int) ($data['manual_discount_fixed_input'] ?? 0),
            manualDiscountByOperatorId: (int) $request->user()?->getKey() ?: null,
            offlineKey: $data['offline_key'],
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (RangeNotAvailableException) {
            return response()->json([
                'status' => 'conflict',
                'reason' => 'Room already booked for those dates. Rebook manually with a different room.',
                'offline_key' => $data['offline_key'],
            ], 409);
        } catch (BookingValidationException $e) {
            return response()->json([
                'status' => 'error',
                'reason' => $e->getMessage(),
                'offline_key' => $data['offline_key'],
            ], 422);
        } catch (Throwable) {
            return response()->json([
                'status' => 'error',
                'reason' => 'Unexpected server error while syncing.',
                'offline_key' => $data['offline_key'],
            ], 500);
        }

        return response()->json([
            'status' => 'created',
            'offline_key' => $data['offline_key'],
            'booking_ref' => $booking->booking_ref,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        try {
            return $request->validate([
                'offline_key' => ['required', 'string', 'uuid'],
                'room_id' => ['required', 'integer', 'exists:rooms,id'],
                'guest_name' => ['required', 'string', 'max:120'],
                'guest_phone' => ['required', 'string', 'max:20'],
                'guest_email' => ['nullable', 'string', 'email', 'max:190'],
                'adults' => ['required', 'integer', 'min:1'],
                'children' => ['nullable', 'integer', 'min:0'],
                'checkin_date' => ['required', 'date'],
                'checkout_date' => ['required', 'date', 'after:checkin_date'],
                'special_requests' => ['nullable', 'string', 'max:1000'],
                'source_note' => ['nullable', 'string', 'max:120'],
                'payment_status' => ['nullable', 'in:confirmed,on_hold,pending'],
                'breakfast_included' => ['nullable', 'boolean'],
                'manual_discount_type' => ['nullable', 'in:percent,fixed'],
                'manual_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'manual_discount_fixed_input' => ['nullable', 'integer', 'min:0'],
            ]);
        } catch (ValidationException $e) {
            abort(response()->json([
                'status' => 'error',
                'reason' => collect($e->errors())->flatten()->first() ?? 'Invalid payload.',
                'offline_key' => (string) $request->input('offline_key'),
            ], 422));
        }
    }
}
