<?php

declare(strict_types=1);

use App\Actions\GuestPortal\InitiatePortalPayment;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\GuestPortalIntent;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Reserve-another-room tile — guest picks category + dates, pays
 * via Paystack, callback resolves to a Confirmed booking.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.callback_url', 'http://gp-reserve.keverna.test/room/portal/pay/callback');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'gp-reserve',
        'paystack_secret_key' => 'sk_test',
    ]);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->currentCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Std', 'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0, 'min_nights' => 1,
    ]);
    $this->currentRoom = Room::factory()->for($this->tenant)->for($this->property)->for($this->currentCat, 'category')->create();

    // A DIFFERENT category the guest wants to book from the portal.
    $this->targetCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 90_000, 'capacity_adults' => 4, 'capacity_children' => 2, 'min_nights' => 1,
    ]);
    $this->targetRoom = Room::factory()->for($this->tenant)->for($this->property)->for($this->targetCat, 'category')->create();

    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    // Current guest — checked into a Std room, wants to add a Suite
    // for the weekend on top.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->currentRoom)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Repeat Guest',
        'guest_phone' => '+2348011111111',
        'guest_email' => 'repeat@example.com',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDays(3),
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('reservePreviewAmount computes nights × category base_rate', function (): void {
    session()->put("guest_portal.verified.{$this->currentRoom->id}", $this->booking->id);

    $portal = new RoomPortal;
    $portal->mount($this->currentRoom->qr_slug);
    $portal->reserveCategoryId = $this->targetCat->id;
    $portal->reserveCheckin = Carbon::today()->addDays(5)->toDateString();
    $portal->reserveCheckout = Carbon::today()->addDays(8)->toDateString();

    // 3 nights × ₦90k = ₦270k
    expect($portal->reservePreviewAmount())->toBe(270_000);
});

it('submit initiates Paystack redirect + writes a reserve intent', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'access_code' => 'a', 'reference' => 'r'],
    ])]);

    session()->put("guest_portal.verified.{$this->currentRoom->id}", $this->booking->id);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->currentRoom->qr_slug])
        ->call('openTile', 'reserve')
        ->set('reserveCategoryId', $this->targetCat->id)
        ->set('reserveCheckin', Carbon::today()->addDays(5)->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(8)->toDateString())
        ->set('reserveAdults', 2)
        ->set('reserveChildren', 1)
        ->call('submitReservePayByCard')
        ->assertRedirect('https://checkout.paystack.test/x');

    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    expect($intent->purpose)->toBe(GuestPortalIntent::PURPOSE_RESERVE);
    expect((int) $intent->payload['category_id'])->toBe($this->targetCat->id);
    expect((int) $intent->payload['adults'])->toBe(2);
    expect((int) $intent->payload['children'])->toBe(1);
    // 3 nights × ₦90k = ₦270k
    expect((int) $intent->payment->amount)->toBe(270_000);
});

it('callback creates a Confirmed booking for the target category', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'x', 'access_code' => 'a', 'reference' => 'r'],
    ])]);
    $result = app(InitiatePortalPayment::class)->handle(
        booking: $this->booking,
        amountNgn: 90_000,
        purpose: GuestPortalIntent::PURPOSE_RESERVE,
        payload: [
            'category_id' => $this->targetCat->id,
            'checkin' => Carbon::today()->addDays(5)->toDateString(),
            'checkout' => Carbon::today()->addDays(6)->toDateString(),
            'adults' => 1, 'children' => 0,
            'guest_name' => $this->booking->guest_name,
            'guest_email' => $this->booking->guest_email,
            'guest_phone' => $this->booking->guest_phone,
        ],
    );

    Http::fake(['https://api.paystack.test/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 9_000_000, 'reference' => $result['reference'], 'paid_at' => Carbon::now()->toIso8601String()],
    ])]);

    $this->get('http://gp-reserve.keverna.test/room/portal/pay/callback?reference='.$result['reference']);

    // A new booking exists for the target room (allocator picks the one target room in this category).
    $new = Booking::query()->where('room_id', $this->targetRoom->id)->firstOrFail();
    expect($new->booking_status)->toBe(BookingStatus::Confirmed);
    expect($new->guest_name)->toBe($this->booking->guest_name);

    // Payment is re-pointed at the new booking.
    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    expect((int) $intent->payment->fresh()->booking_id)->toBe($new->id);
});
