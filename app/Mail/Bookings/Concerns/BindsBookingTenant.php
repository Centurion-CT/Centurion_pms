<?php

declare(strict_types=1);

namespace App\Mail\Bookings\Concerns;

use App\Models\Booking;
use App\Models\Tenant;
use App\Support\MailBranding;
use Illuminate\Mail\Mailables\Headers;

/**
 * Booking mailables run on a queue worker, where `Tenant::current()` is
 * never automatically bound. {@see MailBranding} relies on
 * that binding to render the right header — so we bind it ourselves
 * before the view is rendered and clear it after.
 *
 * Using `headers()` is a tidy hook: it runs once per send (before
 * envelope + content) and we can piggyback to set the context. The
 * actual returned Headers object is empty — the side effect is the point.
 */
trait BindsBookingTenant
{
    public function headers(): Headers
    {
        $booking = $this->bookingForBranding();
        if ($booking !== null) {
            // Use the row's persisted tenant_id rather than the relation so we
            // skip an extra query if the relation isn't preloaded.
            $tenant = Tenant::query()->withoutGlobalScopes()->find($booking->tenant_id);
            $tenant?->makeCurrent();
        }

        return new Headers;
    }

    private function bookingForBranding(): ?Booking
    {
        /** @phpstan-ignore-next-line property.notFound */
        return property_exists($this, 'booking') ? $this->booking : null;
    }
}
