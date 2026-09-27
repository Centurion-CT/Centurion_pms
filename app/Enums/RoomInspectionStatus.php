<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one room inspection. Progresses linearly:
 *
 *   Pending → CleaningDone → PorterVerified → Passed
 *
 * `Failed` is the terminal state when any signer rejects — the room
 * can't be checked into until a new inspection is opened.
 */
enum RoomInspectionStatus: string
{
    case Pending = 'pending';                 // opened, awaiting housekeeper
    case CleaningDone = 'cleaning_done';      // housekeeper submitted, awaiting porter
    case PorterVerified = 'porter_verified';  // porter cleared, awaiting supervisor
    case Passed = 'passed';                   // supervisor signed — check-in unlocked
    case Failed = 'failed';                   // rejected somewhere in the chain

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting housekeeper',
            self::CleaningDone => 'Awaiting porter',
            self::PorterVerified => 'Awaiting supervisor',
            self::Passed => 'Ready for guest',
            self::Failed => 'Rejected',
        };
    }

    /**
     * Who the room is currently waiting on — mirrors the display
     * label in short-form for check-in error messages.
     */
    public function waitingOn(): string
    {
        return match ($this) {
            self::Pending => 'housekeeper',
            self::CleaningDone => 'porter',
            self::PorterVerified => 'supervisor',
            self::Passed => 'no one — room is cleared',
            self::Failed => 'a fresh inspection',
        };
    }

    /**
     * Passed inspections + inspections that never had one (legacy /
     * empty room) unlock check-in. Anything else in the chain blocks.
     */
    public function unlocksCheckin(): bool
    {
        return $this === self::Passed;
    }
}
