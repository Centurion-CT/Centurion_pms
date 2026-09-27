<?php

declare(strict_types=1);

use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Booking;
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
 * Pins the fee model + pay-at-property option on the marketplace
 * checkout:
 *
 *   * Keverna platform fee is 5% of the room subtotal, additive
 *     to the guest total. Old config had 0.5% capped at ₦2,000
 *     for the tenant flow; marketplace now runs at 5%.
 *   * Paystack processing is 1.5% of chargeable subtotal capped
 *     at ₦2,000 (no ₦100 flat portion for real-hospitality-sized
 *     bookings).
 *   * Guest picks Pay-in-full OR Pay-30%-deposit. Deposit charges
 *     30% of the total up-front via Paystack + leaves the balance
 *     due on the booking so the operator collects at check-in.
 */
beforeEach(function (): void {
    // Pin the fee model at the values the checkout page + the
    // CreateStaysBooking action are expected to charge - keeps
    // this suite deterministic even if the .env drifts.
    config()->set('keverna.pricing.paystack_fee_percent', 1.5);
    config()->set('keverna.pricing.paystack_fee_flat_ngn', 0);
    config()->set('keverna.pricing.paystack_fee_cap_ngn', 2_000);
    // Marketplace fee is flat 2.5% now (previously tiered 5%/2.5%).
    config()->set('keverna.pricing.stays_fee_percent_default', 2.5);
    config()->set('keverna.pricing.stays_fee_percent_high_tier', 2.5);
    config()->set('keverna.pricing.stays_fee_tier_threshold_ngn', 100_000);
    // Tenant-flow keverna_fee_percent still used for tenant bookings
    // - marketplace path never reads it.
    config()->set('keverna.pricing.keverna_fee_percent', 2.5);
    config()->set('keverna.pricing.keverna_fee_cap_ngn', 10_000_000);
    config()->set('keverna.pricing.stays_deposit_percent', 30);

    $this->tenant = Tenant::factory()->create([
        'slug' => 'pref-test',
        'paystack_subaccount_code' => 'ACCT_prefx',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Preference Test Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();

    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');
});

afterEach(fn () => Tenant::forgetCurrent());

it('breakdown ships 2.5% Keverna service fee + 1.5% Paystack processing (capped at ₦2000)', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString());

    $bd = $c->instance()->breakdown();
    expect($bd)->not->toBeNull();
    expect($bd['nights'])->toBe(1);
    expect($bd['subtotal'])->toBe(50_000);
    // Additive fee model: guest sees + pays sticker + Keverna
    // service fee + Paystack processing. Operator receives 100%
    // of the room rate.
    // 2.5% × 50,000 = 1,250 (Keverna service fee, guest paid).
    // 1.5% × 50,000 = 750   (Paystack processing, guest paid).
    // Guest total = 50,000 + 1,250 + 750.
    expect($bd['platform_fee'])->toBe(1_250);
    expect($bd['processing_fee'])->toBe(750);
    expect($bd['total'])->toBe(50_000 + 1_250 + 750);
    // Operator payout = full subtotal, no deductions.
    expect($bd['operator_payout'])->toBe(50_000);
    expect($bd['platform_fee_percent'])->toBe(2.5);
});

it('holds the same 2.5% rate at any subtotal (flat, no tier)', function (): void {
    // Tier config is still in place - default + high are both 2.5%.
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(6)->toDateString());

    $bd = $c->instance()->breakdown();
    expect($bd['subtotal'])->toBe(150_000);
    expect($bd['platform_fee_percent'])->toBe(2.5);
    expect($bd['platform_fee'])->toBe(3_750);      // 2.5% × 150k
    expect($bd['operator_payout'])->toBe(150_000); // full room rate
});

it('caps Paystack processing at ₦2000 on a large booking', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        // 30 nights × ₦50,000 = ₦1,500,000 subtotal. 1.5% = ₦22,500
        // uncapped -> cap kicks in at ₦2,000.
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(33)->toDateString());

    $bd = $c->instance()->breakdown();
    expect($bd['processing_fee'])->toBe(2_000);
});

it('defaults paymentPreference to full', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSet('paymentPreference', 'full');
});

it('lets the guest pick pay-deposit + splits due-now / due-at-property in the breakdown', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('paymentPreference', 'deposit');

    $bd = $c->instance()->breakdown();
    // Total = 53,288 (from the earlier test).
    // 30% of 53,288 = 15,986.4 rounds to 15,986.
    expect($bd['payment_preference'])->toBe('deposit');
    expect($bd['due_now'])->toBe((int) round($bd['total'] * 0.30));
    expect($bd['due_at_property'])->toBe($bd['total'] - $bd['due_now']);
});

it('pay button label switches to "Pay ₦X deposit" when deposit is picked', function (): void {
    // Contact-info gate now sits between dates + the Pay label -
    // seed it so the label reaches the deposit branch (dedicated
    // contact-guard tests live in StaysCheckoutPayGuardTest).
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+2348030000000')
        ->set('paymentPreference', 'deposit');

    expect($c->instance()->payButtonLabel())->toContain('deposit');
});

it('rejects an unknown paymentPreference on pay()', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+2348030000000')
        ->set('paymentPreference', 'monopoly-money')
        ->call('pay')
        ->assertHasErrors('paymentPreference');
});

it('drops the misleading 98/2 copy from the summary', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertDontSee('98%')
        ->assertDontSee('Keverna keeps 2%');
});

it('CreateStaysBooking sets deposit_amount + balance_due when payment_preference=deposit', function (): void {
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
        'adults'             => 2,
        'children'           => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'deposit',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    $total = (int) $booking->total_amount;
    $expectedDeposit = (int) round($total * 0.30);
    expect((int) $booking->deposit_amount)->toBe($expectedDeposit);
    expect((int) $booking->balance_due)->toBe($total - $expectedDeposit);
});

it('CreateStaysBooking rewrites booking totals to match FeePolicy after CreateBooking runs', function (): void {
    // Verifies the marketplace override: guest total excludes
    // Keverna fee (only subtotal + Paystack) + tiered % is applied.
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
        'adults'             => 2, 'children' => 0,
        // 1 night × ₦50k = ₦50k subtotal - below threshold, 5% tier.
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    expect((int) $booking->room_total)->toBe(50_000);
    expect((int) $booking->keverna_fee)->toBe(1_250);   // 2.5% × 50k
    expect((int) $booking->paystack_fee)->toBe(750);    // 1.5% × 50k
    expect((int) $booking->total_amount)->toBe(52_000); // subtotal + Keverna + Paystack
});

it('CreateStaysBooking applies the flat 2.5% rate on a subtotal above ₦100k too', function (): void {
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
        'adults'             => 2, 'children' => 0,
        // 3 nights × ₦50k = ₦150k subtotal, above threshold -> 2.5%.
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(6)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    expect((int) $booking->room_total)->toBe(150_000);
    expect((int) $booking->keverna_fee)->toBe(3_750);   // 2.5% × 150k
    // Paystack: 1.5% × 150k = 2,250 -> ₦2,000 cap.
    expect((int) $booking->paystack_fee)->toBe(2_000);
    expect((int) $booking->total_amount)->toBe(155_750); // subtotal + Keverna + capped Paystack
});

it('CreateStaysBooking leaves deposit_amount unset when payment_preference=full', function (): void {
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
        'adults'             => 2,
        'children'           => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    expect((int) $booking->deposit_amount)->toBe(0);
    expect((int) $booking->balance_due)->toBe(0);
});

it('deposit-mode Paystack init charges only the deposit portion', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_id'            => $this->room->id,
        'guest_name'         => 'Ada N',
        'guest_email'        => 'ada@example.test',
        'guest_phone'        => '+2348030000000',
        'adults'             => 2,
        'children'           => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'deposit',
    ], callbackUrl: 'http://central.test/callback');

    // Amount sent to Paystack init is deposit_amount in kobo, NOT
    // total_amount. Additive model: total = subtotal + Keverna
    // service fee + Paystack processing.
    // 1 night × ₦50k + 2.5% (₦1,250) + 1.5% (₦750) = ₦52,000.
    // 30% × ₦52,000 = ₦15,600. In kobo: 1,560,000.
    Http::assertSent(fn ($r) => $r->url() === 'https://api.paystack.test/transaction/initialize'
        && (int) $r['amount'] === 1_560_000);
});
