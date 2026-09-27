<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PropertyType;
use App\Enums\StockModel;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property PropertyType $type
 * @property StockModel $stock_model
 * @property string|null $description
 * @property string $address
 * @property string|null $city
 * @property string|null $state
 * @property bool $is_live
 * @property bool $is_listed_publicly
 * @property string $stays_online_hold_mode  'room'|'category' — how /stays online bookings hold inventory. See migration.
 * @property string $unit_mode 'multi_room'|'single_unit' — see 2026_09_20_120000 migration.
 * @property string $public_slug
 * @property array<string,mixed>|null $amenities
 * @property string $primary_color
 * @property string|null $tagline
 * @property string $font_theme
 * @property array<string,bool>|null $section_visibility
 * @property bool $whatsapp_button_enabled
 * @property string|null $whatsapp_button_prefill
 * @property array<string,mixed>|null $house_rules
 * @property array<string,mixed>|null $checkout_policy
 * @property-read Tenant $tenant
 */
class Property extends Model implements HasMedia
{
    use BelongsToTenant;

    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Three property-level collections per PRD §6.5.1:
     *  - `hero`  one full-bleed banner shown above the fold on landing
     *  - `logo`  PNG/SVG used in header + email templates
     *  - `gallery` general property photos (separate from per-room galleries)
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('hero')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']);

        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Tiny low-quality placeholder for blur-up loading. ~1–2 KB
        // per image - small enough to inline or render directly while
        // the full-size version streams in. Fit::Max preserves aspect
        // ratio so the wrapper can crop it the same way the real
        // image will.
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
        return [
            'type' => PropertyType::class,
            'stock_model' => StockModel::class,
            'is_live' => 'boolean',
            'is_listed_publicly' => 'boolean',
            'amenities' => 'array',
            'nearby_places' => 'array',
            'house_rules' => 'array',
            'checkout_policy' => 'array',
            'section_visibility' => 'array',
            'whatsapp_button_enabled' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'geo_fence_radius_meters' => 'integer',
            'guest_coord_min_score' => 'integer',
        ];
    }

    /**
     * Currency the property renders amounts in. Falls back to NGN
     * when the column is null / not a recognised ISO 4217 code —
     * matches the pre-multi-currency default. Casting is done in
     * an accessor rather than the casts() array so an invalid code
     * on a legacy row (say a hand-inserted "XXX") doesn't blow up
     * the whole model load.
     */
    public function getCurrencyAttribute(): Currency
    {
        return Currency::tryFromCode($this->attributes['currency_code'] ?? null)
            ?? Currency::NGN;
    }

    public function setCurrencyAttribute(Currency|string|null $value): void
    {
        $this->attributes['currency_code'] = $value instanceof Currency
            ? $value->value
            : (is_string($value) ? strtoupper(trim($value)) : null);
    }

    /**
     * The property IS the listing (standalone apartment / condo /
     * bungalow) — no operator-managed room categories or per-room
     * housekeeping. See unit_mode migration.
     */
    public function isSingleUnit(): bool
    {
        return ($this->unit_mode ?? 'multi_room') === 'single_unit';
    }

    /**
     * Trust-score for a guest's captured device coords, 0-100.
     *
     * 100 = right at the property pin
     * 100→0 linearly across the fence radius
     * 0    = at or beyond the fence
     *
     * Returns 100 when the property hasn't set coordinates OR the
     * fence radius is 0 - the gate is effectively "off" so the
     * portal behaves the same as before geo-fencing shipped.
     */
    public function computeGuestCoordScore(float $lat, float $lng): int
    {
        if (! $this->hasCoordinates()) return 100;
        $fence = (int) ($this->geo_fence_radius_meters ?? 0);
        if ($fence <= 0) return 100;

        $distance = self::haversineDistanceMeters(
            (float) $this->latitude, (float) $this->longitude,
            $lat, $lng,
        );

        if ($distance <= 0) return 100;
        if ($distance >= $fence) return 0;

        return (int) round(100 * (1 - ($distance / $fence)));
    }

    /**
     * Great-circle distance in metres between two lat/lng pairs.
     * Standard haversine - accurate enough for the sub-kilometre
     * fences the guest portal uses.
     */
    public static function haversineDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * True when the operator has both a fence set AND a non-zero
     * min-score. Off means the portal doesn't gate any action even
     * if lat/lng were captured.
     */
    public function portalGateEnabled(): bool
    {
        return $this->hasCoordinates()
            && (int) ($this->geo_fence_radius_meters ?? 0) > 0
            && (int) ($this->guest_coord_min_score ?? 0) > 0;
    }

    /**
     * True when the property has geolocated itself - powers the
     * "Where we are" map on the guest portal + any future
     * distance/route features. Both columns are nullable so the
     * accessor guards against half-configured properties.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null
            && $this->longitude !== null
            && (float) $this->latitude !== 0.0
            && (float) $this->longitude !== 0.0;
    }

    /**
     * Comma-joined address for copy/share on the portal. Skips
     * empty parts so a Lagos-only property doesn't read
     * "Address,,,Lagos".
     */
    public function fullAddress(): string
    {
        return collect([$this->address, $this->city, $this->state])
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->implode(', ');
    }

    protected static function newFactory(): PropertyFactory
    {
        return PropertyFactory::new();
    }

    /**
     * Multi-property URLs bind by slug (/branches/{property:slug})
     * because ids leak growth signal and don't survive a re-parented
     * property row. Uniqueness is scoped per-tenant at the DB level.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Fills `slug` before insert if the caller didn't provide one.
     * Deterministic: base is Str::slug(name), collisions inside the
     * same tenant get a numeric suffix. Runs on `creating` so brand-
     * new branch rows never hit the unique index blind.
     */
    protected static function booted(): void
    {
        static::creating(function (Property $property): void {
            if (empty($property->slug)) {
                $base = Str::slug((string) $property->name) ?: 'branch';
                $candidate = $base;
                $suffix = 1;
                while (
                    static::query()
                        ->where('tenant_id', $property->tenant_id)
                        ->where('slug', $candidate)
                        ->exists()
                ) {
                    $suffix++;
                    $candidate = $base.'-'.$suffix;
                }
                $property->slug = $candidate;
            }

            // Portal QR slug - separate from the human-readable branch
            // slug because it's what a guest scans + we don't want it
            // guessable ("citistay-lagos" ≠ "kf7q9m…"). One QR per
            // property, printed once, hangs in the lobby.
            if (empty($property->portal_qr_slug)) {
                do {
                    $candidate = Str::lower(Str::random(24));
                } while (static::query()->where('portal_qr_slug', $candidate)->exists());
                $property->portal_qr_slug = $candidate;
            }

            // Marketplace public_slug is globally unique (lives under
            // keverna.com/stays/{slug}, not a tenant subdomain), so
            // dedup against every property regardless of tenant.
            if (empty($property->public_slug)) {
                $base = Str::slug((string) ($property->name ?? '')) ?: 'stay';
                $candidate = $base;
                $n = 2;
                while (
                    static::query()
                        ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                        ->where('public_slug', $candidate)
                        ->exists()
                ) {
                    $candidate = $base.'-'.$n;
                    $n++;
                }
                $property->public_slug = $candidate;
            }
        });
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return HasMany<RoomCategory, $this>
     */
    public function roomCategories(): HasMany
    {
        return $this->hasMany(RoomCategory::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Whether a named landing-page section should render publicly (PRD §6.5.1).
     * Defaults to visible when the operator hasn't toggled the setting.
     */
    public function isSectionVisible(string $section): bool
    {
        $vis = $this->section_visibility ?? [];

        return ! array_key_exists($section, $vis) || (bool) $vis[$section];
    }

    /**
     * Canonical shape for house_rules - every consumer (landing, category
     * detail, dashboard form) reads through here so a missing key never
     * crashes a view. Empty-string values are treated as "not set" by the
     * presenter so the row simply doesn't render.
     *
     * Keys:
     *   check_in_from / check_in_to / check_out_from / check_out_to - HH:MM
     *   cancellation_policy / early_checkout / children_policy /
     *   pets_policy / smoking_policy / quiet_hours - free text
     *
     * @return array{
     *     check_in_from: string,
     *     check_in_to: string,
     *     check_out_from: string,
     *     check_out_to: string,
     *     cancellation_policy: string,
     *     early_checkout: string,
     *     children_policy: string,
     *     pets_policy: string,
     *     smoking_policy: string,
     *     quiet_hours: string
     * }
     */
    /**
     * Extract the 11-char YouTube video id from whatever URL shape the
     * operator pasted into Property Settings. Supports:
     *  - https://www.youtube.com/watch?v=ID
     *  - https://youtu.be/ID
     *  - https://www.youtube.com/embed/ID
     *  - https://www.youtube.com/shorts/ID
     *  - https://www.youtube.com/live/ID
     * Returns null when the URL is empty, malformed, or from a host we
     * don't recognise — the stays page just skips the video section
     * silently rather than embedding a broken player.
     */
    public function youtubeVideoId(): ?string
    {
        $raw = trim((string) $this->video_url);
        if ($raw === '') {
            return null;
        }

        if (preg_match('~^https?://(?:www\.)?youtu\.be/([A-Za-z0-9_-]{11})~', $raw, $m)) {
            return $m[1];
        }
        if (preg_match('~^https?://(?:www\.|m\.)?youtube\.com/watch\?[^\#]*[?&]?v=([A-Za-z0-9_-]{11})~', $raw, $m)) {
            return $m[1];
        }
        if (preg_match('~^https?://(?:www\.|m\.)?youtube\.com/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})~', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Resolve the operator's pasted video_url into everything the
     * frontend needs to render it. Broader than {@see youtubeVideoId()},
     * which is kept for callers that still switch on kind manually.
     *
     * Return shape:
     *   ['kind' => 'youtube', 'embed_url' => string, 'poster' => string]
     *   ['kind' => 'vimeo',   'embed_url' => string, 'poster' => null]
     *   ['kind' => 'file',    'src' => string,        'poster' => null]
     * or null when the URL is empty / unrecognised.
     *
     * @return array{kind: string, embed_url?: string, src?: string, poster: ?string}|null
     */
    public function videoEmbed(): ?array
    {
        $raw = trim((string) $this->video_url);
        if ($raw === '') {
            return null;
        }

        if ($id = $this->youtubeVideoId()) {
            return [
                'kind'      => 'youtube',
                'embed_url' => 'https://www.youtube-nocookie.com/embed/'.$id
                             .'?autoplay=1&rel=0&modestbranding=1&playsinline=1',
                'poster'    => 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg',
            ];
        }

        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d+)~i', $raw, $m)) {
            return [
                'kind'      => 'vimeo',
                'embed_url' => 'https://player.vimeo.com/video/'.$m[1].'?autoplay=1&title=0&byline=0&portrait=0',
                'poster'    => null,
            ];
        }

        if (preg_match('~\.(mp4|webm|mov|m4v|ogg|ogv)(?:\?[^\s]*)?$~i', $raw)) {
            return [
                'kind'   => 'file',
                'src'    => $raw,
                'poster' => null,
            ];
        }

        return null;
    }

    public function houseRules(): array
    {
        $raw = is_array($this->house_rules) ? $this->house_rules : [];
        $defaults = [
            'check_in_from' => '',
            'check_in_to' => '',
            'check_out_from' => '',
            'check_out_to' => '',
            'cancellation_policy' => '',
            'early_checkout' => '',
            'children_policy' => '',
            'pets_policy' => '',
            'smoking_policy' => '',
            'quiet_hours' => '',
        ];

        return array_map(static fn ($v): string => is_string($v) ? trim($v) : '',
            array_merge($defaults, array_intersect_key($raw, $defaults)));
    }

    /**
     * Late-checkout policy - sits next to the check-in/out windows in
     * house_rules but is operational (settled money at checkout) rather
     * than display copy. `late_checkout_enabled` gates the whole thing:
     * a saved-but-disabled policy keeps prior config around for one-
     * click re-enable without re-typing numbers.
     *
     * `late_checkout_full_day_after_hours` of 0 means "never trip a
     * full-day charge, keep billing hourly forever" - the calculator
     * treats it that way.
     *
     * Early-checkout keys drive the "guest leaving before booked
     * checkout with unused paid nights" resolution:
     *  - `early_checkout_default_mode` = 'ask' shows the operator
     *    modal (default); 'keep' | 'wallet' | 'cash' auto-applies.
     *  - `early_checkout_refund_percent` = 0-100 of the unused amount.
     *    0 = non-refundable policy, 100 = full refund of the unused
     *    nights, in-between = cancellation-fee style.
     *
     * @return array{
     *     late_checkout_enabled: bool,
     *     late_checkout_grace_minutes: int,
     *     late_checkout_hourly_fee: int,
     *     late_checkout_full_day_after_hours: int,
     *     early_checkout_default_mode: string,
     *     early_checkout_refund_percent: int
     * }
     */
    public function checkoutPolicy(): array
    {
        $raw = is_array($this->checkout_policy) ? $this->checkout_policy : [];

        $mode = (string) ($raw['early_checkout_default_mode'] ?? 'ask');
        if (! in_array($mode, ['ask', 'keep', 'wallet', 'cash'], true)) {
            $mode = 'ask';
        }
        $percent = (int) ($raw['early_checkout_refund_percent'] ?? 100);
        $percent = max(0, min(100, $percent));

        return [
            'late_checkout_enabled' => (bool) ($raw['late_checkout_enabled'] ?? false),
            'late_checkout_grace_minutes' => max(0, (int) ($raw['late_checkout_grace_minutes'] ?? 0)),
            'late_checkout_hourly_fee' => max(0, (int) ($raw['late_checkout_hourly_fee'] ?? 0)),
            'late_checkout_full_day_after_hours' => max(0, (int) ($raw['late_checkout_full_day_after_hours'] ?? 0)),
            'early_checkout_default_mode' => $mode,
            'early_checkout_refund_percent' => $percent,
        ];
    }

    /**
     * True when at least one rule field is populated - call sites use
     * this to decide whether to render the rules card at all.
     */
    public function hasHouseRules(): bool
    {
        foreach ($this->houseRules() as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Aggregate guest rating for the landing-page badge (PRD §10).
     * Returns a 0.0–5.0 average and the count of displayed reviews - call
     * sites can format either ("4.8 · 24 reviews") without re-aggregating.
     *
     * @return array{average: float, count: int}
     */
    public function ratingSummary(): array
    {
        $reviews = $this->reviews()->displayed();
        $count = (int) $reviews->count();
        $avg = $count > 0
            ? round((float) $reviews->avg('rating'), 1)
            : 0.0;

        return ['average' => $avg, 'count' => $count];
    }
}
