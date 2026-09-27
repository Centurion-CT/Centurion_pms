<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $supplier_id
 * @property int $outlet_id
 * @property int|null $operator_id
 * @property string $reference
 * @property PurchaseOrderStatus $status
 * @property Carbon|null $expected_at
 * @property Carbon|null $placed_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property int $subtotal
 * @property int $tax_amount
 * @property int $total
 * @property-read Supplier $supplier
 * @property-read Outlet $outlet
 * @property-read Operator|null $operator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PurchaseOrderLine> $lines
 */
class PurchaseOrder extends Model
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
            'status'        => PurchaseOrderStatus::class,
            'expected_at'   => 'date',
            'placed_at'     => 'datetime',
            'received_at'   => 'datetime',
            'cancelled_at'  => 'datetime',
            'subtotal'      => 'int',
            'tax_amount'    => 'int',
            'total'         => 'int',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po): void {
            if ((string) $po->reference === '') {
                $po->reference = self::mintReference((int) $po->tenant_id);
            }
        });
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * True when every ordered unit has been received.
     */
    public function isFullyReceived(): bool
    {
        return $this->lines->every(fn (PurchaseOrderLine $l) => $l->quantity_received >= $l->quantity_ordered);
    }

    /**
     * True when at least one unit has landed but not all.
     */
    public function hasPartialReceipt(): bool
    {
        $anyReceived = $this->lines->contains(fn (PurchaseOrderLine $l) => $l->quantity_received > 0);
        return $anyReceived && ! $this->isFullyReceived();
    }

    /**
     * Short human-readable reference — KV-PO-abcd. Kept unique per tenant
     * (see the migration's unique index); collision retry is defensive.
     */
    private static function mintReference(int $tenantId): string
    {
        do {
            $candidate = 'KV-PO-'.strtolower(Str::random(6));
        } while (
            self::query()
                ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('reference', $candidate)
                ->exists()
        );

        return $candidate;
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PurchaseOrderStatus::Sent->value,
            PurchaseOrderStatus::PartiallyReceived->value,
        ]);
    }
}
