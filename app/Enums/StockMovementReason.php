<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every mutation of `products.quantity_on_hand` OR
 * `store_items.quantity_on_hand` writes a stock_movements row tagged
 * with one of these reasons. The ledger is the source of truth; the
 * materialised column on Product / StoreItem is a fast-read cache.
 *
 *  - Received      : bulk stock-in (supplier delivery — now targets
 *                    the store, not an outlet)
 *  - Sold          : sold through the POS terminal — carries a
 *                    source_type=PosSale, source_id=<sale id>
 *  - Adjusted      : manual correction (spillage, counting fix)
 *  - Wasted        : written off (expired, broken)
 *  - Transferred   : peer-to-peer between outlets (paired -N / +N rows)
 *  - Issued        : store → outlet on requisition (paired -N store /
 *                    +N outlet rows, sharing source_type=Issuance)
 *  - Returned      : outlet → store (paired -N outlet / +N store
 *                    rows, sharing source_type=IssuanceReturn)
 *  - InitialStock  : seeded when a product/store item is first created
 *                    with a starting quantity, or as a cutover marker
 *                    for the two-tier migration
 *  - Used          : housekeeping turnover consumption (amenity soaps
 *                    used on cleaned rooms). Carries a source_type of
 *                    Housekeeping and a note referencing the rooms
 *                    covered. Rotational SKUs move to qty_in_use
 *                    instead of being spent — see ApplyHousekeepingTurnover.
 *  - ReturnedFromLaundry : rotational linens coming back clean from
 *                    the laundry, restocking the outlet's clean shelf.
 *                    Delta positive; SendToLaundry is a rotation state
 *                    change only (no clean_stock ledger row).
 */
enum StockMovementReason: string
{
    case Received            = 'received';
    case Sold                = 'sold';
    case Adjusted            = 'adjusted';
    case Wasted              = 'wasted';
    case Transferred         = 'transferred';
    case Issued              = 'issued';
    case Returned            = 'returned';
    case InitialStock        = 'initial_stock';
    case Used                = 'used';
    case ReturnedFromLaundry = 'returned_from_laundry';

    public function label(): string
    {
        return match ($this) {
            self::Received            => 'Received',
            self::Sold                => 'Sold',
            self::Adjusted            => 'Adjusted',
            self::Wasted              => 'Wasted',
            self::Transferred         => 'Transferred',
            self::Issued              => 'Issued',
            self::Returned            => 'Returned',
            self::InitialStock        => 'Initial stock',
            self::Used                => 'Used',
            self::ReturnedFromLaundry => 'Returned from laundry',
        };
    }
}
