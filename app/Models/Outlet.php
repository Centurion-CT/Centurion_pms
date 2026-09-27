<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutletType;
use App\Multitenancy\BelongsToTenant;
use App\Multitenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string $name
 * @property string $slug
 * @property OutletType $type
 * @property bool $active
 * @property string $tax_rate
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Property $property
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, Operator> $operators
 */
class Outlet extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Log outlet field-level changes to Spatie's activity_log for the
     * Inventory > Change history page. `tax_rate` is the money-relevant
     * field; name / active are logged so a rename or archive shows up
     * on the timeline too.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'active', 'tax_rate'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('outlet');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OutletType::class,
            'active' => 'bool',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Outlet $outlet): void {
            if ((string) $outlet->slug === '') {
                $outlet->slug = static::generateUniqueSlug((int) $outlet->tenant_id, (string) $outlet->name);
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
     * @return HasMany<PosSale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    /**
     * Operators explicitly assigned to run this outlet's POS terminal.
     * Managers bypass this pivot — they see every outlet regardless.
     *
     * @return BelongsToMany<Operator, $this>
     */
    public function operators(): BelongsToMany
    {
        return $this->belongsToMany(Operator::class, 'outlet_operators')
            ->withTimestamps();
    }

    /**
     * @param  Builder<Outlet>  $query
     * @return Builder<Outlet>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Generate a slug that's unique within the tenant. Handles
     * collisions by suffixing "-2", "-3", etc.
     */
    private static function generateUniqueSlug(int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'outlet';
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
