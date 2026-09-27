<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Enums\InvoiceLineType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\BookingPackage;

/**
 * Synthesises the initial line-item set for a new invoice drawn from
 * one Booking. Staff can then add/remove/edit lines in the form before
 * calling CreateInvoice — this only prefills what's most likely useful.
 *
 * What gets included:
 *   - One Night line covering the whole stay (quantity = booking.nights,
 *     unit_price = room_total / nights). Always shown, even for fully-
 *     paid bookings, because per the ask the invoice is meant to
 *     "capture all the nights" as an itemised record of the stay.
 *   - One Package line per BookingPackage — priced from the snapshot,
 *     skipped when the package was flagged complimentary.
 *   - One Charge line per unsettled BookingCharge (settled charges
 *     have already been paid — no need to re-bill).
 *
 * Returns pure arrays so the Livewire form can hold them in a public
 * array property without model hydration overhead.
 *
 * @return list<array{
 *   line_type: value-of<InvoiceLineType>,
 *   source_type: ?class-string,
 *   source_id: ?int,
 *   description: string,
 *   quantity: int,
 *   unit_price: int,
 *   line_total: int,
 * }>
 */
class SuggestInvoiceLines
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Booking $booking): array
    {
        $lines = [];

        // Room / accommodation line. Compute unit_price as the average
        // nightly rate rather than pulling from a Room daily-rate query
        // — RoomPrice can vary per date and we want the total to match
        // exactly what the guest was charged at booking time, not a
        // recomputed figure that could disagree by rounding.
        $nights = max(1, (int) $booking->nights);
        $roomTotal = (int) $booking->room_total;
        $unitPrice = intdiv($roomTotal, $nights);
        $roomLineTotal = $roomTotal;  // preserve the exact stored total

        $lines[] = [
            'line_type'   => InvoiceLineType::Night->value,
            'source_type' => Booking::class,
            'source_id'   => $booking->id,
            'description' => "Room accommodation ({$booking->room?->name}) · {$booking->checkin_date?->toDateString()} → {$booking->checkout_date?->toDateString()}",
            'quantity'    => $nights,
            'unit_price'  => $unitPrice,
            'line_total'  => $roomLineTotal,
        ];

        foreach ($booking->selectedPackages as $package) {
            /** @var BookingPackage $package */
            if ($package->is_complimentary_snapshot) {
                continue;
            }

            $lines[] = [
                'line_type'   => InvoiceLineType::Package->value,
                'source_type' => BookingPackage::class,
                'source_id'   => $package->id,
                'description' => $package->name_snapshot,
                'quantity'    => (int) $package->quantity,
                'unit_price'  => (int) $package->unit_price_snapshot,
                'line_total'  => (int) $package->line_total,
            ];
        }

        foreach ($booking->charges()->unsettled()->get() as $charge) {
            /** @var BookingCharge $charge */
            $lines[] = [
                'line_type'   => InvoiceLineType::Charge->value,
                'source_type' => BookingCharge::class,
                'source_id'   => $charge->id,
                'description' => $charge->description,
                'quantity'    => 1,
                'unit_price'  => (int) $charge->amount,
                'line_total'  => (int) $charge->amount,
            ];
        }

        // Corporate discount — surfaces the saving as a negative line so
        // the invoice mirrors what the guest / corp actually owes. Only
        // when the booking carries a stamped discount amount (set by
        // CorporateBookingService::attachToBooking at create time).
        $corpDiscount = (int) ($booking->corporate_discount_naira ?? 0);
        if ($corpDiscount > 0) {
            $corpLabel = $booking->corporate?->name
                ? 'Corporate rate — '.$booking->corporate->name
                : 'Corporate rate';
            $lines[] = [
                'line_type'   => InvoiceLineType::Custom->value,
                'source_type' => null,
                'source_id'   => null,
                'description' => $corpLabel,
                'quantity'    => 1,
                'unit_price'  => -$corpDiscount,
                'line_total'  => -$corpDiscount,
            ];
        }

        return $lines;
    }
}
