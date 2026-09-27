<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositType;
use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\RoomCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Template / class for a group of rooms. See the create_room_categories
 * migration's docblock for why this split exists.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string $name
 * @property string $type
 * @property string|null $description
 * @property int $capacity_adults
 * @property int $capacity_children
 * @property int $base_rate
 * @property int|null $breakfast_included_rate  // null = tier disabled
 * @property int|null $monthly_rate
 * @property int $min_nights
 * @property int $weekly_discount_percent
 * @property int $monthly_discount_percent
 * @property DepositType $deposit_type
 * @property int $deposit_value
 * @property RoomBookingType $booking_type
 * @property RoomStatus $status
 * @property string|null $rules
 */
class RoomCategory extends Model implements HasMedia
{
    use BelongsToTenant;

    /** @use HasFactory<RoomCategoryFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    protected static function newFactory(): RoomCategoryFactory
    {
        return RoomCategoryFactory::new();
    }

    /**
     * Route-model binding uses the slug. Existing `/rooms/{id}`
     * paths still bind via Room's default (id) key, so this only
     * affects category-scoped public URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Auto-generate a slug from `name` when the operator didn't
     * supply one, keeping uniqueness within (property_id, slug).
     */
    protected static function booted(): void
    {
        static::saving(function (RoomCategory $cat): void {
            if (! empty($cat->slug)) {
                return;
            }
            $base = Str::slug((string) $cat->name);
            if ($base === '') {
                $base = 'category';
            }

            $candidate = $base;
            $suffix = 1;
            while (
                static::query()
                    ->withoutGlobalScopes()
                    ->where('property_id', $cat->property_id)
                    ->where('slug', $candidate)
                    ->where('id', '!=', $cat->id)
                    ->exists()
            ) {
                $suffix++;
                $candidate = $base.'-'.$suffix;
            }
            $cat->slug = $candidate;
        });
    }

    /** @var list<string> */
    protected $guarded = ['id'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->onlyKeepLatest(20);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Tiny placeholder for blur-up loading — see Property model.
        $this->addMediaConversion('placeholder')->fit(Fit::Max, 32, 32)->quality(35);
        $this->addMediaConversion('thumb')->nonOptimized()->fit(Fit::Crop, 200, 200);
        $this->addMediaConversion('medium')->nonOptimized()->fit(Fit::Crop, 800, 600);
        $this->addMediaConversion('full')->nonOptimized()->fit(Fit::Max, 1920, 1920);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_type' => RoomBookingType::class,
            'status' => RoomStatus::class,
            'deposit_type' => DepositType::class,
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Reusable housekeeping checklist templates attached to this
     * category. A category with no attached templates opens empty
     * inspections until a manager attaches at least one. Multiple
     * templates union at snapshot time — see OpenRoomInspection.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<HousekeepingChecklist, $this>
     */
    public function checklists(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            HousekeepingChecklist::class,
            'housekeeping_checklist_room_category',
            'room_category_id',
            'housekeeping_checklist_id',
        )->withTimestamps();
    }

    public function maxOccupancy(): int
    {
        return $this->capacity_adults + $this->capacity_children;
    }

    /**
     * Aggregate guest rating across every room in this category.
     * Mirrors {@see Room::ratingSummary()} but rolls up the units,
     * so the landing-page category card can show a single star score
     * for "Deluxe" rather than per-unit averages.
     *
     * @return array{average: float, count: int}
     */
    public function ratingSummary(): array
    {
        $roomIds = $this->rooms()->pluck('id');
        if ($roomIds->isEmpty()) {
            return ['average' => 0.0, 'count' => 0];
        }

        $reviews = Review::query()
            ->whereIn('room_id', $roomIds)
            ->displayed();

        $count = (int) $reviews->count();
        $avg   = $count > 0 ? round((float) $reviews->avg('rating'), 1) : 0.0;

        return ['average' => $avg, 'count' => $count];
    }

    public function isLongStayCapable(): bool
    {
        return $this->booking_type->allowsLongStay() && $this->monthly_rate !== null;
    }

    /**
     * Whether this category offers the "with complimentary breakfast"
     * alternative rate. When true, both the guest funnel and every
     * manual reservation surface show a Room-only / With-breakfast
     * radio pair. When false, only the base rate applies.
     */
    public function hasBreakfastOption(): bool
    {
        return $this->breakfast_included_rate !== null
            && (int) $this->breakfast_included_rate > 0;
    }

    /**
     * The effective nightly rate to charge, given the guest's choice.
     * Falls back to base_rate whenever the tier isn't offered or the
     * guest declined it — keeps the caller unaware of the toggle.
     */
    public function effectiveNightlyRate(bool $withBreakfast): int
    {
        if ($withBreakfast && $this->hasBreakfastOption()) {
            return (int) $this->breakfast_included_rate;
        }

        return (int) $this->base_rate;
    }

    /**
     * @param  Builder<RoomCategory>  $query
     * @return Builder<RoomCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RoomStatus::Active->value);
    }

    /**
     * First gallery image flagged hero, falling back to the first
     * gallery item.
     */
    public function heroImage(): ?Media
    {
        $gallery = $this->getMedia('gallery');

        return $gallery->first(fn ($m) => (bool) $m->getCustomProperty('is_hero'))
            ?? $gallery->first();
    }
}
