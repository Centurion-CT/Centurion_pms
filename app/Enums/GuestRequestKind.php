<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categories of request a guest raises from the in-room QR portal.
 * Drives the operator dashboard's grouping / colour / SLA cues.
 */
enum GuestRequestKind: string
{
    case Complaint = 'complaint';
    case Taxi = 'taxi';
    case Transfer = 'transfer';
    case Order = 'order';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Complaint => 'Complaint',
            self::Taxi => 'Taxi request',
            self::Transfer => 'Room transfer',
            self::Order => 'Room service order',
            self::Custom => 'Custom request',
        };
    }

    /**
     * Urgency signal for the operator queue — complaint + transfer
     * jump ahead of routine taxi / order requests.
     */
    public function isUrgent(): bool
    {
        return $this === self::Complaint || $this === self::Transfer;
    }
}
