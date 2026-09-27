<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a purchase order.
 *
 *   Draft             — being composed; nothing committed
 *   Sent              — operator has ordered from the supplier
 *   PartiallyReceived — some delivered, some still outstanding
 *   Received          — fully landed; stock movements written
 *   Cancelled         — supplier no-showed or operator cancelled
 */
enum PurchaseOrderStatus: string
{
    case Draft             = 'draft';
    case Sent              = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received          = 'received';
    case Cancelled         = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'Draft',
            self::Sent              => 'Sent',
            self::PartiallyReceived => 'Partially received',
            self::Received          => 'Received',
            self::Cancelled         => 'Cancelled',
        };
    }

    /**
     * Colour token for status pills; picks from the shared design palette.
     */
    public function toneColor(): string
    {
        return match ($this) {
            self::Draft             => 'var(--color-ink-mute)',
            self::Sent              => '#3E7CB1',
            self::PartiallyReceived => '#C08A2E',
            self::Received          => 'var(--color-accent-deep)',
            self::Cancelled         => '#B85C42',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived], true);
    }
}
