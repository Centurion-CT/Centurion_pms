<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Livewire\Site\Checkout;
use App\Livewire\Site\ManageBooking;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Availability\AvailabilityService;
use App\Site\BookingSite;
use App\Site\Stay;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The booking site on each business's address (docs/MULTITENANCY.md,
 * step 5; design/website/c-map).
 */

beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_platform');

    $this->a = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Suites', 'phone' => '0803 000 1111']);
    $this->b = Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Homes']);

    $this->wuse = siteBranch($this->a, 'Wuse 2', [['Studio', 60_000, 2], ['Two bedroom', 150_000, 1]]);
    $this->jabi = siteBranch($this->a, 'Jabi', [['Deluxe', 70_000, 1]]);
    siteBranch($this->b, 'Lekki', [['Penthouse', 300_000, 1]]);
});

afterEach(fn () => Tenant::forgetCurrent());

/** A live building with apartment types [name, nightly rate, rooms]. */
function siteBranch(Tenant $tenant, string $name, array $types): Property
{
    $tenant->makeCurrent();
    $branch = Property::factory()->live()->for($tenant)->create(['name' => $name, 'city' => 'Abuja', 'latitude' => 9.07, 'longitude' => 7.47]);
    foreach ($types as [$type, $rate, $rooms]) {
        $category = RoomCategory::factory()->for($tenant)->for($branch)->create([
            'name' => $type, 'type' => $type, 'base_rate' => $rate, 'capacity_adults' => 2, 'capacity_children' => 0,
            'status' => RoomStatus::Active, 'deposit_value' => 30,
        ]);
        foreach (range(1, $rooms) as $i) {
            Room::factory()->for($tenant)->for($branch)->for($category, 'category')->create(['status' => RoomStatus::Active, 'base_rate' => $rate]);
        }
    }
    Tenant::forgetCurrent();

    return $branch;
}

function blockNight(Room $room, Carbon $day): void
{
    AvailabilityBlock::query()->create(['tenant_id' => $room->tenant_id, 'room_id' => $room->id, 'date' => $day->toDateString(), 'type' => AvailabilityBlockType::Booked->value]);
}

it('counts free apartments per night the same way as the availability service', function (): void {
    $this->a->makeCurrent();
    $studio = RoomCategory::query()->where('name', 'Studio')->firstOrFail();
    [$r1, $r2] = $studio->rooms()->get()->all();
    $day = Carbon::today()->addDays(2);
    blockNight($r1, $day);
    blockNight($r2, $day);
    blockNight($r1, $day->copy()->addDay());

    $free = app(BookingSite::class)->freeByNight(collect([$studio]), Carbon::today(), 5)[$studio->id];
    $service = app(AvailabilityService::class);

    foreach ($free as $date => $count) {
        $d = Carbon::parse($date);
        expect($count)->toBe($service->categoryFreeUnits($studio, $d, $d->copy()->addDay()), $date);
    }
    expect(array_values($free))->toBe([2, 2, 0, 1, 2]);
});

it('shows each business only its own buildings and apartments', function (): void {
    $this->get('http://alpha.keverna.test/')
        ->assertOk()
        ->assertSee('Alpha Suites')->assertSee('Wuse 2')->assertSee('Jabi')
        ->assertSee('Find a free apartment anywhere in Abuja')
        ->assertDontSee('Lekki')->assertDontSee('Penthouse');

    $this->get('http://alpha.keverna.test/apartments')
        ->assertOk()->assertSee('3 apartments free for your dates')->assertDontSee('Penthouse');
});

it('sends the address to the staff login while no building is live', function (): void {
    Property::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->update(['is_live' => false]);

    $this->get('http://bravo.keverna.test/')->assertRedirect(route('tenant.login'));
});

it('filters by building and leaves out apartments taken for the stay', function (): void {
    $this->a->makeCurrent();
    $deluxe = RoomCategory::query()->where('name', 'Deluxe')->firstOrFail();
    $in = Carbon::today()->addDays(3);
    blockNight($deluxe->rooms()->firstOrFail(), $in);
    Tenant::forgetCurrent();
    $q = ['in' => $in->toDateString(), 'out' => $in->copy()->addDays(2)->toDateString()];

    $this->get('http://alpha.keverna.test/apartments?'.http_build_query($q))
        ->assertSee('2 apartments free for your dates')->assertDontSee('>Deluxe<', false);

    $this->get('http://alpha.keverna.test/apartments?'.http_build_query($q + ['where' => ['wuse-2']]))
        ->assertSee('2 apartments free')->assertSee('Studio');
});

it('opens the detail panel with the price for the stay', function (): void {
    $this->a->makeCurrent();
    $studio = RoomCategory::query()->where('name', 'Studio')->firstOrFail();
    Tenant::forgetCurrent();
    $in = Carbon::today()->addDays(3);

    $this->get('http://alpha.keverna.test/apartments?'.http_build_query(['in' => $in->toDateString(), 'out' => $in->copy()->addDays(2)->toDateString(), 'open' => $studio->id]))
        ->assertOk()
        ->assertSee('₦60,000 × 2 nights')
        ->assertSee('/book/wuse-2/'.$studio->slug, false);
});

it('books the apartment and sends the guest to Paystack, returning to this address', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/abc', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);
    $this->a->makeCurrent();
    onBusinessHost('alpha');
    $studio = RoomCategory::query()->where('name', 'Studio')->firstOrFail();
    $in = Carbon::today()->addDays(4);
    Livewire::withQueryParams(['in' => $in->toDateString(), 'out' => $in->copy()->addDays(3)->toDateString(), 'adults' => 2])
        ->test(Checkout::class, ['branch' => 'wuse-2', 'type' => $studio->slug])
        ->set('firstName', 'Ada')->set('lastName', 'Obi')
        ->set('phone', '0803 123 4567')->set('email', 'ada@example.ng')
        ->set('company', 'Dangote')->set('notes', 'Airport pickup please')
        ->call('book')
        ->assertHasNoErrors()
        ->assertRedirect('https://checkout.paystack.test/abc');

    $booking = Booking::query()->sole();
    expect($booking->guest_name)->toBe('Ada Obi')
        ->and($booking->booking_status)->toBe(BookingStatus::Pending)
        ->and($booking->source_note)->toBe('Booking site')
        ->and($booking->special_requests)->toContain('Company: Dangote')->toContain('Airport pickup please')
        ->and($booking->checkin_date->toDateString())->toBe($in->toDateString());
    Http::assertSent(fn (HttpRequest $r) => $r['callback_url'] === 'http://alpha.keverna.test/stays/pay/callback');
});

it('releases the room when the payment cannot start', function (): void {
    config()->set('services.paystack.secret_key', '');
    $this->a->makeCurrent();
    $deluxe = RoomCategory::query()->where('name', 'Deluxe')->firstOrFail();

    Livewire::test(Checkout::class, ['branch' => 'jabi', 'type' => $deluxe->slug])
        ->set('firstName', 'Ada')->set('lastName', 'Obi')->set('phone', '0803 123 4567')->set('email', 'ada@example.ng')
        ->call('book')
        ->assertHasErrors('booking');

    expect(Booking::query()->sole()->booking_status)->toBe(BookingStatus::Cancelled)
        ->and(AvailabilityBlock::query()->count())->toBe(0);
});

it("does not book another business's apartment", function (): void {
    $this->get('http://alpha.keverna.test/book/lekki/penthouse')->assertNotFound();
});

it('finds a booking by reference and email or phone, and nothing otherwise', function (): void {
    $this->a->makeCurrent();
    $room = Room::query()->firstOrFail();
    $booking = Booking::factory()->for($this->a)->for($room)->create(['guest_email' => 'ada@example.ng', 'guest_phone' => '+234 803 123 4567']);

    Livewire::test(ManageBooking::class)->set('ref', strtolower($booking->booking_ref))->set('contact', '0803 123 4567')
        ->call('find')->assertRedirectContains('/guest/booking/'.$booking->booking_ref.'?token=');

    Livewire::test(ManageBooking::class)->set('ref', $booking->booking_ref)->set('contact', 'someone@else.ng')
        ->call('find')->assertHasErrors('ref')->assertNoRedirect();
});

it('shows an unpaid booking as waiting for payment, not booked', function (): void {
    $this->a->makeCurrent();
    $room = Room::query()->firstOrFail();
    $pending = Booking::factory()->for($this->a)->for($room)->create(['booking_status' => BookingStatus::Pending, 'balance_due' => 0, 'guest_name' => 'Ada Obi']);
    $confirmed = Booking::factory()->for($this->a)->for($room)->create(['booking_status' => BookingStatus::Confirmed, 'balance_due' => 0, 'guest_name' => 'Tobi Ade']);
    Tenant::forgetCurrent();

    $this->get("http://alpha.keverna.test/stays/pay/success/{$pending->booking_ref}")
        ->assertOk()->assertSee("We're waiting for your payment", false)->assertDontSee("You're booked", false);
    $this->get("http://alpha.keverna.test/stays/pay/success/{$confirmed->booking_ref}")
        ->assertOk()->assertSee("You're booked, Tobi", false);
});

it('corrects impossible stays to something bookable', function (): void {
    $stay = Stay::from('2020-01-01', '2019-12-30', 40);

    expect($stay->checkin->isToday())->toBeTrue()
        ->and($stay->nights())->toBe(Stay::DEFAULT_NIGHTS)
        ->and($stay->adults)->toBe(Stay::MAX_ADULTS)
        ->and(Stay::time('13:00'))->toBe('1pm')
        ->and(Stay::time('13:30'))->toBe('1:30pm');
});
