<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categories of extra charges that can land on a booking after it's
 * created. Extension nights are special — they're added by the
 * ExtendBooking action and tie back to the new dates — but on the
 * money side they're just another line on the ledger.
 */
enum BookingChargeType: string
{
    case Extension      = 'extension';        // additional nights tacked onto the stay
    case Overstay       = 'overstay';         // checkout was overdue — auto-added by MarkCheckedOut
    case LateCheckout   = 'late_checkout';    // same-day checkout past property's check_out_to — auto-added by MarkCheckedOut
    case RoomService    = 'room_service';
    case FoodBeverage   = 'food_beverage';
    case Laundry        = 'laundry';
    case Transport      = 'transport';
    case Damage         = 'damage';
    case DepositForfeit = 'deposit_forfeit';  // recorded when a no-show keeps the deposit
    case Package        = 'package';          // paid add-on package added via guest portal
    case Other          = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Extension      => 'Extension',
            self::Overstay       => 'Overstay',
            self::LateCheckout   => 'Late check-out',
            self::RoomService    => 'Room service',
            self::FoodBeverage   => 'Food & beverage',
            self::Laundry        => 'Laundry',
            self::Transport      => 'Transport',
            self::Damage         => 'Damage',
            self::DepositForfeit => 'Deposit forfeit',
            self::Package        => 'Package',
            self::Other          => 'Other',
        };
    }

    /**
     * Categories an operator can pick manually from the "Add charge"
     * UI. Extension, Overstay, DepositForfeit, and Package are
     * system-only — they're produced by ExtendBooking, MarkCheckedOut,
     * MarkNoShow, and AddPackageToBooking respectively.
     *
     * @return array<int, self>
     */
    public static function manualOptions(): array
    {
        return [
            self::RoomService,
            self::FoodBeverage,
            self::Laundry,
            self::Transport,
            self::Damage,
            self::Other,
        ];
    }
}
