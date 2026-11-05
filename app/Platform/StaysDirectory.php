<?php

declare(strict_types=1);

namespace App\Platform;

use App\Enums\TenantStatus;
use App\Models\Property;
use App\Models\Review;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Pricing\PricingCalculator;
use App\Site\BookingSite;
use App\Site\Listing;
use App\Site\Photo;
use App\Site\Stay;
use Illuminate\Support\Collection;

/**
 * The Stays marketplace on the central domain: live, bookable buildings of
 * businesses that chose to be listed (Settings → Business) and are open.
 *
 * The one reader across businesses besides the platform pages, so it reads
 * only public facts, and only for businesses in listed(). Guests book on
 * the business's own site, which keeps payments and bookings with it.
 */
final class StaysDirectory
{
    public function anyListed(): bool
    {
        return $this->search()->isNotEmpty();
    }

    /** Cities with at least one listed building, for the filter. */
    public function cities(): Collection
    {
        return $this->search()->pluck('city')->filter()->unique()->sort()->values();
    }

    /**
     * @param  Stay|null  $stay  With dates, only buildings free for every
     *                           night are returned, priced for the stay.
     * @return Collection<int, ListedStay>
     */
    public function search(string $query = '', string $city = '', int $guests = 0, ?Stay $stay = null): Collection
    {
        $properties = $this->properties($query, $city);
        $types = $this->types($properties->pluck('id'), $guests)->groupBy('property_id');
        $reviews = $this->reviews($properties->pluck('id'));

        $rows = $properties
            ->filter(fn (Property $p) => $types->has($p->id))
            ->map(fn (Property $p) => new ListedStay(
                tenantId: $p->tenant_id,
                propertyId: $p->id,
                building: (string) $p->name,
                business: (string) $p->tenant->name,
                city: (string) $p->city,
                slug: (string) $p->slug,
                host: $p->tenant->primaryHost(),
                photo: $this->photo($p, $types[$p->id]),
                fromPerNight: (int) $types[$p->id]->min('base_rate'),
                rating: round((float) ($reviews[$p->id]->average ?? 0), 1),
                reviews: (int) ($reviews[$p->id]->total ?? 0),
            ))
            ->sortBy('fromPerNight')
            ->values();

        return $stay === null ? $rows : $this->freeFor($rows, $stay);
    }

    /** @return Collection<int, Property> */
    private function properties(string $query, string $city): Collection
    {
        $listed = Tenant::query()
            ->whereNotNull('listed_on_stays_at')
            ->whereIn('status', [TenantStatus::Trial->value, TenantStatus::Active->value]);
        $like = '%'.addcslashes(mb_strtolower(trim($query)), '%_\\').'%';

        return Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('is_live', true)
            ->whereIn('tenant_id', (clone $listed)->select('id'))
            ->when(trim($query) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(city) like ?', [$like])
                ->orWhereRaw('lower(state) like ?', [$like])
                ->orWhereIn('tenant_id', (clone $listed)->whereRaw('lower(name) like ?', [$like])->select('id'))))
            ->when(trim($city) !== '', fn ($q) => $q->whereRaw('lower(city) = ?', [mb_strtolower(trim($city))]))
            ->with(['media', 'tenant'])
            ->get();
    }

    /**
     * Apartment types guests could book: active, with an active room, big
     * enough for the party.
     *
     * @return Collection<int, RoomCategory>
     */
    private function types(Collection $propertyIds, int $guests): Collection
    {
        return RoomCategory::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('property_id', $propertyIds)
            ->active()
            ->whereHas('rooms', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->active())
            ->when($guests > 0, fn ($q) => $q->where('capacity_adults', '>=', $guests))
            ->with('media')
            ->get();
    }

    private function reviews(Collection $propertyIds): Collection
    {
        return Review::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('property_id', $propertyIds)
            ->where('is_displayed', true)
            ->selectRaw('property_id, avg(rating) as average, count(*) as total')
            ->groupBy('property_id')
            ->toBase()
            ->get()
            ->keyBy('property_id');
    }

    private function photo(Property $property, Collection $types): ?string
    {
        $media = $property->getFirstMedia('hero') ?? $property->getFirstMedia('gallery')
            ?? $types->map(fn (RoomCategory $t) => $t->getFirstMedia('gallery'))->filter()->first();

        return $media !== null ? url(Photo::url($media)) : null;
    }

    /**
     * Asks each business's own booking site what's free, so the marketplace
     * and the site can't disagree.
     *
     * @param  Collection<int, ListedStay>  $rows
     * @return Collection<int, ListedStay>
     */
    private function freeFor(Collection $rows, Stay $stay): Collection
    {
        $free = [];
        $tenants = Tenant::query()->whereIn('id', $rows->pluck('tenantId')->unique())->get();
        foreach ($tenants as $tenant) {
            $tenant->execute(function () use ($stay, &$free): void {
                $site = new BookingSite(app(PricingCalculator::class));
                foreach ($site->listings($site->branches(), $stay)->groupBy(fn (Listing $l) => $l->branch->id) as $id => $listings) {
                    $free[$id] = [(int) $listings->sum('freeForStay'), $listings->map(fn (Listing $l) => $l->quote?->total)->filter()->min()];
                }
            });
        }

        return $rows
            ->filter(fn (ListedStay $row) => isset($free[$row->propertyId]))
            ->map(fn (ListedStay $row) => $row->withAvailability(...$free[$row->propertyId]))
            ->sortBy('total')
            ->values();
    }
}
