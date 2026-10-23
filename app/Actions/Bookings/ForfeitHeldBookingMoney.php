<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Mail\Bookings\DepositKeptMail;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Services\Bookings\HeldMoney;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * "Keep as forfeit" on the Refunds page: the host keeps money still
 * held on a cancelled / no-show booking instead of refunding it.
 *
 * Recorded as a settled DepositForfeit charge (settled_method =
 * held_forfeit) so it shows on the booking ledger, counts as billed
 * revenue on the accrual P&L, and clears the held balance. Cash-basis
 * figures are untouched: the money was already counted when it was
 * collected. The guest is emailed when an address is on file.
 */
class ForfeitHeldBookingMoney
{
    public function __construct(private readonly HeldMoney $heldMoney) {}

    public function handle(Booking $booking, Operator $operator, int $amount, string $reason): BookingCharge
    {
        $reason = trim($reason);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Forfeit amount must be greater than zero.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Add a short reason for keeping the money.');
        }
        if (! in_array($booking->booking_status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            throw new InvalidArgumentException('Only cancelled or no-show bookings can have money kept as forfeit.');
        }

        $charge = DB::transaction(function () use ($booking, $operator, $amount, $reason): BookingCharge {
            // Serialise concurrent decisions on the same booking (web
            // Refunds page + host app inbox) so held is read once.
            Booking::query()->lockForUpdate()->whereKey($booking->id)->first();
            $held = $this->heldMoney->byBooking([$booking->id])[$booking->id]['held'] ?? 0;
            if ($amount > $held) {
                throw new InvalidArgumentException('Held is ₦'.number_format($held).'. Enter that or less.');
            }

            $charge = BookingCharge::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'created_by_operator_id' => $operator->getKey(),
                'type' => BookingChargeType::DepositForfeit->value,
                'description' => 'Kept as forfeit · '.$reason,
                'amount' => $amount,
                'settled' => true,
                'settled_at' => Carbon::now(),
                'settled_method' => HeldMoney::FORFEIT_METHOD,
            ]);

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('held_forfeit')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'amount' => $amount,
                    'reason' => $reason,
                    'charge_id' => $charge->id,
                ])
                ->log("Operator {$operator->name} kept ₦".number_format($amount)." as forfeit on booking {$booking->booking_ref}.");

            DashboardSnapshot::forget();

            return $charge;
        });

        if (filled($booking->guest_email)) {
            Mail::to($booking->guest_email)->queue(new DepositKeptMail($booking, $amount, $reason));
        }

        return $charge;
    }
}
