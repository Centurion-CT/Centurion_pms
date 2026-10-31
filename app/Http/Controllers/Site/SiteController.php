<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Models\Property;
use App\Site\BookingSite;
use App\Site\Listing;
use App\Site\Stay;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The booking site's browsing pages on each business's address
 * (docs/MULTITENANCY.md, step 5; design: design/website/c-map):
 * the home page with the map, and the results page with its detail
 * panel. Both are plain GET pages, so every view has a shareable URL
 * and works without JavaScript; the map is the only scripted part.
 */
final class SiteController
{
    public function __construct(private readonly BookingSite $site) {}

    public function home(Request $request): View|RedirectResponse
    {
        $stay = Stay::fromRequest($request);
        $branches = $this->site->branches();

        // No building is live yet: the address is the staff login, as
        // before the booking site existed.
        if ($branches->isEmpty()) {
            return redirect()->route('tenant.login');
        }
        $listings = $this->site->listings($branches, $stay);

        return view('site.home', [
            'stay' => $stay,
            'branches' => $branches,
            'freeTonight' => $branches->mapWithKeys(fn (Property $b) => [$b->id => $this->site->freeTonight($b)]),
            'featured' => $this->byPrice($listings)->take(4),
            'listingCount' => $listings->count(),
            'longStays' => $this->longStays($branches),
            'mapPins' => $this->mapPins($branches),
            'city' => $this->site->city(),
        ]);
    }

    public function results(Request $request): View
    {
        $stay = Stay::fromRequest($request);
        $branches = $this->site->branches();
        $where = collect((array) $request->query('where', []))->map(fn ($s) => (string) $s)
            ->intersect($branches->pluck('slug'))->values();
        $size = (string) $request->query('size', '');
        $sort = $request->query('sort') === 'free' ? 'free' : 'price';

        $all = $this->site->listings($branches, $stay);
        $sizes = $all->map(fn (Listing $l) => $l->type->type)->filter()->unique()->sort()->values();
        $shown = $all
            ->when($where->isNotEmpty(), fn ($c) => $c->filter(fn (Listing $l) => $where->contains($l->branch->slug)))
            ->when($size !== '', fn ($c) => $c->filter(fn (Listing $l) => $l->type->type === $size))
            ->values();
        $shown = $sort === 'free' ? $shown->sortByDesc('freeForStay')->values() : $this->byPrice($shown);

        $open = null;
        if (($pick = (int) $request->query('open', 0)) > 0) {
            $open = $this->site->listings($branches, $stay, includeTaken: true)
                ->first(fn (Listing $l) => $l->type->id === $pick);
        }

        return view('site.results', [
            'stay' => $stay,
            'branches' => $branches,
            'where' => $where,
            'size' => $size,
            'sort' => $sort,
            'sizes' => $sizes,
            'countByBranch' => $all->countBy(fn (Listing $l) => $l->branch->slug),
            'listings' => $shown,
            'open' => $open,
            'calendar' => $open ? $this->calendar($open, $stay) : null,
            'mapPins' => $this->mapPins($branches),
        ]);
    }

    /** @param  Collection<int, Listing>  $listings */
    private function byPrice(Collection $listings): Collection
    {
        return $listings->sortBy(fn (Listing $l) => $l->quote?->total ?? PHP_INT_MAX)->values();
    }

    /**
     * Apartment types with a monthly rate, for the long-stay band.
     *
     * @param  Collection<int, Property>  $branches
     * @return Collection<int, array{name: string, branch: string, night: int, month: int}>
     */
    private function longStays(Collection $branches): Collection
    {
        return $branches
            ->flatMap(fn (Property $b) => $b->roomCategories
                ->filter(fn ($t) => (int) $t->monthly_rate > 0)
                ->map(fn ($t) => ['name' => $t->name, 'branch' => $b->name, 'night' => (int) $t->base_rate, 'month' => (int) $t->monthly_rate]))
            ->sortBy('month')
            ->take(4)
            ->values();
    }

    /**
     * One map marker per building with a position.
     *
     * @param  Collection<int, Property>  $branches
     * @return list<array{slug: string, name: string, lat: float, lng: float, from: int}>
     */
    private function mapPins(Collection $branches): array
    {
        return $branches
            ->filter(fn (Property $b) => $b->latitude !== null && $b->longitude !== null)
            ->map(fn (Property $b) => [
                'slug' => $b->slug,
                'name' => $b->name,
                'lat' => (float) $b->latitude,
                'lng' => (float) $b->longitude,
                'from' => (int) $b->roomCategories->min('base_rate'),
            ])
            ->values()
            ->all();
    }

    /**
     * The months the stay touches (one or two) as calendar cells for the
     * detail panel.
     *
     * @return list<array{month: string, cells: list<array{day: ?int, state: string}>}>
     */
    private function calendar(Listing $listing, Stay $stay): array
    {
        $stayDates = $stay->dates();
        $months = [];
        $first = $stay->checkin->copy()->startOfMonth();
        $last = $stay->checkout->copy()->startOfMonth();
        for ($m = $first->copy(); $m->lte($last) && count($months) < 2; $m->addMonthNoOverflow()) {
            $cells = array_fill(0, $m->dayOfWeekIso - 1, ['day' => null, 'state' => '']);
            for ($d = $m->copy(); $d->month === $m->month; $d->addDay()) {
                $date = $d->toDateString();
                $cells[] = ['day' => $d->day, 'state' => match (true) {
                    $date === $stay->checkin->toDateString() => 'start',
                    $date === $stay->checkout->toDateString() => 'end',
                    in_array($date, $stayDates, true) => 'range',
                    ($listing->strip[$date] ?? null) === 'gone' => 'gone',
                    default => '',
                }];
            }
            $months[] = ['month' => $m->format('F'), 'cells' => $cells];
        }

        return $months;
    }
}
