<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingChargeType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Operator-driven "charge to room" — record an extra charge against a
 * booking (food, drink, laundry, damage, etc).
 *
 * Two of the BookingChargeType cases are system-only:
 *   - Extension      → produced by ExtendBooking
 *   - DepositForfeit → produced by MarkNoShow
 * This action rejects them; use the dedicated actions instead.
 */
class AddBookingCharge
{
    public function handle(
        Booking $booking,
        Operator $operator,
        BookingChargeType $type,
        string $description,
        int $amount,
    ): BookingCharge {
        if (in_array($type, [BookingChargeType::Extension, BookingChargeType::DepositForfeit], true)) {
            throw new InvalidArgumentException(
                'System-only charge type — use ExtendBooking or MarkNoShow.'
            );
        }

        $description = trim($description);
        if ($description === '') {
            throw new InvalidArgumentException('Charge description cannot be empty.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Charge amount must be positive.');
        }

        return DB::transaction(function () use ($booking, $operator, $type, $description, $amount): BookingCharge {
            $charge = BookingCharge::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'created_by_operator_id' => $operator->getKey(),
                'type' => $type->value,
                'description' => $description,
                'amount' => $amount,
                'settled' => false,
            ]);

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('charge_added')
                ->log(sprintf(
                    'Operator %s added a %s charge to %s: %s ₦%s.',
                    $operator->name,
                    $type->label(),
                    $booking->booking_ref,
                    $description,
                    number_format($amount),
                ));

            return $charge;
        });
    }
}
