<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\RoomCategory;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\BookingReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Category-level reservation - the guest is held against a room
 * category (Deluxe, Studio, ...) without pinning a specific room
 * until check-in. Front desk picks a real room via
 * AssignRoomToBooking before the guest can be checked in.
 *
 * Kept as a dedicated action rather than folded into CreateBooking
 * because the pricing surface, discount + package plumbing, and
 * corporate wallet integration on CreateBooking all assume a
 * concrete Room. Category-level reservations only exist to hold
 * inventory + capture guest details for the assignment step;
 * everything else that touches money runs through the normal
 * CreateBooking action once the room is picked.
 */
class CreateCategoryReservation
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingReferenceGenerator $refs,
    ) {}

    /**
     * @throws BookingValidationException     guest-facing input problem
     * @throws RangeNotAvailableException     concurrent booking took the last slot
     */
    public function handle(
        RoomCategory $category,
        Carbon $checkin,
        Carbon $checkout,
        string $guestName,
        string $guestEmail,
        string $guestPhone,
        int $adults,
        int $children,
        ?string $specialRequests = null,
        BookingSource $source = BookingSource::Online,
        ?string $sourceNote = null,
        // Marketplace-side callers thread the incoming HTTP request so
        // per-night pricing runs through RateResolver::resolveStay()
        // instead of the flat category.base_rate default. Front-desk
        // callers pass null and get the flat rate.
        ?\Illuminate\Http\Request $marketplaceRequest = null,
    ): Booking {
        if ($checkout->lessThanOrEqualTo($checkin)) {
            throw new BookingValidationException('Check-out must be after check-in.');
        }
        if ($adults < 1) {
            throw new BookingValidationException('At least one adult is required.');
        }
        if ($category->maxOccupancy() > 0 && ($adults + $children) > $category->maxOccupancy()) {
            throw new BookingValidationException(sprintf(
                'This category sleeps up to %d guests; you entered %d.',
                $category->maxOccupancy(),
                $adults + $children,
            ));
        }

        return DB::transaction(function () use (
            $category, $checkin, $checkout,
            $guestName, $guestEmail, $guestPhone,
            $adults, $children, $specialRequests, $source, $sourceNote,
            $marketplaceRequest,
        ): Booking {
            // Row-lock the category during the count-and-reserve so a
            // concurrent category-level booking can't sneak past the
            // guard. SQLite ignores the lockForUpdate; on Postgres +
            // MySQL it's a real row lock, released on commit.
            $lockedCategory = RoomCategory::query()->lockForUpdate()
                ->findOrFail($category->id);

            $free = $this->availability->categoryFreeUnits($lockedCategory, $checkin, $checkout);
            if ($free < 1) {
                throw new RangeNotAvailableException(
                    'This category is fully booked for those dates.'
                );
            }

            $nights = (int) $checkin->diffInDays($checkout, absolute: true);
            // Marketplace path: resolve per-night rates through the
            // rate rules so surge / promo windows are honoured on
            // the category-hold flow just like the room-mode flow.
            // Front-desk path (null request): flat base_rate as before.
            $roomTotal = 0;
            if ($marketplaceRequest !== null) {
                $stay = app(\App\Services\Marketplace\RateResolver::class)
                    ->resolveStay($lockedCategory, $marketplaceRequest, $checkin, $checkout);
                $roomTotal = (int) ($stay['total'] ?: 0);
            }
            if ($roomTotal <= 0) {
                $roomTotal = $nights * (int) $lockedCategory->base_rate;
            }

            $booking = Booking::query()->create([
                'tenant_id'         => $lockedCategory->tenant_id,
                'room_id'           => null,                       // <- unassigned
                'room_category_id'  => $lockedCategory->id,
                'booking_ref'       => $this->refs->generate(),
                'guest_name'        => $guestName,
                'guest_email'       => $guestEmail,
                'guest_phone'       => $guestPhone,
                'adults'            => $adults,
                'children'          => $children,
                'special_requests'  => $specialRequests,
                'checkin_date'      => $checkin->toDateString(),
                'checkout_date'     => $checkout->toDateString(),
                'nights'            => $nights,
                'room_total'        => $roomTotal,
                'total_amount'      => $roomTotal,   // fees skipped - collected at check-in
                'paystack_fee'      => 0,
                'keverna_fee'       => 0,
                'deposit_amount'    => 0,
                'balance_due'       => 0,
                'booking_status'    => BookingStatus::Pending,
                'payment_status'    => PaymentStatus::Pending,
                'source'            => $source,
                'source_note'       => $sourceNote,
            ]);

            $this->availability->reserveCategoryRangeForBooking(
                $lockedCategory, $checkin, $checkout, $booking->id,
            );

            return $booking->refresh();
        });
    }
}
