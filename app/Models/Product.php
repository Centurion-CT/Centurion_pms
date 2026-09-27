<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A Product belongs to EITHER an outlet (regular POS item) OR the
 * property's central store (store-side SKU that will be issued to
 * outlets on request). Exactly one of `outlet_id` / `store_id` is
 * populated — enforced at the app layer by the create paths.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $outlet_id
 * @property int|null $store_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property int $cost
 * @property bool $track_stock
 * @property int $quantity_on_hand
 * @property int $low_stock_threshold
 * @property int $usage_per_clean
 * @property bool $is_rotational
 * @property int $qty_in_use
 * @property int $qty_at_laundry
 * @property bool $active
 * @property-read Outlet|null $outlet
 * @property-read Store|null $store
 */
class Product extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Log every field-level change on a product to Spatie's activity_log
     * so the Inventory > Change history surface can show who edited a
     * price, cost, or metadata field. `quantity_on_hand` is intentionally
     * omitted — stock movement history lives in the dedicated
     * stock_movements ledger.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'barcode', 'price', 'cost',
                'active', 'track_stock', 'low_stock_threshold',
                'usage_per_clean', 'is_rotational'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('product');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'int',
            'cost' => 'int',
            'track_stock' => 'bool',
            'quantity_on_hand' => 'int',
            'low_stock_threshold' => 'int',
            'usage_per_clean' => 'int',
            'is_rotational' => 'bool',
            'qty_in_use' => 'int',
            'qty_at_laundry' => 'int',
            'active' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * BOM rows describing what this product is made of. Non-empty →
     * composite (cocktail, combo meal). Component decrements on sale
     * happen against these instead of on the composite itself.
     *
     * @return HasMany<RecipeItem, $this>
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'product_id');
    }

    /**
     * True when this product is composed of others — i.e. a cocktail
     * assembled from base ingredients rather than a discrete unit sold
     * as-is.
     */
    public function isComposite(): bool
    {
        return $this->recipeItems()->exists();
    }

    public function isLowStock(): bool
    {
        return $this->track_stock
            && $this->low_stock_threshold > 0
            && $this->quantity_on_hand <= $this->low_stock_threshold;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
