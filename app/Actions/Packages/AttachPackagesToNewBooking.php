<?php

declare(strict_types=1);

namespace App\Actions\Packages;

use App\Actions\Bookings\BookingValidationException;
use App\Models\Booking;
use App\Models\BookingPackage;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * Persists a guest's package selection at booking time. Called from
 * inside the CreateBooking transaction so a package validation failure
 * rolls back the whole booking.
 *
 * Returns the sum of paid lines (NGN) so the caller can fold it into
 * the booking's total_amount.
 */
class AttachPackagesToNewBooking
{
    /**
     * @param  list<array{package_id: int, quantity: int}>  $selections
     */
    public function handle(Booking $booking, array $selections): int
    {
        $cleaned = $this->normalise($selections);
        if ($cleaned === []) {
            return 0;
        }

        $catalog = $this->loadCatalog($booking->tenant_id, array_keys($cleaned));
        $paidTotal = 0;

        foreach ($cleaned as $packageId => $quantity) {
            $package = $catalog->get($packageId);
            if ($package === null) {
                throw new BookingValidationException('One of the selected add-ons is no longer available.');
            }
            if (! $package->available_during_booking) {
                throw new BookingValidationException(
                    sprintf('"%s" cannot be added at booking time.', $package->name),
                );
            }
            if ($quantity > $package->max_per_booking) {
                throw new BookingValidationException(
                    sprintf('"%s" allows at most %d per booking.', $package->name, $package->max_per_booking),
                );
            }

            $unitPrice = $package->effectivePrice();
            $lineTotal = $unitPrice * $quantity;
            $paidTotal += $lineTotal;

            BookingPackage::query()->create([
                'tenant_id'                 => $booking->tenant_id,
                'booking_id'                => $booking->id,
                'package_id'                => $package->id,
                'name_snapshot'             => $package->name,
                'unit_price_snapshot'       => $unitPrice,
                'is_complimentary_snapshot' => $package->is_complimentary,
                'quantity'                  => $quantity,
                'line_total'                => $lineTotal,
                'added_at'                  => 'during_booking',
            ]);
        }

        return $paidTotal;
    }

    /**
     * Drops zero-quantity entries and collapses duplicates by summing
     * quantities. Keeps the array keyed by package_id so the loader
     * can do a single `whereIn`.
     *
     * @param  list<array{package_id: int, quantity: int}>  $selections
     * @return array<int, int>
     */
    private function normalise(array $selections): array
    {
        $out = [];
        foreach ($selections as $row) {
            $id = (int) ($row['package_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $out[$id] = ($out[$id] ?? 0) + $qty;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Package>
     */
    private function loadCatalog(int $tenantId, array $ids): Collection
    {
        return Package::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
