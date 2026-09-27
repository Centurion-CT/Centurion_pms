<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mints + verifies one-shot guest-portal access tokens (PRD §6.4.4 / §13).
 *
 * 30-day TTL. Stored hashed; plaintext appears once (in the email URL the
 * guest clicked). Verification is constant-time via hash_equals so a
 * timing oracle can't enumerate live tokens.
 *
 * Builds the full portal URL using the tenant's primary host so the link
 * survives a switch from `slug.keverna.com` to a custom domain.
 */
class GuestPortalTokenService
{
    public const TOKEN_TTL_DAYS = 30;

    private const RAW_TOKEN_BYTES = 48;

    /**
     * Issue a new token and return BOTH the persisted row AND the plaintext
     * value (caller must use plaintext for the URL — it can't be retrieved
     * later from the hashed column).
     *
     * @return array{token: BookingAccessToken, plaintext: string, url: string}
     */
    public function issue(Booking $booking): array
    {
        $plaintext = Str::random(self::RAW_TOKEN_BYTES);

        $token = BookingAccessToken::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => Carbon::now()->addDays(self::TOKEN_TTL_DAYS),
        ]);

        return [
            'token' => $token,
            'plaintext' => $plaintext,
            'url' => $this->urlFor($booking, $plaintext),
        ];
    }

    /**
     * Resolve a (booking_ref, plaintext) pair to its Booking or null.
     * Stamps last_used_at on the token row when it matches.
     */
    public function resolveBooking(string $bookingRef, string $plaintext): ?Booking
    {
        if ($plaintext === '' || $bookingRef === '') {
            return null;
        }

        $booking = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_ref', $bookingRef)
            ->first();

        if ($booking === null) {
            return null;
        }

        // Pull the current tenant's tokens for this booking. The tenant is
        // bound by the route middleware before we get here, so the scoped
        // query also prevents cross-tenant tokens being honoured.
        $token = BookingAccessToken::query()
            ->where('booking_id', $booking->id)
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        if ($token === null) {
            return null;
        }

        $candidate = hash('sha256', $plaintext);
        if (! hash_equals($token->token_hash, $candidate)) {
            return null;
        }

        // Hygiene marker only — not a single-use lock. The action layer is
        // responsible for idempotency on Cancel + change-request submissions.
        $token->forceFill(['last_used_at' => Carbon::now()])->save();

        return $booking;
    }

    public function urlFor(Booking $booking, string $plaintext): string
    {
        $host = $booking->tenant->primaryHost();

        return 'https://'.$host.'/guest/booking/'.$booking->booking_ref.'?token='.$plaintext;
    }
}
