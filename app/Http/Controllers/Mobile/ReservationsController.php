<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\MarkNoShow;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\ReservationResource;
use App\Models\Booking;
use App\Models\Operator;
use App\Services\Payments\BookingPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Reservation-manager surface for the mobile app. All writes
 * delegate to the same action classes the web Livewire components
 * use so business rules can't fork between web and mobile.
 *
 * Idempotency (via MobileIdempotency middleware) protects every
 * mutation — a phone that queued check-in offline can retry
 * indiscriminately without double-charging the deposit or
 * flipping the room state twice.
 */
class ReservationsController extends Controller
{
    /**
     * filter=arrivals   — today's expected check-ins (Confirmed | OnHold + checkin_date=today)
     * filter=in_house   — currently checked in
     * filter=departures — today's expected check-outs (CheckedIn + checkout_date=today)
     * filter=all        — every non-terminal booking (default)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['arrivals', 'in_house', 'departures', 'all'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $filter = $data['filter'] ?? 'all';

        $today = Carbon::today();

        $query = Booking::query()
            ->with(['room.category', 'customer'])
            ->orderBy('checkin_date');

        if ($filter === 'arrivals') {
            $query
                ->whereDate('checkin_date', $today->toDateString())
                ->whereIn('booking_status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::OnHold->value,
                    BookingStatus::Pending->value,
                ]);
        } elseif ($filter === 'in_house') {
            $query->where('booking_status', BookingStatus::CheckedIn->value);
        } elseif ($filter === 'departures') {
            $query
                ->where('booking_status', BookingStatus::CheckedIn->value)
                ->whereDate('checkout_date', $today->toDateString());
        } else {
            $query->whereNotIn('booking_status', [
                BookingStatus::CheckedOut->value,
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ]);
        }

        if (! empty($data['q'])) {
            $needle = '%'.mb_strtolower($data['q']).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(guest_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(booking_ref) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(guest_email) LIKE ?', [$needle]);
            });
        }

        return ReservationResource::collection($query->limit(100)->get());
    }

    public function show(string $ref): ReservationResource
    {
        $booking = $this->findByRef($ref);

        return new ReservationResource($booking);
    }

    public function checkIn(string $ref, MarkCheckedIn $action): JsonResponse
    {
        return $this->run($ref, fn (Booking $b, Operator $op) => $action->handle($b, $op));
    }

    public function checkOut(string $ref, MarkCheckedOut $action): JsonResponse
    {
        return $this->run($ref, fn (Booking $b, Operator $op) => $action->handle($b, $op));
    }

    public function noShow(Request $request, string $ref, MarkNoShow $action): JsonResponse
    {
        $data = $request->validate([
            'forfeit_deposit' => ['nullable', 'boolean'],
        ]);

        return $this->run(
            $ref,
            fn (Booking $b, Operator $op) => $action->handle($b, $op, (bool) ($data['forfeit_deposit'] ?? true)),
        );
    }

    public function cancel(Request $request, string $ref, CancelBooking $action): JsonResponse
    {
        $data = $request->validate([
            'refund_deposit' => ['nullable', 'boolean'],
        ]);

        return $this->run(
            $ref,
            fn (Booking $b, Operator $op) => $action->handle($b, $op, refundRequested: (bool) ($data['refund_deposit'] ?? false)),
        );
    }

    /**
     * Record a cash / transfer / card / POS / other payment of `amount`
     * handed over NOW. Goes through the shared payment layer
     * ({@see BookingPayments::take()}): below the outstanding it is a
     * part payment, at or above it settles everything (wallet first).
     * A Pending booking is confirmed by the payment
     * ({@see BookingPayments::confirmAndPay()}).
     *
     * Before this used RecordManualPayment, whose amount is a running
     * total, so a second payment smaller than what was already paid
     * was silently dropped.
     */
    public function recordPayment(Request $request, string $ref, BookingPayments $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['cash', 'transfer', 'card', 'pos', 'other'])],
            'reference_note' => ['nullable', 'string', 'max:500'],
        ]);

        $booking = $this->findByRef($ref);
        $operator = $this->operator($request);

        try {
            if ($booking->booking_status === BookingStatus::Pending) {
                $payments->confirmAndPay($booking, $operator, (int) $data['amount'], $data['method'], $data['reference_note'] ?? null);
            } else {
                $payments->take($booking, $operator, (int) $data['amount'], $data['method'], $data['reference_note'] ?? null);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'payment_failed', 'detail' => $e->getMessage()], 422);
        }

        $fresh = $booking->fresh(['room.category', 'customer', 'charges', 'payments']);

        return response()->json([
            'status' => 'ok',
            'reservation' => new ReservationResource($fresh),
        ]);
    }

    // ── helpers ──

    private function findByRef(string $ref): Booking
    {
        return Booking::query()
            ->with(['room.category', 'customer', 'charges', 'payments'])
            ->where('booking_ref', $ref)
            ->firstOrFail();
    }

    private function operator(Request $request): Operator
    {
        $op = $request->user();
        if (! $op instanceof Operator) {
            abort(401);
        }

        return $op;
    }

    /**
     * Shared plumbing for check-in / check-out / no-show / cancel:
     * hydrate the booking, hand to the action, return the fresh
     * ReservationResource. Action-level exceptions surface as 422 so
     * the app can show the operator "you can only check-in an arrival
     * that's Confirmed/OnHold today" and similar messages verbatim.
     */
    private function run(string $ref, callable $handler): JsonResponse
    {
        $booking = $this->findByRef($ref);
        $operator = $this->operator(request());
        try {
            $handler($booking, $operator);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'action_failed', 'detail' => $e->getMessage()], 422);
        }

        $fresh = $booking->fresh(['room.category', 'customer', 'charges', 'payments']);

        return response()->json([
            'status' => 'ok',
            'reservation' => new ReservationResource($fresh),
        ]);
    }
}
