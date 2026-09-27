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
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
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
 * Guest-facing room detail + booking form (PRD §6.4.2). Hosts the price
 * preview (re-runs PricingCalculator on every date change) and on submit
 * dispatches CreateBooking + InitiateBookingPayment, redirecting to Paystack
 * checkout.
 *
 * Inactive rooms and rooms belonging to another tenant 404, route-model
 * binding flows through Room's BelongsToTenant scope + an explicit Active
 * check.
 */
class RoomDetail extends Component
{
    use HasCorporateContext;
    use HasDiscountLookup;

    #[Locked]
    public int $roomId;

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
     * Keyed by package_id, value is the quantity the guest picked.
     * Zero / missing entries are treated as "not selected".
     *
     * @var array<int, int>
     */
    public array $selectedPackages = [];

    public function mount(Room $room): void
    {
        if ($room->status !== RoomStatus::Active) {
            throw new NotFoundHttpException;
        }

        $this->roomId = $room->id;
        $this->checkin = Carbon::tomorrow()->toDateString();
        $this->checkout = Carbon::tomorrow()->addDays(2)->toDateString();
        $this->adults = 1;
    }

    /**
     * No-op bound to wire:poll on the view. Livewire re-runs render()
     * which re-checks availability against the AvailabilityBlock rows,
     * so if a competing booking lands while the guest is filling out
     * the form they'll see the "not available" warning within the
     * next poll tick, without needing to refresh.
     */
    public function refresh(): void
    {
        // wire:poll triggers a render; no work to do here.
    }

    public function book(CreateBooking $create, InitiateBookingPayment $initPayment): void
    {
        $this->validate();

        $room = $this->room();
        $discountArgs = $this->discountSubmissionArgs();
        $packageSelections = $this->normaliseSelectedPackages();

        $req = new CreateBookingRequest(
            guestName: $this->guest_name,
            guestEmail: $this->guest_email,
            guestPhone: $this->guest_phone,
            adults: $this->adults,
            children: $this->children,
            checkin: Carbon::parse($this->checkin),
            checkout: Carbon::parse($this->checkout),
            paymentType: $this->payment_choice === 'deposit' ? PaymentType::Deposit : PaymentType::Full,
            specialRequests: $this->special_requests !== '' ? $this->special_requests : null,
            source: BookingSource::Online,
            discountCode: $discountArgs['discountCode'],
            autoApplyDiscounts: $discountArgs['autoApplyDiscounts'],
            selectedPackages: $packageSelections,
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (BookingValidationException $e) {
            throw ValidationException::withMessages(['checkin' => $e->getMessage()]);
        } catch (RangeNotAvailableException) {
            throw ValidationException::withMessages([
                'checkin' => __('Those dates are no longer available. Please pick another range.'),
            ]);
        }

        $checkoutUrl = $initPayment->handle($booking);

        $this->redirect($checkoutUrl);
    }

    protected function discountContext(): ?array
    {
        try {
            $room = $this->room();
        } catch (Throwable) {
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

        $pricing = app(PricingCalculator::class);
        try {
            $preview = $pricing->calculate($room, $checkin, $checkout);
        } catch (Throwable) {
            return null;
        }

        return [
            'room' => $room,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'channel' => BookingSource::Online,
            'netSubtotal' => $preview->netSubtotal,
        ];
    }

    public function render(AvailabilityService $availability, PricingCalculator $pricing): View
    {
        $room = $this->room();
        $packages = Package::query()
            ->where('tenant_id', $room->tenant_id)
            ->active()
            ->forBookingFlow()
            ->orderBy('id')
            ->get();
        $packagesTotal = $this->computePackagesTotal($packages);
        $preview = $this->buildPreview($pricing, $room, $packagesTotal);
        $isFree = $this->isRangeFree($availability, $room);

        // Keep the displayed discount honest about current dates/room -
        // re-validates any manual code and re-scans auto-apply rules.
        $this->refreshDiscount();

        // Sibling rooms, drive the "Other rooms at this property" carousel
        // (PRD §10 OTA-style enrichment). Hide the current room; cap at 6.
        $relatedRooms = Room::query()
            ->active()
            ->where('property_id', $room->property_id)
            ->where('id', '!=', $room->id)
            ->orderBy('id')
            ->limit(6)
            ->get();

        $property = $room->property;
        $branches = $property?->tenant?->properties()->orderBy('id')->get() ?? collect();

        // Per-room rating + reviews. The room detail page shows ONLY reviews
        // attached to this room; the room cards / hero badge on the landing
        // page use the property aggregate.
        $roomRating = $room->ratingSummary();
        $roomReviews = Review::query()
            ->where('room_id', $room->id)
            ->displayed()
            ->orderBy('order_column')
            ->get();
        $propertyRating = $property->ratingSummary();

        return view('livewire.booking.room-detail', [
            'room' => $room,
            'property' => $property,
            'branches' => $branches,
            'preview' => $preview,
            'isFree' => $isFree,
            'allowDeposit' => $room->deposit_type !== DepositType::None,
            'relatedRooms' => $relatedRooms,
            'reviews' => $roomReviews,
            'rating' => $roomRating,
            'propertyRating' => $propertyRating,
            'packages' => $packages,
        ])->layout('layouts.tenant-public');
    }

    private function buildPreview(PricingCalculator $pricing, Room $room, int $packagesTotal = 0): ?PricingBreakdown
    {
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

        return $pricing->calculate($room, $checkin, $checkout, $packagesTotal);
    }

    /**
     * Sum the effective price of every currently-selected package,
     * clamped to each package's max_per_booking. Complimentary
     * packages contribute zero by virtue of effectivePrice().
     *
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
     * Convert the keyed selection map into the list shape
     * CreateBookingRequest expects.
     *
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

    private function isRangeFree(AvailabilityService $availability, Room $room): ?bool
    {
        if ($this->checkin === '' || $this->checkout === '') {
            return null;
        }
        try {
            $checkin = Carbon::parse($this->checkin);
            $checkout = Carbon::parse($this->checkout);
        } catch (Throwable) {
            return null;
        }

        return $availability->isAvailable($room, $checkin, $checkout);
    }

    private function room(): Room
    {
        return Room::query()->active()->findOrFail($this->roomId);
    }

    /**
     * Contract for {@see HasCorporateContext}.
     */
    protected function roomForCorporateContext(): ?Room
    {
        return $this->room()->loadMissing('property');
    }
}
