<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Physical workspace inventory units for co-working operators.
 *
 * A Workspace row represents either a *kind* of seat (e.g. "Hot Desk")
 * or a specific named asset (e.g. "Boardroom A"). The operator decides
 * what granularity makes sense — hot desks are typically capacity-N
 * shared inventory, while private offices and meeting rooms are
 * usually 1:1 named units.
 */
enum WorkspaceType: string
{
    case HotDesk        = 'hot_desk';
    case DedicatedDesk  = 'dedicated_desk';
    case PrivateOffice  = 'private_office';
    case MeetingRoom    = 'meeting_room';

    public function label(): string
    {
        return match ($this) {
            self::HotDesk       => 'Hot desk',
            self::DedicatedDesk => 'Dedicated desk',
            self::PrivateOffice => 'Private office',
            self::MeetingRoom   => 'Meeting room',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HotDesk       => 'First-come seating, shared across members.',
            self::DedicatedDesk => 'Assigned to one member full-time.',
            self::PrivateOffice => 'Lockable office for a team.',
            self::MeetingRoom   => 'Bookable by the hour for calls + meetings.',
        };
    }
}
