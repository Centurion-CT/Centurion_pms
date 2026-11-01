<?php

declare(strict_types=1);

namespace App\Livewire\Site;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\InitiateBookingPayment;
use App\Enums\BookingSource;
use App\Enums\PaymentType;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Site\BookingSite;
use App\Site\Stay;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Booking an apartment type on the booking site: who's staying and how
 * they pay, then Paystack (docs/MULTITENANCY.md, step 5; design:
 * design/website/c-map/checkout.html).
 *
 * Books through the same path as the category page: RoomAllocator picks
 * a free room of the type, CreateBooking holds it, InitiateBookingPayment
 * sends the guest to the business's Paystack account, and the guest
 * comes back to /stays/pay/callback on this address.
 */
#[Layout('site.layouts.app')]
class Checkout extends Component
{
    public const ARRIVALS = ['1pm – 3pm', '3pm – 6pm', '6pm – 10pm', 'After 10pm', 'Not sure yet'];

    #[Locked]
    public int $typeId;

    #[Locked]
    public string $checkin;

    #[Locked]
    public string $checkout;

    #[Locked]
    public int $adults;

    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    public string $email = '';

    public string $arrival = self::ARRIVALS[0];

    public string $company = '';

    public string $notes = '';

    public string $pay = 'full';

    public function mount(string $branch, string $type): void
    {
        $property = Property::query()->where('slug', $branch)->where('is_live', true)->firstOrFail();
        $this->typeId = $property->roomCategories()->active()->where('slug', $type)->firstOrFail()->id;

        $stay = Stay::fromRequest(request());
        $this->checkin = $stay->checkin->toDateString();
        $this->checkout = $stay->checkout->toDateString();
        $this->adults = $stay->adults;
    }

    public function book(RoomAllocator $allocator, CreateBooking $create, InitiateBookingPayment $payment, BookingSite $site): void
    {
        $this->validate([
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9 +()-]{7,}$/'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'arrival' => ['required', 'in:'.implode(',', self::ARRIVALS)],
            'company' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'pay' => ['required', 'in:full,deposit'],
        ], [], ['firstName' => 'first name', 'lastName' => 'last name', 'phone' => 'phone number']);

        $type = $this->type();
        $stay = $this->stay();
        $quote = $site->quote($type, $stay);
        $deposit = $this->pay === 'deposit' && $quote !== null && $quote->deposit > 0 && $quote->deposit < $quote->total;

        $request = new CreateBookingRequest(
            guestName: trim($this->firstName).' '.trim($this->lastName),
            guestEmail: trim($this->email),
            guestPhone: trim($this->phone),
            adults: $stay->adults,
            children: 0,
            checkin: $stay->checkin,
            checkout: $stay->checkout,
            paymentType: $deposit ? PaymentType::Deposit : PaymentType::Full,
            specialRequests: $this->requests(),
            source: BookingSource::Online,
            sourceNote: 'Booking site',
        );

        // One retry covers the common race: the room the allocator picked
        // is taken between the check and the commit.
        $booking = null;
        foreach ([1, 2] as $_) {
            $room = $allocator->allocate($type, $stay->checkin, $stay->checkout);
            if ($room === null) {
                break;
            }
            try {
                $booking = $create->handle($room, $request);
                break;
            } catch (RangeNotAvailableException) {
                continue;
            } catch (BookingValidationException $e) {
                throw ValidationException::withMessages(['booking' => $e->getMessage()]);
            }
        }
        if ($booking === null) {
            throw ValidationException::withMessages(['booking' => 'This apartment was just booked for these dates. Go back and pick another, or try other dates.']);
        }

        try {
            $this->redirect($payment->handle($booking));
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['booking' => "We couldn't reach the payment provider. Please try again in a moment."]);
        }
    }

    public function render(BookingSite $site): View
    {
        $type = $this->type();
        $stay = $this->stay();
        $free = $site->freeByNight(collect([$type]), $stay->checkin, $stay->nights())[$type->id] ?? [];

        return view('livewire.site.checkout', [
            'type' => $type,
            'branch' => $type->property,
            'stay' => $stay,
            'quote' => $site->quote($type, $stay),
            'available' => $free !== [] && min($free) > 0,
            'rules' => $type->property->houseRules(),
            'photo' => ($media = $type->getFirstMedia('gallery') ?? $type->property->getFirstMedia('hero')) ? $media->getUrl() : null,
        ])->title('Book '.$type->name.' · '.$site->business()->name)->layoutData(['pageClass' => 'pg-checkout']);
    }

    private function type(): RoomCategory
    {
        return RoomCategory::query()->with('property')->findOrFail($this->typeId);
    }

    private function stay(): Stay
    {
        return Stay::from($this->checkin, $this->checkout, $this->adults);
    }

    private function requests(): ?string
    {
        $lines = array_filter([
            'Arriving around '.$this->arrival,
            trim($this->company) !== '' ? 'Company: '.trim($this->company) : null,
            trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        return implode("\n", $lines);
    }
}
