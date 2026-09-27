<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use App\Multitenancy\TenantScope;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Central physical store per property. Receives all supplier
 * deliveries; issues stock to outlets on daily requests.
 *
 * Store-side SKUs live on the same `products` table as outlet items,
 * distinguished by `products.store_id` being set (and `outlet_id`
 * being null).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string $name
 * @property string $slug
 * @property string|null $notes
 * @property bool $active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 * @property-read Collection<int, Product> $products
 */
class Store extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var list<string> */
    protected $guarded = ['id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('store');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Store $store): void {
            if ((string) $store->slug === '') {
                $store->slug = static::generateUniqueSlug((int) $store->tenant_id, (string) $store->name);
            }
        });
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    private static function generateUniqueSlug(int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $candidate = $base;
        $i = 2;
        while (
            static::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
