<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the booking originated (PRD §6.2.2 / §9).
 *
 * Most Nigerian apartments juggle several OTA accounts — Booking.com,
 * Airbnb, Hotels.ng — alongside walk-ins, WhatsApp DMs and phone calls.
 * Tagging each booking with its channel is what lets the operator answer
 * "which platform brought this stay in?" on reports and payments.
 *
 * `Online` = the tenant's own public booking page (guest self-service).
 * `Manual` = pre-channel-tagging bookings written by the frontdesk before
 * this feature shipped. Kept as a legacy value so old rows still cast
 * cleanly; hidden from the picker so operators pick a real channel now.
 */
enum BookingSource: string
{
    case Online = 'online';
    case Manual = 'manual';
    case Direct = 'direct';
    case WalkIn = 'walk_in';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case BookingCom = 'booking_com';
    case Airbnb = 'airbnb';
    case HotelsNg = 'hotels_ng';
    case JumiaTravel = 'jumia_travel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online booking page',
            self::Manual => 'Manual entry',
            self::Direct => 'Direct enquiry',
            self::WalkIn => 'Walk-in',
            self::Phone => 'Phone booking',
            self::WhatsApp => 'WhatsApp',
            self::BookingCom => 'Booking.com',
            self::Airbnb => 'Airbnb',
            self::HotelsNg => 'Hotels.ng',
            self::JumiaTravel => 'Jumia Travel',
            self::Other => 'Other',
        };
    }

    /**
     * Whether this channel actually settles through Paystack (guest-
     * facing engine) — the only case where the Paystack processing
     * fee + Keverna platform take apply. Walk-ins, WhatsApp, OTAs
     * etc. are settled outside Keverna (cash / OTA rail), so tacking
     * those fees onto the guest total would over-bill the operator.
     */
    public function chargesPlatformFees(): bool
    {
        return match ($this) {
            self::Online => true,
            default      => false,
        };
    }

    /**
     * Cases shown in the frontdesk source picker (calendar sheet + manual
     * form). Excludes `Online` (system-set by the guest-facing engine) and
     * `Manual` (legacy — surface real channels going forward).
     *
     * @return list<self>
     */
    public static function pickerCases(): array
    {
        return [
            self::WalkIn,
            self::Phone,
            self::WhatsApp,
            self::Direct,
            self::BookingCom,
            self::Airbnb,
            self::HotelsNg,
            self::JumiaTravel,
            self::Other,
        ];
    }
}
