<?php

declare(strict_types=1);

namespace App\Livewire\Booking;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\InitiateBookingPayment;
use App\Enums\BookingSource;
use App\Enums\DepositType;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Concerns\HasCorporateContext;
use App\Livewire\Concerns\HasDiscountLookup;
use App\Models\Package;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Services\Pricing\PricingBreakdown;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Public-facing booking page for a RoomCategory (Phase 3).
 *
 * Mirrors {@see RoomDetail} structurally, same form, same pricing
 * preview, same live-availability polling, but operates on a class
 * of rooms instead of a specific unit. On submit, the RoomAllocator
 * resolves a concrete physical room (typically the first free unit
 * ordered by room_number), then CreateBooking runs as usual against
 * that room.
 *
 * Direct links to /rooms/{id} are unchanged, RoomDetail still
 * serves those for bookmarks + operator-shared URLs.
 */
class CategoryDetail extends Component
{
    use HasCorporateContext;
    use HasDiscountLookup;

    #[Locked]
    public int $categoryId;

    #[Validate('required|date|after_or_equal:today')]
    public string $checkin = '';

    #[Validate('required|date|after:checkin')]
    public string $checkout = '';

    #[Validate('required|integer|min:1')]
    public int $adults = 1;

    #[Validate('required|integer|min:0')]
    public int $children = 0;

    #[Validate('required|string|max:120')]
    public string $guest_name = '';

    #[Validate('required|email|max:180')]
    public string $guest_email = '';

    #[Validate('required|string|max:32|regex:/^\+?[0-9\s\-()]{8,}$/')]
    public string $guest_phone = '';

    #[Validate('nullable|string|max:1000')]
    public string $special_requests = '';

    #[Validate('required|in:full,deposit')]
    public string $payment_choice = 'full';

    /**
     * Guest opted for the "with complimentary breakfast" tier. Only
     * meaningful when the category has breakfast_included_rate set;
     * ignored otherwise (falls back to base_rate in the calculator).
     */
    #[Validate('boolean')]
    public bool $breakfast_included = false;

    /**
     * Keyed by package_id, value is the quantity the guest picked.
     *
     * @var array<int, int>
     */
    public array $selectedPackages = [];

    public function mount(RoomCategory $category): void
    {
        if ($category->status !== RoomStatus::Active) {
            throw new NotFoundHttpException;
        }

        $this->categoryId = $category->id;
        $this->checkin = Carbon::tomorrow()->toDateString();
        $this->checkout = Carbon::tomorrow()->addDays(2)->toDateString();
    }

    public function refresh(): void
    {
        // wire:poll handles re-render; no work needed.
    }

    public function book(CreateBooking $create, InitiateBookingPayment $initPayment, RoomAllocator $allocator): void
    {
        $this->validate();

        $category = $this->category();
        $checkin = Carbon::parse($this->checkin);
        $checkout = Carbon::parse($this->checkout);

        // Pick a specific room from the category. If none free, surface
        // a friendly error and bail before hitting CreateBooking.
        $room = $allocator->allocate($category, $checkin, $checkout);
        if ($room === null) {
            throw ValidationException::withMessages([
                'checkin' => __('No rooms of this type are available for those dates. Please try a different range.'),
            ]);
        }

        $discountArgs = $this->discountSubmissionArgs();
        $packageSelections = $this->normaliseSelectedPackages();

        $req = new CreateBookingRequest(
            guestName: $this->guest_name,
            guestEmail: $this->guest_email,
            guestPhone: $this->guest_phone,
            adults: $this->adults,
            children: $this->children,
            checkin: $checkin,
            checkout: $checkout,
            paymentType: $this->payment_choice === 'deposit' ? PaymentType::Deposit : PaymentType::Full,
            specialRequests: $this->special_requests !== '' ? $this->special_requests : null,
            source: BookingSource::Online,
            discountCode: $discountArgs['discountCode'],
            autoApplyDiscounts: $discountArgs['autoApplyDiscounts'],
            selectedPackages: $packageSelections,
            breakfastIncluded: $this->breakfast_included,
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (BookingValidationException $e) {
            throw ValidationException::withMessages(['checkin' => $e->getMessage()]);
        } catch (RangeNotAvailableException) {
            // The room the allocator picked got snapped up between the
            // availability check and the commit. Re-allocate once and
            // try again, covers the common single-race case without
            // burning into a retry loop.
            $room = $allocator->allocate($category, $checkin, $checkout);
            if ($room === null) {
                throw ValidationException::withMessages([
                    'checkin' => __('Those dates were just taken. Please pick another range.'),
                ]);
            }
            try {
                $booking = $create->handle($room, $req);
            } catch (RangeNotAvailableException) {
                throw ValidationException::withMessages([
                    'checkin' => __('Those dates were just taken. Please pick another range.'),
                ]);
            }
        }

        $this->redirect($initPayment->handle($booking));
    }

    protected function discountContext(): ?array
    {
        $category = $this->category();
        $allocator = app(RoomAllocator::class);

        // For the discount engine we use the first active room in the
        // category as a representative. Pricing per category (base_rate
        // lives on the category itself) doesn't change between units,
        // so the engine's per-room targeting check still works because
        // we evaluate against an actual Room instance.
        $rooms = $category->rooms()->where('status', RoomStatus::Active->value)->orderBy('room_number')->limit(1)->get();
        $representativeRoom = $rooms->first();
        if ($representativeRoom === null) {
            return null;
        }

        if ($this->checkin === '' || $this->checkout === '') {
            return null;
        }
        try {
            $checkin = Carbon::parse($this->checkin);
            $checkout = Carbon::parse($this->checkout);
        } catch (Throwable) {
            return null;
        }
        if ($checkout->lessThanOrEqualTo($checkin)) {
            return null;
        }

        try {
            $preview = app(PricingCalculator::class)->calculate(
                $representativeRoom, $checkin, $checkout, 0, $this->breakfast_included,
            );
        } catch (Throwable) {
            return null;
        }

        return [
            'room' => $representativeRoom,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'channel' => BookingSource::Online,
            'netSubtotal' => $preview->netSubtotal,
        ];
    }

    public function render(PricingCalculator $pricing, RoomAllocator $allocator): View
    {
        $category = $this->category();
        $property = $category->property;

        // Build a price preview against a representative room, the
        // category's base_rate flows through to the same numbers
        // regardless of which unit we pick.
        $representativeRoom = $category->rooms()
            ->where('status', RoomStatus::Active->value)
            ->orderBy('room_number')
            ->first();
        $packages = Package::query()
            ->where('tenant_id', $category->tenant_id)
            ->active()
            ->forBookingFlow()
            ->orderBy('id')
            ->get();
        $packagesTotal = $this->computePackagesTotal($packages);
        $preview = $this->buildPreview($pricing, $representativeRoom, $packagesTotal);

        // Availability badge, "X of Y rooms available for these dates".
        $availableCount = 0;
        $totalActive = $allocator->activeCount($category);
        if ($this->checkin !== '' && $this->checkout !== ''
            && $representativeRoom !== null
        ) {
            try {
                $availableCount = $allocator->countAvailable(
                    $category,
                    Carbon::parse($this->checkin),
                    Carbon::parse($this->checkout),
                );
            } catch (Throwable) {
                $availableCount = 0;
            }
        }
        $isFree = ($this->checkin !== '' && $this->checkout !== '') ? $availableCount > 0 : null;

        // Sibling categories for the carousel at the foot of the page.
        $relatedCategories = RoomCategory::query()
            ->active()
            ->where('property_id', $category->property_id)
            ->where('id', '!=', $category->id)
            ->orderBy('id')
            ->limit(6)
            ->get();

        // Reviews, Phase 1/2 had per-room reviews. For categories we
        // aggregate any review attached to a room in this category +
        // property-wide reviews. Keeps the existing review model intact.
        $roomIds = $category->rooms()->pluck('id')->all();
        $categoryRating = $this->aggregateRating($roomIds);
        $reviews = Review::query()
            ->whereIn('room_id', $roomIds)
            ->displayed()
            ->orderBy('order_column')
            ->get();
        $propertyRating = $property->ratingSummary();

        $this->refreshDiscount();

        $branches = $property?->tenant?->properties()->orderBy('id')->get() ?? collect();

        return view('livewire.booking.category-detail', [
            'category' => $category,
            'property' => $property,
            'branches' => $branches,
            'preview' => $preview,
            'isFree' => $isFree,
            'availableCount' => $availableCount,
            'totalActive' => $totalActive,
            'allowDeposit' => $category->deposit_type !== DepositType::None,
            'relatedCategories' => $relatedCategories,
            'reviews' => $reviews,
            'rating' => $categoryRating,
            'propertyRating' => $propertyRating,
            'packages' => $packages,
        ])->layout('layouts.tenant-public');
    }

    private function buildPreview(PricingCalculator $pricing, ?Room $representativeRoom, int $packagesTotal = 0): ?PricingBreakdown
    {
        if ($representativeRoom === null || $this->checkin === '' || $this->checkout === '') {
            return null;
        }
        try {
            $checkin = Carbon::parse($this->checkin);
            $checkout = Carbon::parse($this->checkout);
        } catch (Throwable) {
            return null;
        }
        if ($checkout->lessThanOrEqualTo($checkin)) {
            return null;
        }

        return $pricing->calculate(
            $representativeRoom, $checkin, $checkout, $packagesTotal, $this->breakfast_included,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Package>  $packages
     */
    private function computePackagesTotal($packages): int
    {
        $total = 0;
        foreach ($packages as $package) {
            $qty = (int) ($this->selectedPackages[$package->id] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $qty = min($qty, $package->max_per_booking);
            $total += $package->effectivePrice() * $qty;
        }

        return $total;
    }

    /**
     * @return list<array{package_id: int, quantity: int}>
     */
    private function normaliseSelectedPackages(): array
    {
        $out = [];
        foreach ($this->selectedPackages as $packageId => $qty) {
            $id = (int) $packageId;
            $qty = (int) $qty;
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $out[] = ['package_id' => $id, 'quantity' => $qty];
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $roomIds
     * @return array{average: float, count: int}
     */
    private function aggregateRating(array $roomIds): array
    {
        if ($roomIds === []) {
            return ['average' => 0.0, 'count' => 0];
        }
        $q = Review::query()->whereIn('room_id', $roomIds)->displayed();
        $count = (int) $q->count();
        $avg = $count > 0 ? round((float) $q->avg('rating'), 1) : 0.0;

        return ['average' => $avg, 'count' => $count];
    }

    private function category(): RoomCategory
    {
        return RoomCategory::query()->active()->findOrFail($this->categoryId);
    }

    /**
     * Contract for {@see HasCorporateContext}, uses the same
     * "representative room" pattern as the discount engine. The
     * category's base_rate flows identically through every active
     * room, so any one of them gives the right corporate-rate preview.
     */
    protected function roomForCorporateContext(): ?Room
    {
        return $this->category()->rooms()
            ->where('status', RoomStatus::Active->value)
            ->with('property')
            ->orderBy('room_number')
            ->first();
    }
}
