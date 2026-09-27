<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Random\Randomizer;

/**
 * Generates short, human-friendly booking references like `KV-A4F9R2C7` that
 * the guest types on the phone + reads in email confirmations (PRD §6.4.2).
 *
 * Uses an alphabet stripped of look-alikes (0/O/1/I/L) so support agents
 * don't transcribe wrongly. Falls back to a regenerate-on-collision loop
 * across all tenants (lifts TenantScope so we never reuse a code globally).
 */
class BookingReferenceGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0, O, 1, I, L

    private const LENGTH = 8;

    private const PREFIX = 'KV-';

    public function __construct(private readonly Randomizer $randomizer = new Randomizer) {}

    public function generate(): string
    {
        do {
            $code = self::PREFIX.$this->randomCode();
        } while ($this->collides($code));

        return $code;
    }

    private function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= $alphabet[$this->randomizer->getInt(0, $max)];
        }

        return $out;
    }

    private function collides(string $code): bool
    {
        return Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_ref', $code)
            ->exists();
    }
}
