<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\BookingChargeType;

/**
 * The category of a POS outlet. Drives icon + copy + which
 * BookingChargeType a "charge to room" sale becomes.
 */
enum OutletType: string
{
    case Bar          = 'bar';
    case Restaurant   = 'restaurant';
    case Kitchen      = 'kitchen';
    case Laundry      = 'laundry';
    case Spa          = 'spa';
    case GiftShop     = 'gift_shop';
    case Housekeeping = 'housekeeping';
    case Other        = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bar          => 'Bar',
            self::Restaurant   => 'Restaurant',
            self::Kitchen      => 'Kitchen',
            self::Laundry      => 'Laundry',
            self::Spa          => 'Spa',
            self::GiftShop     => 'Gift shop',
            self::Housekeeping => 'Housekeeping',
            self::Other        => 'Other',
        };
    }

    /**
     * Which BookingChargeType a "charge to room" sale from this outlet
     * generates. Front-desk staff looking at Frank Mike's folio will see
     * "Food & beverage" for a bar/kitchen sale, "Laundry" for a laundry
     * ticket, etc. Housekeeping never sells to a guest, but if a linen
     * charge is ever recorded it books under room service.
     */
    public function bookingChargeType(): BookingChargeType
    {
        return match ($this) {
            self::Bar, self::Restaurant, self::Kitchen                  => BookingChargeType::FoodBeverage,
            self::Laundry                                               => BookingChargeType::Laundry,
            self::Spa, self::GiftShop, self::Housekeeping, self::Other  => BookingChargeType::RoomService,
        };
    }

    /**
     * Whether this outlet fronts a POS terminal — customers pay, tender
     * is chosen, receipts print. Housekeeping is a supply room, not a
     * point of sale, so it must not appear in the terminal picker or
     * anywhere the sales-facing UI expects a rung-up outlet.
     */
    public function usesPos(): bool
    {
        return $this !== self::Housekeeping;
    }

    /**
     * Whether this outlet handles food + beverage — the vocabulary in
     * F&B settings differs from retail ("Item / Portion" vs
     * "Product / Unit"). Drives noun helpers below.
     */
    public function isFoodAndBeverage(): bool
    {
        return in_array($this, [self::Bar, self::Restaurant, self::Kitchen], true);
    }

    /**
     * Singular label for the "thing on sale" in this outlet's UI.
     * F&B outlets read as "Item"; retail / service outlets as
     * "Product". Used everywhere the outlet product form / list
     * would otherwise say "product".
     */
    public function productNoun(bool $plural = false): string
    {
        $noun = $this->isFoodAndBeverage() ? 'Item' : 'Product';

        return $plural ? $noun.'s' : $noun;
    }

    /**
     * Singular label for the "one countable thing sold" — Portion
     * for F&B (a plate, a glass), Unit for retail (a bottle, a bar
     * of soap).
     */
    public function quantityNoun(bool $plural = false): string
    {
        $noun = $this->isFoodAndBeverage() ? 'Portion' : 'Unit';

        return $plural ? $noun.'s' : $noun;
    }
}
