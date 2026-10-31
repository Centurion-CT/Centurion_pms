<?php

declare(strict_types=1);

namespace App\Site;

use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What the booking site shows for the current business
 * (docs/MULTITENANCY.md, step 5; design/website/c-map): its buildings,
 * and each apartment type's free count per night and price for a stay.
 *
 * Free counts follow AvailabilityService::categoryFreeUnits (active rooms
 * in the type, less room-level and type-level blocks that night) but are
 * read for every type and night in two queries rather than two per night.
 */
final class BookingSite
{
    /** Nights in an availability strip, starting at check-in. */
    public const STRIP_NIGHTS = 14;

    /** @var Collection<int, Property>|null */
    private ?Collection $branches = null;

    public function __construct(private readonly PricingCalculator $pricing) {}

    public function business(): Tenant
    {
        /** @var Tenant */
        return Tenant::current();
    }

    /** The business's logo (Tenant::logoUrl), if it has uploaded one. */
    public function logo(): ?string
    {
        return $this->business()->logoUrl();
    }

    /** The city every building is in, or null when they're in several. */
    public function city(): ?string
    {
        $cities = $this->branches()->pluck('city')->filter()->unique();

        return $cities->count() === 1 ? (string) $cities->first() : null;
    }

    /**
     * Buildings guests can book: live, with at least one active apartment
     * type, each with its active types loaded.
     *
     * @return Collection<int, Property>
     */
    public function branches(): Collection
    {
        return $this->branches ??= Property::query()
            ->where('is_live', true)
            ->with(['media', 'roomCategories' => fn ($q) => $q->active()->with('media')->orderBy('base_rate')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Property $p) => $p->roomCategories->isNotEmpty())
            ->values();
    }

    /**
     * Free units per apartment type per night, for `$nights` nights from
     * `$from`.
     *
     * @param  Collection<int, RoomCategory>  $types
     * @return array<int, array<string, int>> type id => [Y-m-d => free]
     */
    public function freeByNight(Collection $types, Carbon $from, int $nights): array
    {
        $ids = $types->pluck('id')->all();
        if ($ids === [] || $nights < 1) {
            return [];
        }

        $first = $from->copy()->startOfDay();
        $last = $first->copy()->addDays($nights - 1);

        $units = Room::query()
            ->whereIn('room_category_id', $ids)
            ->where('status', RoomStatus::Active->value)
            ->selectRaw('room_category_id, count(*) as n')
            ->groupBy('room_category_id')
            ->pluck('n', 'room_category_id');

        $taken = [];
        $count = function ($rows) use (&$taken): void {
            foreach ($rows as $row) {
                $day = substr((string) $row->day, 0, 10);
                $taken[(int) $row->cat][$day] = ($taken[(int) $row->cat][$day] ?? 0) + (int) $row->n;
            }
        };
        $count(AvailabilityBlock::query()
            ->join('rooms', 'rooms.id', '=', 'availability_blocks.room_id')
            ->whereIn('rooms.room_category_id', $ids)
            ->whereBetween('availability_blocks.date', [$first->toDateString(), $last->toDateString().' 23:59:59'])
            ->selectRaw('rooms.room_category_id as cat, availability_blocks.date as day, count(*) as n')
            ->groupBy('rooms.room_category_id', 'availability_blocks.date')
            ->toBase()
            ->get());
        $count(AvailabilityBlock::query()
            ->whereNull('room_id')
            ->whereIn('room_category_id', $ids)
            ->whereBetween('date', [$first->toDateString(), $last->toDateString().' 23:59:59'])
            ->selectRaw('room_category_id as cat, date as day, count(*) as n')
            ->groupBy('room_category_id', 'date')
            ->toBase()
            ->get());

        $free = [];
        foreach ($ids as $id) {
            for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
                $day = $d->toDateString();
                $free[$id][$day] = max(0, (int) ($units[$id] ?? 0) - ($taken[$id][$day] ?? 0));
            }
        }

        return $free;
    }

    /**
     * One card per apartment type for a stay: availability, the strip
     * and the price. Types with nothing free for every night of the stay
     * are left out unless `$includeTaken`.
     *
     * @param  Collection<int, Property>  $branches
     * @return Collection<int, Listing>
     */
    public function listings(Collection $branches, Stay $stay, bool $includeTaken = false): Collection
    {
        $types = $branches->flatMap(fn (Property $p) => $p->roomCategories);
        $window = max(self::STRIP_NIGHTS, $stay->nights());
        $free = $this->freeByNight($types, $stay->checkin, $window);

        return $branches
            ->flatMap(fn (Property $branch) => $branch->roomCategories->map(function (RoomCategory $type) use ($branch, $stay, $free) {
                $nights = $free[$type->id] ?? [];
                $forStay = collect($stay->dates())->map(fn (string $day) => $nights[$day] ?? 0)->min() ?? 0;

                return new Listing(
                    type: $type,
                    branch: $branch,
                    freeForStay: (int) $forStay,
                    strip: collect(array_slice($nights, 0, self::STRIP_NIGHTS, true))
                        ->map(fn (int $n) => $n === 0 ? 'gone' : ($n === 1 ? 'last' : 'free'))
                        ->all(),
                    quote: $this->quote($type, $stay),
                );
            }))
            ->filter(fn (Listing $l) => $l->type->capacity_adults >= $stay->adults)
            ->filter(fn (Listing $l) => $includeTaken || $l->freeForStay > 0)
            ->values();
    }

    /** Price for a stay in an apartment type, or null when it can't be priced. */
    public function quote(RoomCategory $type, Stay $stay): ?Quote
    {
        $room = $type->rooms()->where('status', RoomStatus::Active->value)->orderBy('room_number')->first();
        if ($room === null) {
            return null;
        }

        try {
            return Quote::from($this->pricing->calculate($room, $stay->checkin, $stay->checkout));
        } catch (Throwable) {
            return null;
        }
    }

    /** How many apartments of each building are free tonight. */
    public function freeTonight(Property $branch): int
    {
        $free = $this->freeByNight($branch->roomCategories, Carbon::today(), 1);

        return (int) collect($free)->sum(fn (array $nights) => array_sum($nights));
    }
}
