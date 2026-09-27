<?php

declare(strict_types=1);

namespace App\Services\GuestPortal;

use App\Models\Booking;
use App\Services\Sendpipe\SendpipeClient;
use Illuminate\Support\Facades\Log;

/**
 * Guest-side WhatsApp/SMS delivery for the in-room portal. Wraps
 * Sendpipe with two conveniences:
 *   - Silently no-ops when the Sendpipe key is unset (dev/CI), same
 *     pattern the existing WhatsAppOtpService uses. Feature can be
 *     turned on/off by environment without conditionals at call sites.
 *   - Normalises phone numbers to a leading + so E.164 downstream
 *     doesn't care what the operator captured on the booking form.
 *
 * Keeping this small + focused so a future migration off Sendpipe
 * (Twilio, Termii, etc.) touches one file, not every call site.
 */
class GuestNotifier
{
    public function __construct(private readonly SendpipeClient $sendpipe) {}

    /**
     * PIN-reset OTP - matches the same WhatsApp template the operator
     * onboarding OTP uses so we don't need Meta to approve another
     * one. Template body: "{{1}} is your Keverna verification code."
     */
    public function sendPinResetOtp(string $phone, string $code): void
    {
        if (! $this->enabled()) {
            Log::info('Guest PIN reset OTP skipped (Sendpipe key unset)', [
                'phone_masked' => $this->maskPhone($phone),
                'code' => $code, // logged only when Sendpipe is disabled - dev-only path
            ]);
            return;
        }

        $this->sendpipe->sendWhatsAppTemplate(
            to: [$this->normalisePhone($phone)],
            templateName: (string) config('services.sendpipe.whatsapp_otp_template', 'kev_otp'),
            templateLanguage: (string) config('services.sendpipe.whatsapp_otp_language', 'en'),
            templateParams: [$code],
        );
    }

    /**
     * Generic notification for a resolved guest request. Uses a
     * plain "session" WhatsApp message (24h window) rather than a
     * pre-approved template - should be a delivery only if the guest
     * has messaged the property number in the past 24h. Sendpipe
     * falls back to a template if the session is stale; we rely on
     * that behaviour rather than pre-approving a template for every
     * notification kind.
     *
     * When Sendpipe is disabled we log the message body so operators
     * running against a dev tenant can still see what would have
     * been sent.
     */
    public function sendRequestResolvedNotice(string $phone, string $message): void
    {
        if (! $this->enabled()) {
            Log::info('Guest request notice skipped (Sendpipe key unset)', [
                'phone_masked' => $this->maskPhone($phone),
                'message' => $message,
            ]);
            return;
        }

        // Sendpipe's WhatsApp session-message path - plain text body,
        // no template. If the 24h session is closed Sendpipe returns
        // an error we don't want to block the operator on; catch +
        // log so a resolve action still succeeds.
        try {
            $this->sendpipe->sendWhatsAppText(
                to: [$this->normalisePhone($phone)],
                body: $message,
            );
        } catch (\Throwable $e) {
            Log::warning('Guest request notice delivery failed (non-blocking)', [
                'phone_masked' => $this->maskPhone($phone),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Day-before checkout nudge - sent by the SendExtendPrompts cron.
     * "Hi {name}, you're checking out tomorrow. Want to stay another
     * night? Open your portal: {url}"
     *
     * Uses the session-message path (same as request-resolved notice)
     * with Sendpipe's automatic fallback to a template when the 24h
     * window is closed. Non-blocking on failure: the caller stamps
     * `extend_prompt_sent_at` regardless of downstream delivery so
     * we don't spam the guest on retry.
     */
    /**
     * Branded WhatsApp arrival card. Sent at booking confirmation so
     * the guest lands the booking in their WhatsApp thread with:
     *   - a warm "Welcome, {first name}" line + property name
     *   - check-in date + room number (if assigned)
     *   - Google Maps deep link (uses coords when the property has
     *     them, falls back to the postal address so pin-drops still
     *     work in a taxi driver's hand)
     *   - portal deep link (the same URL emailed in BookingConfirmedMail)
     *   - property landing URL as the *last* URL so WhatsApp's rich
     *     link preview pulls the property's own OG image and title
     *     (the message reads as a branded card, not a naked text)
     *
     * Non-blocking on failure; the booking still lands in the operator
     * pipeline even if Sendpipe rejects the session send.
     */
    public function sendArrivalCard(Booking $booking, string $portalUrl): void
    {
        $phone = (string) $booking->guest_phone;
        if ($phone === '') return;

        $property = $booking->room?->property ?? $booking->tenant?->property;
        $tenant   = $booking->tenant;

        $propertyName = $property?->name ?? $tenant?->name ?? 'the property';
        $firstName    = trim(explode(' ', (string) $booking->guest_name)[0] ?? '') ?: 'there';
        $checkinLine  = $booking->checkin_date?->format('l, j M') ?? 'your arrival day';
        $roomNumber   = $booking->room?->room_number;

        $address = trim(collect([
            $property?->address_line_1 ?? null,
            $property?->city ?? null,
            $property?->state ?? null,
        ])->filter()->join(', '));

        $lat = $property?->latitude;
        $lng = $property?->longitude;
        $mapsUrl = is_numeric($lat) && is_numeric($lng)
            ? sprintf('https://maps.google.com/?q=%s,%s', $lat, $lng)
            : ($address !== ''
                ? 'https://maps.google.com/?q='.rawurlencode($address)
                : null);

        // Landing URL sits last so WhatsApp's link preview scrapes IT
        // (not the maps link) for the message card thumbnail. That
        // pulls the property's own hero photograph + tagline, turning
        // the message into a rich brand moment on the guest's phone.
        $landingUrl = $tenant?->slug
            ? 'https://'.$tenant->slug.'.keverna.com/'
            : null;

        $body  = "🌿 Welcome, {$firstName}!\n";
        $body .= "Your stay at {$propertyName} is confirmed.\n\n";
        $body .= "📅 Check-in: {$checkinLine}\n";
        if ($roomNumber !== null && $roomNumber !== '') {
            $body .= "🏠 Room {$roomNumber}\n";
        }
        if ($address !== '') {
            $body .= "📍 {$address}\n";
        }
        $body .= "\n";
        if ($mapsUrl !== null) {
            $body .= "🗺️  Directions: {$mapsUrl}\n";
        }
        $body .= "🗝️  Your room portal: {$portalUrl}\n";
        if ($landingUrl !== null) {
            $body .= "\n{$landingUrl}";
        }

        if (! $this->enabled()) {
            Log::info('Arrival card skipped (Sendpipe key unset)', [
                'phone_masked' => $this->maskPhone($phone),
                'message' => $body,
            ]);
            return;
        }

        try {
            $this->sendpipe->sendWhatsAppText(
                to: [$this->normalisePhone($phone)],
                body: $body,
                idempotencyKey: 'kev-arrival-'.$booking->id,
            );
        } catch (\Throwable $e) {
            Log::warning('Arrival card delivery failed (non-blocking)', [
                'phone_masked' => $this->maskPhone($phone),
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendExtendPrompt(string $phone, string $guestName, string $propertyName, string $portalUrl): void
    {
        $body = "Hi {$guestName}, you're checking out tomorrow from {$propertyName}. "
              . "Want to stay another night? Open your room portal to extend: {$portalUrl}";

        if (! $this->enabled()) {
            Log::info('Extend prompt skipped (Sendpipe key unset)', [
                'phone_masked' => $this->maskPhone($phone),
                'message' => $body,
            ]);
            return;
        }

        try {
            $this->sendpipe->sendWhatsAppText(
                to: [$this->normalisePhone($phone)],
                body: $body,
            );
        } catch (\Throwable $e) {
            Log::warning('Extend prompt delivery failed (non-blocking)', [
                'phone_masked' => $this->maskPhone($phone),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function enabled(): bool
    {
        return (string) config('services.sendpipe.api_key') !== '';
    }

    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // Nigerian mobile: strip leading zeros and prefix +234 when
        // the number arrives as 0801… - Sendpipe wants E.164.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '234'.substr($digits, 1);
        }
        return '+'.ltrim($digits, '+');
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) return '***';
        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
