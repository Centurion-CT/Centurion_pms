<?php

declare(strict_types=1);

namespace App\Actions\Packages;

use App\Actions\Bookings\BookingValidationException;
use App\Enums\BookingChargeType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\BookingPackage;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

/**
 * Adds a single package to an existing booking from the guest portal
 * (or operator dashboard). For complimentary packages this is just a
 * pivot insert. For paid packages, the line is materialised as a
 * BookingCharge so the existing balance-payment flow handles
 * settlement — no special-case payment plumbing needed.
 */
class AddPackageToBooking
{
    public function handle(Booking $booking, Package $package, int $quantity = 1): BookingPackage
    {
        if ($quantity < 1) {
            throw new BookingValidationException('Quantity must be at least 1.');
        }
        if ($package->tenant_id !== $booking->tenant_id) {
            throw new BookingValidationException('That package does not belong to this property.');
        }
        if (! $package->active) {
            throw new BookingValidationException('That package is no longer offered.');
        }
        if (! $package->available_after_booking) {
            throw new BookingValidationException('That package can only be added at booking time.');
        }

        $alreadyOnBooking = (int) BookingPackage::query()
            ->where('booking_id', $booking->id)
            ->where('package_id', $package->id)
            ->sum('quantity');
        if ($alreadyOnBooking + $quantity > $package->max_per_booking) {
            throw new BookingValidationException(
                sprintf('"%s" allows at most %d per booking.', $package->name, $package->max_per_booking),
            );
        }

        $unitPrice = $package->effectivePrice();
        $lineTotal = $unitPrice * $quantity;

        return DB::transaction(function () use ($booking, $package, $quantity, $unitPrice, $lineTotal): BookingPackage {
            $charge = null;
            if (! $package->is_complimentary && $lineTotal > 0) {
                $charge = BookingCharge::query()->create([
                    'tenant_id'                => $booking->tenant_id,
                    'booking_id'               => $booking->id,
                    'created_by_operator_id'   => null,
                    'type'                     => BookingChargeType::Package,
                    'description'              => $quantity > 1
                        ? sprintf('%s × %d', $package->name, $quantity)
                        : $package->name,
                    'amount'                   => $lineTotal,
                    'settled'                  => false,
                ]);
            }

            return BookingPackage::query()->create([
                'tenant_id'                 => $booking->tenant_id,
                'booking_id'                => $booking->id,
                'package_id'                => $package->id,
                'name_snapshot'             => $package->name,
                'unit_price_snapshot'       => $unitPrice,
                'is_complimentary_snapshot' => $package->is_complimentary,
                'quantity'                  => $quantity,
                'line_total'                => $lineTotal,
                'added_at'                  => 'post_booking',
                'booking_charge_id'         => $charge?->id,
            ]);
        });
    }
}
