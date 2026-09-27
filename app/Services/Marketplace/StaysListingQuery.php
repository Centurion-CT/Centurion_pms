<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Collection;

/**
 * Cross-tenant query for the /stays marketplace listing page.
 *
 * The one place in the app where Property + Room queries intentionally
 * bypass {@see TenantScope}. Every result carries its own tenant_id so
 * downstream code (property detail, booking) can re-enter tenant
 * context before mutating anything.
 *
 * Kept as a dedicated service so the "opt-out flag + eligibility
 * filter + cheapest-room join" logic lives in exactly one place -
 * the controller for /stays, the future property detail lookup and
 * any admin-side marketplace preview all read from here.
 */
final class StaysListingQuery
{
    /**
     * Search + filter listed properties for the marketplace grid.
     *
     * A property is eligible if:
     *   - opted into the marketplace (`is_listed_publicly = true`)
     *   - has at least one Active room (an empty property can't be
     *     booked, so listing it would waste a click)
     *   - has at least one Active room whose combined adult+child
     *     capacity meets the requested guest count (only when
     *     $guests > 0; otherwise this predicate is skipped)
     *
     * @param  array{
     *     query?: string|null,
     *     city?: string|null,
     *     guests?: int|null,
     *     max_price?: int|null,
     * }  $filters
     * @param  \Illuminate\Http\Request|null  $request  Incoming request; when
     *                                                  provided, each row's
     *                                                  `from_price` is resolved
     *                                                  through {@see RateResolver}
     *                                                  and `from_price_base` carries
     *                                                  the pre-adjustment anchor.
     * @return Collection<int, array{property: Property, from_price: int, from_price_base: int, room_count: int}>
     */
    public function search(array $filters = [], ?\Illuminate\Http\Request $request = null): Collection
    {
        $query = trim((string) ($filters['query'] ?? ''));
        $city = trim((string) ($filters['city'] ?? ''));
        $guests = max(0, (int) ($filters['guests'] ?? 0));
        $maxPrice = max(0, (int) ($filters['max_price'] ?? 0));
        $resolver = $request !== null ? app(RateResolver::class) : null;

        // whereHas('rooms') would apply Room's own TenantScope on the
        // subquery + fail closed to zero rows in central context. We
        // want cross-tenant so the subquery must also bypass the scope.
        $properties = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('is_listed_publicly', true)
            ->whereHas('rooms', fn ($q) => $q
                ->withoutGlobalScope(TenantScope::class)
                ->where('status', RoomStatus::Active->value))
            ->when($query !== '', fn ($q) => $q->where(function ($q2) use ($query): void {
                $q2->where('name', 'like', '%'.$query.'%')
                    ->orWhere('city', 'like', '%'.$query.'%')
                    ->orWhere('state', 'like', '%'.$query.'%');
            }))
            ->when($city !== '', fn ($q) => $q->where('city', $city))
            ->with(['media', 'tenant'])
            ->get();

        // Enrich each property with cheapest active room's base_rate +
        // active room count. Done in PHP after the fetch so the SQL
        // stays simple + engine-portable (SQLite for tests, Postgres
        // in prod both cover this path).
        return $properties
            ->map(function (Property $property) use ($guests, $maxPrice, $resolver, $request) {
                // Both Room + RoomCategory carry BelongsToTenant, so
                // the category eager-load has to bypass the scope too
                // or every room comes back with $r->category === null
                // (and $r->base_rate accessor falls to 0 as a result).
                $rooms = Room::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('property_id', $property->id)
                    ->where('status', RoomStatus::Active->value)
                    ->with(['category' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
                    ->get();

                if ($guests > 0) {
                    $rooms = $rooms->filter(
                        fn (Room $r) => ($r->capacity_adults + $r->capacity_children) >= $guests
                    );
                }

                $baseFromPrice = (int) $rooms
                    ->map(fn (Room $r): int => (int) $r->base_rate)
                    ->filter(fn (int $p): bool => $p > 0)
                    ->min();

                // Resolve each category through the rate rules and pick
                // the cheapest — same drift-safety loop used by the
                // detail-page sticky book card. Skips when no request
                // (dashboard preview / SEO crawler ingestion).
                $resolvedFromPrice = $baseFromPrice;
                if ($resolver !== null && $request !== null) {
                    foreach ($rooms as $r) {
                        if ($r->category === null) continue;
                        $rate = (int) $resolver->resolve($r->category, $request)['rate'];
                        if ($rate > 0 && $rate < $resolvedFromPrice) {
                            $resolvedFromPrice = $rate;
                        }
                    }
                }

                return [
                    'property'        => $property,
                    'from_price'      => $resolvedFromPrice,
                    'from_price_base' => $baseFromPrice,
                    'room_count'      => $rooms->count(),
                ];
            })
            ->filter(fn (array $row): bool => $row['room_count'] > 0)
            ->when($maxPrice > 0, fn ($c) => $c->filter(fn (array $r): bool => $r['from_price'] <= $maxPrice))
            ->values();
    }

    /**
     * Per-room-category availability for the property-detail page.
     * Groups the property's active rooms by category, then for each
     * category counts how many of its rooms are fully free for the
     * requested [$checkin, $checkout) range (checkout exclusive).
     *
     * When no dates are provided, every active room counts as
     * "available" - the page still shows the category with all its
     * units + a Reserve CTA that carries no date context forward.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     category: \App\Models\RoomCategory,
     *     total_units: int,
     *     available_units: int,
     *     first_available_room_id: int|null,
     *     stays_count: int,
     *     rating_avg: float|null,
     *     rating_count: int,
     *     rate: int,
     *     base_rate: int,
     *     rate_rule: \App\Models\RateRule|null,
     *     stay_nights: int,
     *     stay_total: int,
     *     stay_base_total: int,
     *     stay_nightly: list<array{date: string, rate: int, base_rate: int, rule_id: int|null, rule_label: string|null}>,
     * }>
     */
    public function categoryAvailability(
        int $propertyId,
        ?\Illuminate\Support\Carbon $checkin = null,
        ?\Illuminate\Support\Carbon $checkout = null,
        ?\Illuminate\Http\Request $request = null,
    ): \Illuminate\Support\Collection {
        // Both Room + RoomCategory carry BelongsToTenant. In central
        // context (no tenant current) the scope has to be bypassed
        // on both sides or every relation comes back empty.
        $rooms = Room::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('property_id', $propertyId)
            ->where('status', RoomStatus::Active->value)
            ->with(['category' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->get();

        if ($rooms->isEmpty()) return collect();

        $hasDates = $checkin !== null && $checkout !== null && $checkout->greaterThan($checkin);

        // Batched availability lookup - one query returns the ids of
        // every room in the property that has ANY block inside
        // [checkin, checkout). Anything not in that set is free.
        //
        // We roll our own query instead of AvailabilityService::isAvailable
        // because that service scopes to the current tenant via
        // AvailabilityBlock's BelongsToTenant trait; central-domain
        // callers (this one) run with no tenant current, so the scope
        // would fail closed to zero rows and every room would look
        // "available" regardless of real bookings.
        $blockedRoomIds = collect();
        // Peak category-level holds per category: for each night in the
        // range, how many category-level bookings overlap the category?
        // We take the MAX across the range so any night at capacity
        // limits the whole range (guest can't stay 3 nights if night 2
        // is full). Empty when dates aren't set.
        $categoryHoldPeaks = [];
        if ($hasDates) {
            $blockedRoomIds = \App\Models\AvailabilityBlock::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('room_id', $rooms->pluck('id'))
                ->whereDate('date', '>=', $checkin->toDateString())
                ->whereDate('date', '<', $checkout->toDateString())
                ->pluck('room_id')
                ->unique();

            $catIdList = array_values(array_unique($rooms
                ->filter(fn (Room $r) => $r->category !== null)
                ->map(fn (Room $r) => (int) $r->category->id)
                ->all()));
            if (! empty($catIdList)) {
                $rowSet = \App\Models\AvailabilityBlock::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->whereIn('room_category_id', $catIdList)
                    ->whereNull('room_id')
                    ->whereDate('date', '>=', $checkin->toDateString())
                    ->whereDate('date', '<', $checkout->toDateString())
                    ->get(['room_category_id', 'date']);
                $perNight = [];
                foreach ($rowSet as $row) {
                    $key = $row->room_category_id.'|'.$row->date;
                    $perNight[$key] = ($perNight[$key] ?? 0) + 1;
                }
                foreach ($perNight as $key => $count) {
                    [$catId, $_] = explode('|', $key);
                    $catId = (int) $catId;
                    $categoryHoldPeaks[$catId] = max($categoryHoldPeaks[$catId] ?? 0, $count);
                }
            }
        }

        // Batched social-proof lookups per category. Two queries, both
        // grouped by category id so we can attach counts/averages in
        // the map() without any N+1 back-and-forth.
        //
        // Stays: bookings that actually happened (CheckedIn or
        // CheckedOut). Pending / cancelled / no-show wouldn't be
        // truthful signals to a shopper.
        $roomIdList = $rooms->pluck('id')->all();
        $roomCategoryIndex = $rooms
            ->filter(fn (Room $r) => $r->category !== null)
            ->mapWithKeys(fn (Room $r) => [$r->id => $r->category->id])
            ->all();
        $stayedStatuses = [
            \App\Enums\BookingStatus::CheckedIn->value,
            \App\Enums\BookingStatus::CheckedOut->value,
        ];
        $staysCountByCategory = [];
        \App\Models\Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('booking_status', $stayedStatuses)
            ->where(function ($q) use ($roomIdList, $roomCategoryIndex) {
                $q->whereIn('room_id', $roomIdList)
                    ->orWhereIn('room_category_id', array_values(array_unique($roomCategoryIndex)));
            })
            ->select(['room_id', 'room_category_id'])
            ->get()
            ->each(function ($booking) use (&$staysCountByCategory, $roomCategoryIndex): void {
                $catId = $booking->room_category_id
                    ?? ($roomCategoryIndex[$booking->room_id] ?? null);
                if ($catId === null) return;
                $staysCountByCategory[$catId] = ($staysCountByCategory[$catId] ?? 0) + 1;
            });

        // Ratings: reviews attached to any room in this property. Since
        // the review carries room_id (nullable — null means the review
        // is property-wide), map back to the category via the rooms
        // list we already have in memory.
        $ratingAggBycategory = [];
        \App\Models\Review::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('room_id', $roomIdList)
            ->where('is_displayed', true)
            ->select(['room_id', 'rating'])
            ->get()
            ->each(function ($review) use (&$ratingAggBycategory, $roomCategoryIndex): void {
                $catId = $roomCategoryIndex[$review->room_id] ?? null;
                if ($catId === null) return;
                $bucket = $ratingAggBycategory[$catId] ?? ['sum' => 0, 'count' => 0];
                $bucket['sum'] += (int) $review->rating;
                $bucket['count'] += 1;
                $ratingAggBycategory[$catId] = $bucket;
            });

        // Dynamic rate resolver — matches operator-defined RateRule
        // rows against the guest's request context (device / referrer
        // / wall-clock). Resolved per category below; falls back to
        // base_rate when no rule matches or when no request is given
        // (dashboard-side previews).
        $resolver = $request !== null ? app(\App\Services\Marketplace\RateResolver::class) : null;
        $checkinRef = $checkin;
        $checkoutRef = $checkout;

        return $rooms
            ->filter(fn (Room $r) => $r->category !== null)
            ->groupBy(fn (Room $r) => $r->category->id)
            ->map(function ($group) use ($hasDates, $blockedRoomIds, $staysCountByCategory, $ratingAggBycategory, $resolver, $request, $checkinRef, $checkoutRef, $categoryHoldPeaks): array {
                /** @var \Illuminate\Support\Collection<int, Room> $group */
                $category = $group->first()->category;
                // Force-hydrate media on the category + surface gallery
                // URLs for the lightbox. The eager-load can't be
                // pushed to the parent query because ->with('media')
                // on RoomCategory would still trip TenantScope on
                // MediaLibrary's `mediable_type`-polymorphic fetch;
                // easier to just resolve URLs here where we already
                // have the category in scope.
                $galleryUrls = $category->getMedia('gallery')
                    ->map(fn ($m) => [
                        'thumb'  => (string) $m->getUrl('thumb'),
                        'medium' => (string) $m->getUrl('medium'),
                        'full'   => (string) $m->getUrl('full'),
                    ])
                    ->all();

                $total = $group->count();
                $staysCount = (int) ($staysCountByCategory[$category->id] ?? 0);
                $ratingBucket = $ratingAggBycategory[$category->id] ?? null;
                $ratingCount = (int) ($ratingBucket['count'] ?? 0);
                $ratingAvg = $ratingCount > 0
                    ? round(((float) $ratingBucket['sum']) / $ratingCount, 1)
                    : null;

                $rateInfo = $resolver !== null && $request !== null
                    ? $resolver->resolve($category, $request, $checkinRef)
                    : ['rate' => (int) $category->base_rate, 'base_rate' => (int) $category->base_rate, 'rule' => null];

                // Multi-night resolve: iterate each night in the
                // stay range so a 3-night booking spanning a
                // weekend surge picks up the higher rate on just
                // the Fri+Sat nights. Empty when no dates picked.
                $stayInfo = ($resolver !== null && $request !== null
                    && $checkinRef !== null && $checkoutRef !== null
                    && $checkoutRef->greaterThan($checkinRef))
                    ? $resolver->resolveStay($category, $request, $checkinRef, $checkoutRef)
                    : ['nights' => 0, 'total' => 0, 'base_total' => 0, 'nightly' => [], 'rule_ids' => []];

                if (! $hasDates) {
                    return [
                        'category'                => $category,
                        'total_units'             => $total,
                        'available_units'         => $total,
                        'first_available_room_id' => $group->first()?->id,
                        'gallery'                 => $galleryUrls,
                        'stays_count'             => $staysCount,
                        'rating_avg'              => $ratingAvg,
                        'rating_count'            => $ratingCount,
                        'rate'                    => $rateInfo['rate'],
                        'base_rate'               => $rateInfo['base_rate'],
                        'rate_rule'               => $rateInfo['rule'],
                        'stay_nights'             => 0,
                        'stay_total'              => 0,
                        'stay_base_total'         => 0,
                        'stay_nightly'            => [],
                    ];
                }

                $free = $group->filter(
                    fn (Room $r) => ! $blockedRoomIds->contains($r->id)
                );
                // Category-level holds on the /stays online path also
                // consume inventory even though they don't lock a
                // specific room. Subtract the peak hold-count across
                // the requested range so the "N units left" chip on
                // the marketplace card matches reality.
                $categoryHoldPeak = (int) ($categoryHoldPeaks[$category->id] ?? 0);
                $availableUnits = max(0, $free->count() - $categoryHoldPeak);

                return [
                    'category'                => $category,
                    'total_units'             => $total,
                    'available_units'         => $availableUnits,
                    'first_available_room_id' => $free->first()?->id,
                    'gallery'                 => $galleryUrls,
                    'stays_count'             => $staysCount,
                    'rating_avg'              => $ratingAvg,
                    'rating_count'            => $ratingCount,
                    'rate'                    => $rateInfo['rate'],
                    'base_rate'               => $rateInfo['base_rate'],
                    'rate_rule'               => $rateInfo['rule'],
                    'stay_nights'             => $stayInfo['nights'],
                    'stay_total'              => $stayInfo['total'],
                    'stay_base_total'         => $stayInfo['base_total'],
                    'stay_nightly'            => $stayInfo['nightly'],
                ];
            })
            ->sortBy(fn (array $row): int => (int) $row['category']->base_rate)
            ->values();
    }

    /**
     * Look up a listed property + its active rooms for the detail
     * page. Returns null when the slug doesn't resolve OR the
     * property has opted out - the controller 404s in that case so
     * off-market properties don't leak via the public URL.
     *
     * @return array{
     *     property: Property,
     *     rooms: Collection<int, Room>,
     *     from_price: int
     * }|null
     */
    public function findBySlug(string $slug): ?array
    {
        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $slug)
            ->where('is_listed_publicly', true)
            ->with(['media', 'tenant'])
            ->first();

        if ($property === null) return null;

        $rooms = Room::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active->value)
            ->with(['category' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->get();

        if ($rooms->isEmpty()) return null;

        $fromPrice = (int) $rooms
            ->map(fn (Room $r): int => (int) $r->base_rate)
            ->filter(fn (int $p): bool => $p > 0)
            ->min();

        return [
            'property'   => $property,
            'rooms'      => $rooms,
            'from_price' => $fromPrice,
        ];
    }

    /**
     * Earliest single-night slot (in the next 30 days) where at least
     * one category on the property has an available unit. Returns
     * `null` when no gap opens within the window.
     *
     * Used to seed the check-in / check-out defaults on the stays
     * detail page — both the Livewire availability browser AND the
     * right-column sticky book card call this so their seeded dates
     * match. `shift_days` is 0 when today is available, ≥ 1 when the
     * probe had to walk forward, and drives the "we moved you"
     * advisory banner.
     *
     * @return array{starts_on: \Illuminate\Support\Carbon, ends_on: \Illuminate\Support\Carbon, shift_days: int}|null
     */
    public function earliestAvailableSingleNight(int $propertyId): ?array
    {
        $today = \Illuminate\Support\Carbon::today();
        for ($offset = 0; $offset < 30; $offset++) {
            $candidate = $today->copy()->addDays($offset);
            $next = $candidate->copy()->addDay();
            $rows = $this->categoryAvailability($propertyId, $candidate, $next, null);
            $anyFree = $rows->contains(fn (array $row) => (int) ($row['available_units'] ?? 0) > 0);
            if ($anyFree) {
                return [
                    'starts_on'  => $candidate,
                    'ends_on'    => $next,
                    'shift_days' => $offset,
                ];
            }
        }
        return null;
    }

    /**
     * Distinct cities that currently host at least one listed property.
     * Feeds the city-filter dropdown on /stays.
     *
     * @return Collection<int, string>
     */
    public function cities(): Collection
    {
        return Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('is_listed_publicly', true)
            ->whereHas('rooms', fn ($q) => $q
                ->withoutGlobalScope(TenantScope::class)
                ->where('status', RoomStatus::Active->value))
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');
    }
}
