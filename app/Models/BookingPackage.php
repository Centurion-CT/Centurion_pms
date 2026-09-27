<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A package attached to a booking. Carries snapshot copies of the
 * catalog row so later edits don't rewrite history.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $package_id
 * @property string $name_snapshot
 * @property int $unit_price_snapshot
 * @property bool $is_complimentary_snapshot
 * @property int $quantity
 * @property int $line_total
 * @property string $added_at
 * @property int|null $booking_charge_id
 */
class BookingPackage extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_package';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_snapshot'       => 'int',
            'is_complimentary_snapshot' => 'bool',
            'quantity'                  => 'int',
            'line_total'                => 'int',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        // withTrashed so a soft-deleted catalog row still resolves on
        // historical bookings (the snapshot columns cover the UI but
        // the relation is occasionally handy for admin tooling).
        return $this->belongsTo(Package::class)->withTrashed();
    }

    /**
     * @return BelongsTo<BookingCharge, $this>
     */
    public function bookingCharge(): BelongsTo
    {
        return $this->belongsTo(BookingCharge::class);
    }
}
