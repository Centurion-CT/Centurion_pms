<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Marketplace\CreateStaysBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Coupon code support on the marketplace checkout. Guests can type a
 * property-issued campaign code + hit Apply; validation runs through
 * the same DiscountEngine tenant flows use, so any active code on the
 * property's tenant works. Applied amount is folded into the
 * marketplace breakdown before the Keverna + Paystack fees compute,
 * matching CreateBooking's tenant-side behaviour.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'coupon-test',
        'paystack_subaccount_code' => 'ACCT_coupon',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Coupon Test Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);

    // 10% off code on THIS property's tenant.
    $this->tenPercent = Discount::query()->create([
        'tenant_id' => $this->tenant->id,
        'name'      => 'Ten off',
        'code'      => 'TENOFF',
        'type'      => DiscountType::Percent,
        'value'     => 10,
        'active'    => true,
    ]);

    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the coupon input while no code is applied', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSee('data-testid="checkout-coupon-input"', false)
        ->assertSee('data-testid="checkout-coupon-apply"', false)
        ->assertSet('appliedDiscountCode', null)
        ->assertSet('discountAmount', 0);
});

it('rejects an unknown code with an inline error + no amount applied', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('discountCode', 'BOGUS')
        ->call('applyDiscount')
        ->assertSet('appliedDiscountCode', null)
        ->assertSet('discountAmount', 0)
        ->tap(fn ($c) => expect($c->instance()->discountError)->toContain('No discount'));
});

it('applies a valid code + shows the applied chip + reduces the total', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('discountCode', 'TENOFF')
        ->call('applyDiscount');

    $c->assertSet('appliedDiscountCode', 'TENOFF')
      ->assertSet('discountAmount', 5_000)  // 10% of 50k
      ->assertSee('data-testid="checkout-coupon-applied"', false)
      ->assertSee('data-testid="checkout-line-discount"', false);

    $bd = $c->instance()->breakdown();
    // Subtotal is still shown as sticker for transparency, but the
    // net_subtotal drives fees + total.
    expect($bd['subtotal'])->toBe(50_000);
    expect($bd['discount_code'])->toBe('TENOFF');
    expect($bd['discount_amount'])->toBe(5_000);
    expect($bd['net_subtotal'])->toBe(45_000);
    // Keverna: 2.5% of 45k = 1,125. Paystack: 1.5% of 45k = 675.
    expect($bd['platform_fee'])->toBe(1_125);
    expect($bd['processing_fee'])->toBe(675);
    expect($bd['total'])->toBe(45_000 + 1_125 + 675);
});

it('lets the guest remove an applied code + falls back to the un-discounted total', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('discountCode', 'TENOFF')
        ->call('applyDiscount')
        ->assertSet('appliedDiscountCode', 'TENOFF')
        ->call('removeDiscount')
        ->assertSet('appliedDiscountCode', null)
        ->assertSet('discountAmount', 0)
        ->assertSet('discountCode', '');

    $bd = $c->instance()->breakdown();
    expect($bd['net_subtotal'])->toBe(50_000);
    expect($bd['total'])->toBe(50_000 + 1_250 + 750);  // full-price fees at 2.5% + 1.5%
});

it('nudges the guest to pick dates before trying to apply a code', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', '')
        ->set('checkout', '')
        ->set('discountCode', 'TENOFF')
        ->call('applyDiscount')
        ->tap(fn ($c) => expect($c->instance()->discountError)->toContain('Pick your dates'));
});

it('nudges when the input is empty on click', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('discountCode', '')
        ->call('applyDiscount')
        ->tap(fn ($c) => expect($c->instance()->discountError)->toContain('Enter a coupon code'));
});

it('normalises to upper-case when matching the code (guest can type in lower)', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('discountCode', 'tenoff')  // lowercase - Discount::scopeWithCode uppers
        ->call('applyDiscount')
        ->assertSet('appliedDiscountCode', 'tenoff')  // preserve what guest typed
        ->assertSet('discountAmount', 5_000);
});

it('is scoped to the property\'s tenant - a code on ANOTHER tenant does NOT apply here', function (): void {
    // Create a discount for a different tenant with the same code.
    $otherTenant = Tenant::factory()->create(['slug' => 'other-tenant']);
    Discount::query()->create([
        'tenant_id' => $otherTenant->id,
        'name'      => 'Cross-tenant',
        'code'      => 'CROSSCODE',
        'type'      => DiscountType::Percent,
        'value'     => 50,
        'active'    => true,
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('discountCode', 'CROSSCODE')
        ->call('applyDiscount')
        ->assertSet('appliedDiscountCode', null)  // not on this tenant
        ->assertSet('discountAmount', 0);
});

it('CreateStaysBooking passes the applied code into CreateBooking', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_id'            => $this->room->id,
        'guest_name'         => 'Ada N',
        'guest_email'        => 'ada@example.test',
        'guest_phone'        => '+2348030000000',
        'adults'             => 1, 'children' => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
        'discount_code'      => 'TENOFF',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    // room_total = subtotal minus discount = 50k - 5k = 45k.
    expect((int) $booking->room_total)->toBe(45_000);
    // Marketplace fee overlay is computed on the discounted room_total:
    //   Keverna 2.5% × 45k = 1,125;  Paystack 1.5% × 45k = 675.
    expect((int) $booking->keverna_fee)->toBe(1_125);
    expect((int) $booking->paystack_fee)->toBe(675);
    // Guest total = discounted subtotal + Keverna + Paystack.
    expect((int) $booking->total_amount)->toBe(45_000 + 1_125 + 675);
});
