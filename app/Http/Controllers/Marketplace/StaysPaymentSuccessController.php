<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Post-payment thank-you screen. Reached only via the Paystack
 * callback redirect, which validates the payment first. Renders
 * booking summary + notes on what happens next (email confirmation,
 * property will be in touch, etc.).
 */
final class StaysPaymentSuccessController
{
    public function __invoke(string $bookingRef): View
    {
        $booking = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_ref', $bookingRef)
            ->with(['tenant', 'room.property'])
            ->first();
        if ($booking === null) {
            throw new NotFoundHttpException();
        }

        return view('marketplace.stays.pay-success', [
            'booking' => $booking,
        ]);
    }
}
