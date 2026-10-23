<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Multitenancy\TenantScope;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a not-yet-arrived booking that is pinned to a room to new dates
 * (and optionally another room of the same branch): the desk calendar
 * drag. Category holds use {@see EditReservation} instead, which owns
 * the category re-block.
 *
 * Price: the room part is repriced as the difference between today's
 * price of the old stay and of the new stay (same PricingCalculator the
 * booking flow uses, with the guest's percent discount carried), so
 * fees, packages and fixed discounts stay as booked. balance_due only
 * moves once the guest has paid something, as in EditReservation.
 *
 * Atomic: old nights are released and the new ones reserved in one
 * transaction; a clash rolls everything back (RangeNotAvailableException).
 */
class ChangeBookingDates
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingCalculator $pricing,
    ) {}

    /**
     * @return array{booking: Booking, old_total: int, new_total: int, delta: int}
     *
     * @throws RuntimeException
     * @throws RangeNotAvailableException
     */
    public function handle(Booking $booking, Carbon $checkin, Carbon $checkout, ?Room $room, Operator $operator): array
    {
        if (! in_array($booking->booking_status, [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold], true)) {
            throw new RuntimeException('Only bookings that have not arrived yet can change dates.');
        }
        $oldRoom = $booking->room;
        if ($oldRoom === null) {
            throw new RuntimeException('This booking has no room yet.');
        }
        $room ??= $oldRoom;
        if ($room->status !== RoomStatus::Active) {
            throw new RuntimeException('That room is not in service.');
        }

        $checkin = $checkin->copy()->startOfDay();
        $checkout = $checkout->copy()->startOfDay();
        if ($checkout->lte($checkin)) {
            throw new RuntimeException('Check-out must be after check-in.');
        }
        if ($checkin->lt(Carbon::today())) {
            throw new RuntimeException('Check-in can not be in the past.');
        }

        $oldIn = $booking->checkin_date->copy()->startOfDay();
        $oldOut = $booking->checkout_date->copy()->startOfDay();
        if ($checkin->equalTo($oldIn) && $checkout->equalTo($oldOut) && $room->id === $oldRoom->id) {
            throw new RuntimeException('Nothing changed.');
        }

        if (! $this->availability->isAvailable($room, $checkin, $checkout, (int) $booking->id)) {
            throw new RangeNotAvailableException('The room is not free for those dates.');
        }

        $breakfast = (bool) $booking->breakfast_included;
        $percent = $booking->manual_discount_type === ManualDiscountType::Percent
            ? max(0.0, min(100.0, (float) $booking->manual_discount_percent))
            : 0.0;
        $price = function (Room $r, Carbon $in, Carbon $out) use ($breakfast, $percent): int {
            $net = $this->pricing->calculate($r, $in, $out, breakfastIncluded: $breakfast)->netSubtotal;

            return max(0, $net - (int) floor($net * $percent / 100));
        };
        $delta = $price($room, $checkin, $checkout) - $price($oldRoom, $oldIn, $oldOut);
        $oldTotal = (int) $booking->total_amount;

        return DB::transaction(function () use ($booking, $room, $oldRoom, $checkin, $checkout, $oldIn, $oldOut, $delta, $oldTotal, $operator): array {
            AvailabilityBlock::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('booking_id', $booking->id)
                ->where('type', AvailabilityBlockType::Booked->value)
                ->delete();

            $this->availability->reserveRangeForBooking($room, $checkin, $checkout, (int) $booking->id);

            $nights = (int) $checkin->diffInDays($checkout, absolute: true);
            $isSettled = $booking->payment_status !== PaymentStatus::Pending;
            $newTotal = max(0, $oldTotal + $delta);

            $booking->forceFill([
                'room_id' => $room->id,
                'room_category_id' => $room->room_category_id ?? $booking->room_category_id,
                'checkin_date' => $checkin->toDateString(),
                'checkout_date' => $checkout->toDateString(),
                'nights' => $nights,
                'room_total' => max(0, (int) $booking->room_total + $delta),
                'total_amount' => $newTotal,
                'balance_due' => $isSettled ? max(0, (int) $booking->balance_due + $delta) : (int) $booking->balance_due,
            ])->save();

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('dates_changed')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'old_checkin' => $oldIn->toDateString(),
                    'old_checkout' => $oldOut->toDateString(),
                    'new_checkin' => $checkin->toDateString(),
                    'new_checkout' => $checkout->toDateString(),
                    'old_room' => $oldRoom->room_number,
                    'new_room' => $room->room_number,
                    'old_total' => $oldTotal,
                    'new_total' => $newTotal,
                    'delta' => $delta,
                ])
                ->log(sprintf(
                    'Dates changed from %s to %s%s (new total ₦%s).',
                    $oldIn->format('j M').' - '.$oldOut->format('j M'),
                    $checkin->format('j M').' - '.$checkout->format('j M'),
                    $room->id !== $oldRoom->id ? ', room '.$oldRoom->room_number.' to '.$room->room_number : '',
                    number_format($newTotal),
                ));

            return ['booking' => $booking->refresh(), 'old_total' => $oldTotal, 'new_total' => $newTotal, 'delta' => $delta];
        });
    }
}
