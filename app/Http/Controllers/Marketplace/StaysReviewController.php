<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Post-stay review submission for Keverna Stays marketplace guests.
 *
 * The URL is signed by MarkCheckedOut via URL::temporarySignedRoute
 * with a 90-day TTL, so the `signed` middleware on the route already
 * gates access + expiry. This controller only has to:
 *   - resolve the booking cross-tenant by booking_ref
 *   - short-circuit to a "you've already reviewed" screen when a
 *     Review row exists for this booking (DB-enforced unique)
 *   - render the form (GET) / persist the Review + redirect to a
 *     thank-you view (POST), running the persist inside the
 *     property's tenant context so BelongsToTenant + audit trails
 *     see the right scope
 */
final class StaysReviewController
{
    public function show(Request $request, string $bookingRef): View
    {
        $booking = $this->resolveBooking($bookingRef);

        $existing = $this->existingReview($booking);
        if ($existing !== null) {
            return view('marketplace.stays.review-already-submitted', [
                'booking'  => $booking,
                'review'   => $existing,
                'property' => $booking->room?->property,
            ]);
        }

        return view('marketplace.stays.review-form', [
            'booking'  => $booking,
            'property' => $booking->room?->property,
        ]);
    }

    public function store(Request $request, string $bookingRef): RedirectResponse|View
    {
        $booking = $this->resolveBooking($bookingRef);

        // Double-submit safety - unique(booking_id) would catch it
        // at the DB layer too but returning a friendly screen beats
        // a 500.
        if ($this->existingReview($booking) !== null) {
            return view('marketplace.stays.review-already-submitted', [
                'booking'  => $booking,
                'review'   => $this->existingReview($booking),
                'property' => $booking->room?->property,
            ]);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body'   => ['required', 'string', 'min:6', 'max:2000'],
        ]);

        // Persist under the property's tenant scope so BelongsToTenant
        // fills tenant_id correctly + downstream (dashboard reviews
        // widget, moderation UI) sees the row.
        $previous = Tenant::current();
        $booking->tenant->makeCurrent();

        try {
            Review::query()->create([
                'tenant_id'    => $booking->tenant_id,
                'property_id'  => $booking->room?->property_id,
                'room_id'      => $booking->room_id,
                'booking_id'   => $booking->id,
                'guest_name'   => $booking->guest_name ?: 'Anonymous',
                'body'         => trim((string) $data['body']),
                'rating'       => (int) $data['rating'],
                // Verified stay + trusted-source auto-publish. Operator
                // can still hide via the existing Reviews dashboard.
                'is_displayed' => true,
                'order_column' => 0,
            ]);
        } finally {
            Tenant::forgetCurrent();
            $previous?->makeCurrent();
        }

        return redirect()->route('marketplace.stays.review.thanks', [
            'booking_ref' => $booking->booking_ref,
        ]);
    }

    public function thanks(string $bookingRef): View
    {
        $booking = $this->resolveBooking($bookingRef, requireCheckedOut: false);

        return view('marketplace.stays.review-thanks', [
            'booking'  => $booking,
            'property' => $booking->room?->property,
        ]);
    }

    private function resolveBooking(string $bookingRef, bool $requireCheckedOut = true): Booking
    {
        // room + room.property both carry BelongsToTenant, so the
        // eager-load in central context has to bypass the scope or
        // they come back null and downstream code (property_id on
        // the Review row) trips a NOT NULL constraint.
        $booking = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_ref', $bookingRef)
            ->where('tenant_id', Tenant::current()?->getKey()) // this business only (docs/MULTITENANCY.md)
            ->with([
                'tenant',
                'room' => fn ($q) => $q->withoutGlobalScope(TenantScope::class),
                'room.property' => fn ($q) => $q->withoutGlobalScope(TenantScope::class),
            ])
            ->first();
        if ($booking === null) {
            throw new NotFoundHttpException();
        }

        // Only checked-out marketplace bookings are eligible to
        // review. The signed URL is issued at checkout so this is
        // usually redundant, but a manual-testing / dev-tools tweak
        // shouldn't be able to leave a review on a live stay.
        if ($requireCheckedOut && $booking->booking_status !== BookingStatus::CheckedOut) {
            throw new NotFoundHttpException();
        }

        return $booking;
    }

    private function existingReview(Booking $booking): ?Review
    {
        return Review::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $booking->id)
            ->first();
    }
}
