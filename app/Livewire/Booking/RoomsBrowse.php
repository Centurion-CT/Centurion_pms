<?php

declare(strict_types=1);

namespace App\Livewire\Booking;

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Attributes\Locked;
use App\Services\Bookings\RoomAllocator;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Guest-facing "browse rooms" page (`/rooms`), Phase 3 rewrites this
 * to be category-first.
 *
 * Each card represents a RoomCategory and shows:
 *   - category photo (or fallback)
 *   - name + type + capacity
 *   - "X of Y rooms available" for the chosen date range
 *   - base price (or all-in total for picked dates)
 *
 * Filters live in the URL so a filtered result is shareable.
 * Clicking a card opens /categories/{slug} where the guest picks
 * dates + fills out the booking form; the allocator resolves a
 * specific physical room at submit time.
 *
 * Per-room direct links (/rooms/{id}) continue to work via RoomDetail
 * so previous bookmarks don't break.
 */
class RoomsBrowse extends Component
{
    #[Url(as: 'in', except: '')]
    public string $checkin = '';

    #[Url(as: 'out', except: '')]
    public string $checkout = '';

    #[Url(as: 'adults', except: 1)]
    public int $adults = 1;

    #[Url(as: 'children', except: 0)]
    public int $children = 0;

    #[Url(as: 'sort', except: 'price_asc')]
    public string $sort = 'price_asc';

    /**
     * Which branch this browse is scoped to. Populated from the
     * /branches/{property:slug}/rooms route param; null on the
     * legacy /rooms route (falls back to Tenant::properties()->first()
     * so single-property tenants keep working unchanged).
     */
    #[Locked]
    public ?int $propertyId = null;

    public function mount(?Property $property = null): void
    {
        if ($property !== null) {
            $this->propertyId = $property->getKey();
        }
    }

    public function clearFilters(): void
    {
        $this->checkin = '';
        $this->checkout = '';
        $this->adults = 1;
        $this->children = 0;
        $this->sort = 'price_asc';
    }

    public function refresh(): void
    {
        // wire:poll handles re-render.
    }

    public function render(RoomAllocator $allocator, PricingCalculator $pricing): View
    {
        $tenant = Tenant::current();
        // Branch-scoped route: use the resolved property from the URL.
        // Legacy /rooms: fall back to the tenant's first property so
        // single-branch orgs keep working exactly as before.
        $property = $this->propertyId !== null
            ? Property::query()->find($this->propertyId)
            : $tenant?->property;
        $branches = $tenant !== null ? $tenant->properties()->orderBy('id')->get() : collect();

        $categories = collect();
        $unavailableCount = 0;
        $tooSmallCount = 0;
        $totalCategoryCount = 0;

        if ($property !== null) {
            $query = RoomCategory::query()
                ->active()
                ->where('property_id', $property->id);

            match ($this->sort) {
                'price_desc'    => $query->orderByDesc('base_rate'),
                'capacity_desc' => $query->orderByDesc('capacity_adults')->orderByDesc('capacity_children'),
                default         => $query->orderBy('base_rate'),
            };

            $allCategories = $query->get();
            $totalCategoryCount = $allCategories->count();

            [$checkin, $checkout, $datesValid] = $this->parseDateRange();

            foreach ($allCategories as $category) {
                // Capacity filter, the category's defaults apply to every
                // unit in it.
                if ($this->adults > $category->capacity_adults || $this->children > $category->capacity_children) {
                    $tooSmallCount++;

                    continue;
                }

                $available = null;
                $totalActive = $allocator->activeCount($category);
                $preview = null;

                if ($datesValid) {
                    $available = $allocator->countAvailable($category, $checkin, $checkout);
                    if ($available === 0) {
                        $unavailableCount++;

                        continue;
                    }
                    // Price preview using the category's first active
                    // room, base_rate is identical across the units
                    // so the totals match whichever the allocator
                    // ultimately picks.
                    $representative = $category->rooms()
                        ->where('status', RoomStatus::Active->value)
                        ->orderBy('room_number')
                        ->first();
                    if ($representative !== null) {
                        try {
                            $preview = $pricing->calculate($representative, $checkin, $checkout);
                        } catch (Throwable) {
                            $preview = null;
                        }
                    }
                }

                $categories->push((object) [
                    'model' => $category,
                    'hero' => $category->heroImage(),
                    'available' => $available,
                    'totalActive' => $totalActive,
                    'preview' => $preview,
                ]);
            }
        }

        return view('livewire.booking.rooms-browse', [
            'tenant' => $tenant,
            'property' => $property,
            'branches' => $branches,
            'categories' => $categories,
            'datesValid' => $this->parseDateRange()[2],
            'guestCount' => max(1, $this->adults) + max(0, $this->children),
            'totalCategoryCount' => $totalCategoryCount,
            'unavailableCount' => $unavailableCount,
            'tooSmallCount' => $tooSmallCount,
        ])->layout('layouts.tenant-public');
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon|null, 1: \Illuminate\Support\Carbon|null, 2: bool}
     */
    private function parseDateRange(): array
    {
        if ($this->checkin === '' || $this->checkout === '') {
            return [null, null, false];
        }
        try {
            $in = Carbon::parse($this->checkin)->startOfDay();
            $out = Carbon::parse($this->checkout)->startOfDay();
        } catch (Throwable) {
            return [null, null, false];
        }
        if ($out->lessThanOrEqualTo($in)) {
            return [$in, $out, false];
        }
        if ($in->lessThan(Carbon::today())) {
            return [$in, $out, false];
        }

        return [$in, $out, true];
    }
}
