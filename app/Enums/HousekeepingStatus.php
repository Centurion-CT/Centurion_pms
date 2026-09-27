<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Housekeeping state of a physical room. Independent from
 * {@see RoomStatus} (Active/Inactive — the visibility flag) — a room
 * can be Active AND Dirty (just checked out, needs cleaning) or
 * Active AND OutOfOrder (broken AC, plumbing issue) without the
 * operator having to flip the visibility.
 *
 * State machine:
 *   Clean      → Dirty       (MarkCheckedOut auto-flip)
 *   Dirty      → Clean       (housekeeper marks cleaned)
 *   Clean      → Inspected   (supervisor confirms ready for next guest)
 *   *          → OutOfOrder  (manual; blocks check-in)
 *   OutOfOrder → Clean       (manual; back in service)
 *
 * Check-in flow refuses if the room is `Dirty` or `OutOfOrder`. Clean
 * + Inspected are both check-in-ready.
 */
enum HousekeepingStatus: string
{
    case Clean       = 'clean';
    case Dirty       = 'dirty';
    case Inspected   = 'inspected';
    case OutOfOrder  = 'out_of_order';

    public function label(): string
    {
        return match ($this) {
            self::Clean      => 'Clean',
            self::Dirty      => 'Dirty',
            self::Inspected  => 'Inspected',
            self::OutOfOrder => 'Out of order',
        };
    }

    /** True if a guest can check into a room in this state. */
    public function isCheckinReady(): bool
    {
        return match ($this) {
            self::Clean, self::Inspected => true,
            self::Dirty, self::OutOfOrder => false,
        };
    }

    public function tagClass(): string
    {
        return match ($this) {
            self::Clean      => 'lp-tag--confirmed',
            self::Inspected  => 'lp-tag--checked-in',
            self::Dirty      => 'lp-tag--on-hold',
            self::OutOfOrder => 'lp-tag--cancelled',
        };
    }
}
