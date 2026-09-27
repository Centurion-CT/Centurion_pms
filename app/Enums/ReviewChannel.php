<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origin of a Review row: where the guest originally posted it before
 * the operator transcribed it into Keverna. Rendered as a small badge
 * on the public listing so browsing guests can weigh a Booking.com
 * review differently to a direct one.
 *
 * `Direct` covers reviews collected via Keverna's own channels (guest
 * portal, email follow-up, WhatsApp). Everything else is a paste-from-
 * elsewhere with attribution.
 */
enum ReviewChannel: string
{
    case Direct       = 'direct';
    case Google       = 'google';
    case BookingCom   = 'booking';
    case Airbnb       = 'airbnb';
    case TripAdvisor  = 'tripadvisor';
    case Other        = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Direct      => 'Direct',
            self::Google      => 'Google',
            self::BookingCom  => 'Booking.com',
            self::Airbnb      => 'Airbnb',
            self::TripAdvisor => 'TripAdvisor',
            self::Other       => 'Other',
        };
    }

    /**
     * Tailwind-ish colour tokens (background + ink) used by the badge
     * on the public review card. Kept as inline styles rather than
     * class names so the marketing bundle doesn't have to compile any
     * new utilities.
     *
     * @return array{bg:string, ink:string}
     */
    public function badgeColors(): array
    {
        return match ($this) {
            self::Direct      => ['bg' => '#DBEAFE', 'ink' => '#1E3A8A'],
            self::Google      => ['bg' => '#FEF3C7', 'ink' => '#92400E'],
            self::BookingCom  => ['bg' => '#DBEAFE', 'ink' => '#1D4ED8'],
            self::Airbnb      => ['bg' => '#FEE2E2', 'ink' => '#B91C1C'],
            self::TripAdvisor => ['bg' => '#D1FAE5', 'ink' => '#065F46'],
            self::Other       => ['bg' => '#E5E7EB', 'ink' => '#374151'],
        };
    }
}
