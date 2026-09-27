<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PosSaleStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single POS transaction. `reference` is human-readable (KV-BAR-abcd)
 * so operators can call it out during disputes without exposing raw ids.
 *
 * A sale is either walk-in (booking_id null) or charge-to-room
 * (booking_id set — a BookingCharge is created downstream so the guest
 * folio picks it up).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $outlet_id
 * @property int $operator_id
 * @property string $reference
 * @property string|null $client_key
 * @property PosSaleStatus $status
 * @property int|null $booking_id
 * @property int|null $credit_line_id
 * @property int|null $customer_id
 * @property string|null $customer_phone_snapshot
 * @property string|null $customer_name_snapshot
 * @property int $subtotal
 * @property int $tax_amount
 * @property int $total
 * @property bool $is_complimentary
 * @property int|null $complimentary_by_operator_id
 * @property string|null $complimentary_reason
 * @property string|null $notes
 * @property Carbon|null $sold_at
 * @property Carbon|null $voided_at
 * @property string|null $voided_reason
 * @property-read Outlet $outlet
 * @property-read Operator $operator
 * @property-read Booking|null $booking
 * @property-read Customer|null $customer
 * @property-read Operator|null $complimentaryBy
 * @property-read Collection<int, PosSaleLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
class PosSale extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PosSaleStatus::class,
            'subtotal' => 'int',
            'tax_amount' => 'int',
            'total' => 'int',
            'is_complimentary' => 'boolean',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function complimentaryBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'complimentary_by_operator_id');
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<CreditLine, $this>
     */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(CreditLine::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<PosSaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PosSaleReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PosSaleReturn::class);
    }

    public function isChargeToRoom(): bool
    {
        return $this->booking_id !== null;
    }

    /**
     * @param  Builder<PosSale>  $query
     * @return Builder<PosSale>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', PosSaleStatus::Completed);
    }
}
