<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositType;
use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string $name
 * @property string $type
 * @property string|null $description
 * @property int $capacity_adults
 * @property int $capacity_children
 * @property int $base_rate
 * @property int|null $monthly_rate
 * @property int $min_nights
 * @property int $weekly_discount_percent
 * @property int $monthly_discount_percent
 * @property DepositType $deposit_type
 * @property int $deposit_value
 * @property RoomBookingType $booking_type
 * @property RoomStatus $status
 * @property-read Tenant $tenant
 * @property-read Property $property
 */
class Room extends Model implements HasMedia
{
    use BelongsToTenant;

    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Cap each room at 20 photos (PRD §6.3.3). Accepted: JPG, PNG, WebP ≤ 5MB
     * (cap enforced by media-library.max_file_size).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->onlyKeepLatest(20);
    }

    /**
     * Three sizes per PRD §6.3.3: thumbnail / medium / full. All run on the
     * default queue connection (sync in tests, redis in prod).
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Tiny placeholder for blur-up loading — see Property model.
        $this->addMediaConversion('placeholder')
            ->fit(Fit::Max, 32, 32)
            ->quality(35);

        $this->addMediaConversion('thumb')
            ->nonOptimized()
            ->fit(Fit::Crop, 200, 200);

        $this->addMediaConversion('medium')
            ->nonOptimized()
            ->fit(Fit::Crop, 800, 600);

        $this->addMediaConversion('full')
            ->nonOptimized()
            ->fit(Fit::Max, 1920, 1920);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // booking_type + deposit_type used to be columns on this table;
        // post-Phase 2 they live on RoomCategory and are surfaced via
        // accessors. Status stays — it's per-unit visibility.
        return [
            'status' => RoomStatus::class,
            'housekeeping_status' => \App\Enums\HousekeepingStatus::class,
            'housekeeping_updated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
    }

    /**
     * Auto-fill qr_slug on create so every room gets one without
     * every seeder / factory / import having to remember. The slug
     * is used ONLY on the guest-portal URL — it's unguessable so
     * it can serve as one of three factors (slug + phone + PIN).
     */
    protected static function booted(): void
    {
        static::creating(function (Room $room): void {
            if (empty($room->qr_slug)) {
                do {
                    $candidate = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(24));
                } while (static::query()->where('qr_slug', $candidate)->exists());
                $room->qr_slug = $candidate;
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
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * @return HasMany<RoomPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(RoomPrice::class);
    }

    public function maxOccupancy(): int
    {
        return $this->capacity_adults + $this->capacity_children;
    }

    public function isLongStayCapable(): bool
    {
        return $this->booking_type->allowsLongStay() && $this->monthly_rate !== null;
    }

    /*
    |---------------------------------------------------------------
    | Category-delegated attribute accessors
    |---------------------------------------------------------------
    | Post-Phase 2: RoomCategory is the single source of truth for
    | the shared fields. The accessors below let existing call sites
    | keep saying `$room->base_rate`, `$room->capacity_adults`, etc.
    | without joining manually — Laravel's accessor pipeline auto-
    | loads the relation via lazy-loading.
    |
    | A Room without a category is a data-integrity error post-Phase
    | 2 (room_category_id is NOT NULL on MySQL; SQLite relies on the
    | application guarantee). The accessors return sensible defaults
    | (0 / 1 / null) so a bug doesn't crash the operator dashboard.
    */

    public function getNameAttribute(): string
    {
        $cat = $this->category;
        if ($cat === null) {
            return $this->room_number !== null ? (string) $this->room_number : 'Room';
        }

        return $this->room_number !== null
            ? $cat->name.' · '.$this->room_number
            : $cat->name;
    }

    public function getTypeAttribute(): ?string
    {
        return $this->category?->type;
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->category?->description;
    }

    public function getCapacityAdultsAttribute(): int
    {
        return (int) ($this->category?->capacity_adults ?? 0);
    }

    public function getCapacityChildrenAttribute(): int
    {
        return (int) ($this->category?->capacity_children ?? 0);
    }

    public function getBaseRateAttribute(): int
    {
        return (int) ($this->category?->base_rate ?? 0);
    }

    public function getMonthlyRateAttribute(): ?int
    {
        $value = $this->category?->monthly_rate;

        return $value !== null ? (int) $value : null;
    }

    public function getMinNightsAttribute(): int
    {
        return (int) ($this->category?->min_nights ?? 1);
    }

    public function getWeeklyDiscountPercentAttribute(): int
    {
        return (int) ($this->category?->weekly_discount_percent ?? 0);
    }

    public function getMonthlyDiscountPercentAttribute(): int
    {
        return (int) ($this->category?->monthly_discount_percent ?? 0);
    }

    public function getDepositTypeAttribute(): ?\App\Enums\DepositType
    {
        return $this->category?->deposit_type;
    }

    public function getDepositValueAttribute(): int
    {
        return (int) ($this->category?->deposit_value ?? 0);
    }

    public function getBookingTypeAttribute(): ?\App\Enums\RoomBookingType
    {
        return $this->category?->booking_type;
    }

    /**
     * Visible-on-booking-engine scope (PRD §6.3.1 — only Active rooms appear
     * publicly).
     *
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RoomStatus::Active->value);
    }

    /**
     * Order rooms by the same string $room->name resolves to (see
     * ::getNameAttribute) — "{category.name} · {room_number}". A direct
     * orderBy('name') fails at SQL because the physical `name` column
     * was dropped in the Phase 2 refactor; a JOIN through room_categories
     * would break ->active() (ambiguous `status` / `deleted_at`), so
     * this uses a correlated subquery instead.
     *
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeOrderByDisplayName(Builder $query): Builder
    {
        return $query
            ->orderBy(
                RoomCategory::select('name')
                    ->whereColumn('room_categories.id', 'rooms.room_category_id')
                    ->limit(1)
            )
            ->orderBy('rooms.room_number');
    }

    /**
     * The room's hero shot for the landing page card (PRD §6.3.3).
     *
     * Lookup order:
     *   1. The room's own gallery (legacy / per-unit overrides)
     *   2. The category's gallery (the new authoritative source)
     *
     * "Hero" is the first item flagged with `is_hero=true` in
     * either gallery, falling back to the first image.
     */
    public function heroImage(): ?Media
    {
        $ownGallery = $this->getMedia('gallery');
        if ($ownGallery->isNotEmpty()) {
            return $ownGallery->first(fn ($m) => (bool) $m->getCustomProperty('is_hero'))
                ?? $ownGallery->first();
        }

        $cat = $this->category;
        if ($cat === null) {
            return null;
        }
        $catGallery = $cat->getMedia('gallery');

        return $catGallery->first(fn ($m) => (bool) $m->getCustomProperty('is_hero'))
            ?? $catGallery->first();
    }

    /**
     * Aggregate per-room rating. Only counts displayed reviews whose
     * `room_id` matches this row. Property-wide reviews (room_id=null)
     * do NOT count toward the per-room average — the room detail page
     * shows the property aggregate alongside this number for context.
     *
     * @return array{average: float, count: int}
     */
    public function ratingSummary(): array
    {
        $reviews = Review::query()
            ->where('room_id', $this->id)
            ->displayed();
        $count = (int) $reviews->count();
        $avg = $count > 0
            ? round((float) $reviews->avg('rating'), 1)
            : 0.0;

        return ['average' => $avg, 'count' => $count];
    }
}
