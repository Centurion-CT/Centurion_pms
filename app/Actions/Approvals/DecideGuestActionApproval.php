<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Bookings\ExtendBooking;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Outlet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approve / reject a guest action the geo-fence gate held back
 * (guest_action_approvals). Approve runs the underlying action from
 * the payload snapshot; reject leaves the booking untouched.
 *
 * Extracted from the web Approvals queue so the web page and the host
 * app inbox run the exact same code. Race-safe: the approval row is
 * re-read under a lock inside the transaction, and a row that is no
 * longer pending throws {@see AlreadyDecidedException}. If the payload
 * fails the whole decision rolls back and the row stays pending.
 *
 * Payload kinds:
 *   extend / extend_card     -> ExtendBooking
 *   checkout                 -> MarkCheckedOut
 *   order_room / order_card  -> PosSale + CompletePosSale(tender=room)
 *   reserve                  -> GuestRequest for the desk to work
 *   request:<kind>           -> GuestRequest row
 *   checkin_no_payment       -> Pending -> Confirmed + desk note
 */
class DecideGuestActionApproval
{
    /**
     * @return GuestActionApproval the resolved row. Status is
     *                             `approved`, or `rejected` when the booking no longer exists.
     */
    public function approve(GuestActionApproval $approval, Operator $operator): GuestActionApproval
    {
        return DB::transaction(function () use ($approval, $operator): GuestActionApproval {
            $locked = $this->lockPending($approval);

            $booking = Booking::query()->find($locked->booking_id);
            if ($booking === null) {
                return $this->markResolved($locked, $operator, 'rejected', 'Booking no longer exists.');
            }

            $this->executePayload($locked, $booking, $operator);

            return $this->markResolved($locked, $operator, 'approved', null);
        });
    }

    public function reject(GuestActionApproval $approval, Operator $operator, ?string $note): GuestActionApproval
    {
        return DB::transaction(function () use ($approval, $operator, $note): GuestActionApproval {
            $locked = $this->lockPending($approval);
            $note = $note !== null ? trim($note) : null;

            return $this->markResolved($locked, $operator, 'rejected', $note !== '' ? $note : null);
        });
    }

    /** Success copy per kind (web toast + host app). */
    public static function approvedMessage(string $kind): string
    {
        return match ($kind) {
            'checkin_no_payment' => 'Booking is now Confirmed and cleared for check-in without payment. Front desk notified.',
            'checkout' => 'Guest checked out.',
            'extend', 'extend_card' => 'Stay extended.',
            'order_room', 'order_card' => 'Order landed on the room folio.',
            'reserve' => 'Reservation request queued for the desk.',
            default => 'Guest action executed.',
        };
    }

    private function lockPending(GuestActionApproval $approval): GuestActionApproval
    {
        $locked = GuestActionApproval::query()->lockForUpdate()->find($approval->getKey());
        if ($locked === null || ! $locked->isPending()) {
            throw new AlreadyDecidedException;
        }

        return $locked;
    }

    private function markResolved(GuestActionApproval $approval, Operator $operator, string $status, ?string $note): GuestActionApproval
    {
        $approval->forceFill([
            'status' => $status,
            'approved_by_operator_id' => $operator->getKey(),
            'operator_note' => $note,
            'resolved_at' => Carbon::now(),
        ])->save();

        activity('guest_action_approval')
            ->causedBy($operator)
            ->performedOn($approval)
            ->withProperties(['kind' => $approval->kind, 'status' => $status])
            ->log("Approval {$approval->id} {$status}.");

        return $approval;
    }

    private function executePayload(GuestActionApproval $approval, Booking $booking, Operator $operator): void
    {
        $payload = is_array($approval->payload) ? $approval->payload : [];
        $kind = $approval->kind;

        // Requests are just DB rows - no external side effects, we
        // recreate them exactly as the portal would have.
        if (str_starts_with($kind, 'request:')) {
            GuestRequest::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'kind' => (string) ($payload['kind'] ?? substr($kind, 8)),
                'status' => 'open',
                'notes' => $payload['notes'] ?? null,
                'body' => $payload['body'] ?? [],
            ]);

            return;
        }

        match ($kind) {
            'extend' => $this->executeExtend($booking, $operator, $payload),
            'extend_card' => $this->executeExtendCard($booking, $operator, $payload),
            'checkout' => app(MarkCheckedOut::class)->handle($booking, $operator),
            'order_room' => $this->executeOrderRoom($booking, $operator, $payload),
            'order_card' => $this->executeOrderCard($booking, $operator, $payload),
            'reserve' => $this->executeReserve($booking, $operator, $payload),
            'checkin_no_payment' => $this->executeCheckinNoPayment($booking, $operator),
            default => throw new RuntimeException("Unknown approval kind: {$kind}"),
        };
    }

    /**
     * Manager approved check-in without payment: Pending -> Confirmed
     * with balance_due = total_amount, plus a front-desk note. The
     * actual check-in is NOT run here (the room usually has not
     * cleared housekeeping yet); the normal Check in button does it.
     */
    private function executeCheckinNoPayment(Booking $booking, Operator $operator): void
    {
        if ($booking->booking_status !== BookingStatus::Pending) {
            throw new RuntimeException(
                "Booking is no longer Pending (state: {$booking->booking_status->value}). "
                ."If it's already Checked in, no action needed.",
            );
        }
        DB::transaction(function () use ($booking, $operator): void {
            $booking->forceFill([
                'booking_status' => BookingStatus::Confirmed,
                'balance_due' => (int) $booking->total_amount,
            ])->save();

            GuestRequest::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'kind' => 'custom',
                'status' => 'open',
                'notes' => sprintf(
                    'Manager approved check-in without payment (₦%s on the folio). '
                    .'Proceed with normal check-in once the room clears housekeeping.',
                    number_format((int) $booking->total_amount),
                ),
                'body' => [
                    'source' => 'checkin_no_payment_approval',
                    'approved_by_operator_id' => $operator->getKey(),
                    'approved_by_operator_name' => $operator->name,
                    'balance_owed' => (int) $booking->total_amount,
                ],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeExtend(Booking $booking, Operator $operator, array $payload): void
    {
        $newCheckout = Carbon::parse((string) ($payload['new_checkout_date'] ?? ''));
        // The approval may have sat in the queue while another booking
        // took the range.
        $max = $booking->maxExtendableCheckoutDate();
        if ($max !== null && $newCheckout->gt($max)) {
            throw new RuntimeException(
                'Room is now reserved from '.$max->format('D, d M').' - extension no longer possible.',
            );
        }
        app(ExtendBooking::class)->handle(
            $booking, $newCheckout, $operator,
            breakfastIncluded: (bool) ($payload['breakfast_included'] ?? false),
        );
    }

    /**
     * Card branch: the desk can't charge the guest's card, so approval
     * runs the extend directly (charges land on the folio).
     *
     * @param  array<string, mixed>  $payload
     */
    private function executeExtendCard(Booking $booking, Operator $operator, array $payload): void
    {
        $newCheckout = Carbon::parse((string) ($payload['new_checkout_date'] ?? ''));
        app(ExtendBooking::class)->handle(
            $booking, $newCheckout, $operator,
            breakfastIncluded: (bool) ($payload['breakfast_included'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeOrderRoom(Booking $booking, Operator $operator, array $payload): void
    {
        $outlet = Outlet::query()->findOrFail((int) ($payload['outlet_id'] ?? 0));
        $cart = [];
        foreach ((array) ($payload['cart'] ?? []) as $productId => $qty) {
            if ((int) $qty > 0) {
                $cart[] = ['product_id' => (int) $productId, 'quantity' => (int) $qty];
            }
        }
        if ($cart === []) {
            throw new RuntimeException('Order cart was empty.');
        }
        $sale = app(CreatePosSaleDraft::class)->handle(
            outlet: $outlet, operator: $operator, cart: $cart,
            bookingId: $booking->id, customerId: $booking->customer_id,
            notes: 'Room service (approved from queue)'.
                (! empty($payload['notes']) ? ' · '.$payload['notes'] : ''),
        );
        app(CompletePosSale::class)->handle(sale: $sale, operator: $operator, method: 'room');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeOrderCard(Booking $booking, Operator $operator, array $payload): void
    {
        $this->executeOrderRoom($booking, $operator, $payload);
    }

    /**
     * Card-first flow: raise a GuestRequest so the desk works the new
     * reservation manually with the guest on the phone.
     *
     * @param  array<string, mixed>  $payload
     */
    private function executeReserve(Booking $booking, Operator $operator, array $payload): void
    {
        GuestRequest::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'room_id' => $booking->room_id,
            'kind' => 'other',
            'status' => 'open',
            'notes' => 'Reserve-another-room approval - call guest to take payment.',
            'body' => [
                'category_id' => $payload['category_id'] ?? null,
                'checkin' => $payload['checkin'] ?? null,
                'checkout' => $payload['checkout'] ?? null,
                'adults' => $payload['adults'] ?? 1,
                'children' => $payload['children'] ?? 0,
                'amount' => $payload['amount'] ?? null,
            ],
        ]);
    }
}
