<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Marketplace\CreateStaysBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Multi-room cart on the marketplace checkout. Guest can stack
 * several rooms from the same property + pay for all of them in
 * one Paystack transaction. Each room persists as its own Booking
 * row (per-room checkin/checkout tracking); a shared stays_cart_ref
 * links them + drives sibling confirmation on payment success.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'cart-test',
        'paystack_subaccount_code' => 'ACCT_cart',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Cart Test Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders a cart list when the guest picks two rooms', function (): void {
    Livewire::withQueryParams(['rooms' => $this->roomA->id.','.$this->roomB->id])
        ->test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSee('data-testid="checkout-cart-list"', false)
        ->assertSee('data-testid="checkout-cart-item-'.$this->roomA->id.'"', false)
        ->assertSee('data-testid="checkout-cart-item-'.$this->roomB->id.'"', false)
        ->assertSee('Your rooms')
        ->assertSee('2 rooms');
});

it('breakdown sums subtotals across every room in the cart', function (): void {
    // 2 rooms × ₦40k × 2 nights = ₦160k subtotal.
    $c = Livewire::withQueryParams(['rooms' => $this->roomA->id.','.$this->roomB->id])
        ->test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(5)->toDateString());

    $bd = $c->instance()->breakdown();
    expect($bd['subtotal'])->toBe(160_000);
    // Fees computed ONCE on the aggregate: 2.5% × 160k + 1.5% × 160k
    // (capped at ₦2,000).
    expect($bd['platform_fee'])->toBe(4_000);
    expect($bd['processing_fee'])->toBe(2_000);
});

it('removeFromCart drops a room + falls back to single-room path when only one remains', function (): void {
    $c = Livewire::withQueryParams(['rooms' => $this->roomA->id.','.$this->roomB->id])
        ->test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->call('removeFromCart', $this->roomA->id);

    // Cart string is blanked when only one room remains.
    expect($c->get('rooms'))->toBe('');
    expect($c->get('room'))->toBe($this->roomB->id);
});

it('addToCart appends a room to the cart string', function (): void {
    Livewire::withQueryParams(['room' => (string) $this->roomA->id])
        ->test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->call('addToCart', $this->roomB->id)
        ->tap(fn ($c) => expect($c->get('rooms'))
            ->toBe($this->roomA->id.','.$this->roomB->id));
});

it('renders an "Add another room" link back to the property page', function (): void {
    $r = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSee('data-testid="checkout-cart-add-another"', false);

    // Link carries the current cart so the property page can
    // append the next room on Reserve.
    $html = $r->html();
    expect($html)->toContain('/stays/'.$this->property->public_slug);
    expect($html)->toContain('Add another room');
});

it('CreateStaysBooking creates N bookings + a single aggregate Payment for a cart', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/cart-abc',
                'access_code' => 'ac_cart',
                'reference' => 'kev_stays_cart_1_x',
            ],
        ]),
    ]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_ids'           => [$this->roomA->id, $this->roomB->id],
        'guest_name'         => 'Ada N.',
        'guest_email'        => 'ada@example.test',
        'guest_phone'        => '+2348030000000',
        'adults'             => 2, 'children' => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    // Two bookings, both stamped with the same cart_ref.
    expect($result['cart_ref'])->toStartWith('cart-');
    expect($result['booking_refs'])->toHaveCount(2);

    $bookings = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->whereIn('booking_ref', $result['booking_refs'])
        ->get();
    expect($bookings)->toHaveCount(2);
    expect($bookings->pluck('stays_cart_ref')->unique())->toHaveCount(1);
    expect($bookings->first()->stays_cart_ref)->toBe($result['cart_ref']);

    // ONE Payment for the aggregate, tagged with the cart_ref +
    // amount = sum of both bookings' total_amount.
    $payments = Payment::query()->withoutGlobalScope(TenantScope::class)
        ->where('stays_cart_ref', $result['cart_ref'])->get();
    expect($payments)->toHaveCount(1);
    expect((int) $payments->first()->amount)
        ->toBe((int) $bookings->sum('total_amount'));
});

it('ConfirmBookingPayment marks every sibling in the cart Confirmed on the primary Payment success', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    Event::fake([\App\Events\BookingConfirmed::class]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_ids'           => [$this->roomA->id, $this->roomB->id],
        'guest_name'         => 'Ada N.',
        'guest_email'        => 'ada@example.test',
        'guest_phone'        => '+2348030000000',
        'adults'             => 2, 'children' => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    // Bookings start Pending.
    $bookings = Booking::query()->withoutGlobalScope(TenantScope::class)
        ->whereIn('booking_ref', $result['booking_refs'])->get();
    foreach ($bookings as $b) {
        expect($b->booking_status)->toEqual(BookingStatus::Pending);
    }

    // Simulate Paystack confirming the aggregate payment.
    $payment = Payment::query()->withoutGlobalScope(TenantScope::class)
        ->where('stays_cart_ref', $result['cart_ref'])->firstOrFail();
    $this->tenant->makeCurrent();
    app(\App\Actions\Bookings\ConfirmBookingPayment::class)->handle($payment);
    Tenant::forgetCurrent();

    // Both bookings now Confirmed - the sibling walk did its job.
    $bookings = Booking::query()->withoutGlobalScope(TenantScope::class)
        ->whereIn('booking_ref', $result['booking_refs'])->get();
    foreach ($bookings as $b) {
        expect($b->booking_status)->toEqual(BookingStatus::Confirmed);
        expect($b->payment_status)->toEqual(PaymentStatus::Succeeded);
    }
    // BookingConfirmed dispatches for EACH booking so downstream
    // mail listeners fire per-room.
    Event::assertDispatchedTimes(\App\Events\BookingConfirmed::class, 2);
});

it('single-room path (backwards compat) still works without cart_ref', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_id'            => $this->roomA->id,   // legacy single
        'guest_name'         => 'Ada N.',
        'guest_email'        => 'ada@example.test',
        'guest_phone'        => '+2348030000000',
        'adults'             => 1, 'children' => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    expect($result['cart_ref'])->toBeNull();
    expect($result['booking_refs'])->toHaveCount(1);

    $booking = Booking::query()->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])->firstOrFail();
    expect($booking->stays_cart_ref)->toBeNull();
});
