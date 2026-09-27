<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Booking\RoomDetail;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'guest-book',
        'paystack_secret_key' => 'sk_op_tenant_key',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    // Category holds the shared pricing/capacity fields; the room row is lean.
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Maitama Suite',
        'base_rate' => 30_000,
        'capacity_adults' => 2,
        'capacity_children' => 1,
        'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ---------- Page render + visibility ---------- */

it('renders the room detail page for an Active room', function (): void {
    $response = $this->get('http://guest-book.keverna.test/rooms/'.$this->room->id);

    $response->assertOk();
    $response->assertSeeLivewire(RoomDetail::class);
    $response->assertSee('Maitama Suite');
});

it('404s on an inactive room', function (): void {
    $this->room->forceFill(['status' => RoomStatus::Inactive])->save();

    $response = $this->get('http://guest-book.keverna.test/rooms/'.$this->room->id);

    $response->assertNotFound();
});

it('404s on a foreign tenant\'s room id', function (): void {
    $other = Tenant::factory()->create(['slug' => 'guest-book-other']);
    $otherProp = Property::factory()->for($other)->create();
    Tenant::forgetCurrent();
    $other->makeCurrent();
    $foreignRoom = Room::factory()->for($other)->for($otherProp)->create();
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    $response = $this->get('http://guest-book.keverna.test/rooms/'.$foreignRoom->id);

    $response->assertNotFound();
});

it('shows a live price preview that updates with dates', function (): void {
    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', '2030-08-01')
        ->set('checkout', '2030-08-04')
        ->assertSee('₦90,000');   // 3 × 30,000
});

it('warns when the picked range is not available', function (): void {
    AvailabilityBlock::factory()
        ->for($this->tenant)->for($this->room)
        ->create(['date' => '2030-08-02', 'type' => AvailabilityBlockType::Blocked]);

    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', '2030-08-01')
        ->set('checkout', '2030-08-04')
        ->assertSeeHtml('data-testid="not-available"');
});

/* ---------- Validation ---------- */

it('rejects past checkin dates', function (): void {
    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', Carbon::yesterday()->toDateString())
        ->set('checkout', Carbon::tomorrow()->toDateString())
        ->set('guest_name', 'A')
        ->set('guest_email', 'a@x.test')
        ->set('guest_phone', '+2348012345678')
        ->call('book')
        ->assertHasErrors(['checkin']);
});

it('rejects checkout not after checkin', function (): void {
    $d = Carbon::tomorrow()->toDateString();
    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', $d)
        ->set('checkout', $d)
        ->call('book')
        ->assertHasErrors(['checkout']);
});

it('rejects an invalid phone format', function (): void {
    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(2)->toDateString())
        ->set('guest_name', 'A')
        ->set('guest_email', 'a@x.test')
        ->set('guest_phone', 'abc')
        ->call('book')
        ->assertHasErrors(['guest_phone']);
});

it('rejects adults > capacity', function (): void {
    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(2)->toDateString())
        ->set('adults', 999)
        ->set('guest_name', 'A')
        ->set('guest_email', 'a@x.test')
        ->set('guest_phone', '+2348012345678')
        ->call('book')
        ->assertHasErrors();
});

/* ---------- Happy path → Paystack ---------- */

it('creates a Pending booking + a Pending payment and redirects to Paystack', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/HAPPY',
                'access_code' => 'ac_happy', 'reference' => 'kev_book_happy',
            ],
        ]),
    ]);

    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', '2030-08-10')
        ->set('checkout', '2030-08-13')
        ->set('adults', 2)
        ->set('guest_name', 'Amara Okafor')
        ->set('guest_email', 'amara@x.test')
        ->set('guest_phone', '+2348012345678')
        ->set('payment_choice', 'full')
        ->call('book')
        ->assertRedirect('https://checkout.paystack.test/HAPPY');

    $b = Booking::query()->where('guest_email', 'amara@x.test')->first();
    expect($b)->not->toBeNull();
    expect($b->booking_status)->toBe(BookingStatus::Pending);
    expect($b->room_total)->toBe(90_000);

    $p = Payment::query()->where('booking_id', $b->id)->first();
    expect($p)->not->toBeNull();
    expect($p->status)->toBe(PaymentStatus::Pending);
});

it('rejects an overlapping booking via a guest-friendly validation error', function (): void {
    // Pre-existing booking holds 8/10..8/12 (nights 10,11,12).
    AvailabilityBlock::factory()
        ->for($this->tenant)->for($this->room)->booked()
        ->createMany([
            ['date' => '2030-08-10'], ['date' => '2030-08-11'], ['date' => '2030-08-12'],
        ]);

    Livewire::test(RoomDetail::class, ['room' => $this->room])
        ->set('checkin', '2030-08-11')
        ->set('checkout', '2030-08-14')
        ->set('guest_name', 'Race Loser')
        ->set('guest_email', 'race@x.test')
        ->set('guest_phone', '+2348012345678')
        ->call('book')
        ->assertHasErrors(['checkin']);

    expect(Booking::query()->where('guest_email', 'race@x.test')->exists())->toBeFalse();
});
