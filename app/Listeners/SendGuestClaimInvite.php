<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Mail\Identity\GuestClaimInviteMail;
use App\Models\GuestClaimToken;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;
use App\Services\Identity\GuestClaimTokenSigner;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the Phase 2 magic-link claim invite after a booking confirms
 * (docs/unified-login.md §3, Phase 2 "Backfill / Guests, lazy").
 *
 * Runs alongside SendBookingConfirmationMail — they listen on the same
 * event but do different things. Isolated listener so the claim flow
 * can be toggled or rate-limited without touching the confirmation-
 * mail path.
 *
 * Dedupe rules — skip send if ANY of these is true:
 *  - Booking has no guest email (nothing to invite).
 *  - An identity_account already exists for this email AND has any
 *    identity_guest_link — the guest is already claimed.
 *  - Any guest_claim_token was issued for this email in the last
 *    30 days (consumed or not). Avoids spamming a guest who has
 *    been invited but hasn't clicked yet, without waiting forever
 *    if they never do.
 */
class SendGuestClaimInvite
{
    private const DEDUPE_WINDOW_DAYS = 30;

    public function __construct(private readonly GuestClaimTokenSigner $signer) {}

    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;
        $email = trim((string) $booking->guest_email);
        if ($email === '') {
            return;
        }

        $normalised = IdentityAccount::normaliseEmail($email);

        if ($this->guestAlreadyClaimed($normalised)) {
            return;
        }

        if ($this->recentlyInvited($normalised)) {
            return;
        }

        $token = $this->signer->issue($normalised, bookingId: (int) $booking->id);

        GuestClaimToken::create([
            'nonce' => $token['nonce'],
            'email' => $normalised,
            'origin_tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'issued_at' => now(),
            'expires_at' => $token['expires_at'],
        ]);

        Mail::to($email)->queue(new GuestClaimInviteMail(
            claimUrl: $token['url'],
            propertyName: (string) ($booking->tenant->name ?? 'Keverna'),
        ));
    }

    private function guestAlreadyClaimed(string $normalisedEmail): bool
    {
        $identity = IdentityAccount::query()->where('email', $normalisedEmail)->first();
        if ($identity === null) {
            return false;
        }

        return IdentityGuestLink::query()
            ->where('identity_account_id', $identity->id)
            ->exists();
    }

    private function recentlyInvited(string $normalisedEmail): bool
    {
        return GuestClaimToken::query()
            ->where('email', $normalisedEmail)
            ->where('issued_at', '>=', now()->subDays(self::DEDUPE_WINDOW_DAYS))
            ->exists();
    }
}
