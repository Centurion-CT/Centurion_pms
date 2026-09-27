<?php

declare(strict_types=1);

use App\Actions\GuestPortal\InitiatePortalPayment;
use App\Actions\GuestPortal\ResolvePortalPaymentIntent;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\GuestPortalIntent;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Card-pay path for guest-portal Extend Stay. Verifies the full
 * loop: initiate → Paystack redirect → callback → intent resolves
 * → booking dates advanced + charge settled.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.callback_url', 'http://gp-pay.keverna.test/room/portal/pay/callback');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'gp-pay',
        'paystack_secret_key' => 'sk_gp_test',
    ]);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Std', 'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0, 'min_nights' => 1,
    ]);
    // base_rate lives on RoomCategory (not Room) since the Phase 2
    // refactor — the accessor Room::base_rate reads through category.
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Card Payer',
        'guest_phone' => '+2348012345678',
        'guest_email' => 'cardpayer@example.com',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'total_amount' => 80_000,
        'room_total' => 80_000,
        'nights' => 2,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('InitiatePortalPayment creates Payment + Intent and returns Paystack URL', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/xyz', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);

    $result = app(InitiatePortalPayment::class)->handle(
        booking: $this->booking,
        amountNgn: 40_000,
        purpose: GuestPortalIntent::PURPOSE_EXTEND,
        payload: ['new_checkout_date' => Carbon::today()->addDays(2)->toDateString()],
    );

    expect($result['authorization_url'])->toBe('https://checkout.paystack.test/xyz');
    expect(str_starts_with($result['reference'], 'gp-extend-'))->toBeTrue();

    $payment = Payment::query()->where('paystack_reference', $result['reference'])->firstOrFail();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect((int) $payment->amount)->toBe(40_000);
    expect($payment->type)->toBe(PaymentType::Extra);

    $intent = GuestPortalIntent::query()->where('payment_id', $payment->id)->firstOrFail();
    expect($intent->purpose)->toBe(GuestPortalIntent::PURPOSE_EXTEND);
    expect($intent->status)->toBe(GuestPortalIntent::STATUS_PENDING);
    expect($intent->payload['new_checkout_date'])->toBe(Carbon::today()->addDays(2)->toDateString());
});

it('Extend tile pay-by-card initiates a Paystack redirect', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/pay', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);

    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        ->set('extendCheckoutDate', Carbon::today()->addDays(3)->toDateString())
        ->call('submitExtendPayByCard')
        ->assertRedirect('https://checkout.paystack.test/pay');

    // Intent created with the right payload + amount = 2 extra nights × ₦40k.
    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    expect($intent->purpose)->toBe(GuestPortalIntent::PURPOSE_EXTEND);
    expect($intent->payload['new_checkout_date'])->toBe(Carbon::today()->addDays(3)->toDateString());
    expect((int) $intent->payment->amount)->toBe(80_000);
});

it('Callback verifies with Paystack + resolves the intent + extends the booking', function (): void {
    // Seed: an initiated intent with amount + payload.
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://x/y', 'access_code' => 'a', 'reference' => 'r'],
    ])]);
    $newCheckout = Carbon::today()->addDays(3)->toDateString();
    $result = app(InitiatePortalPayment::class)->handle(
        booking: $this->booking,
        amountNgn: 80_000,
        purpose: GuestPortalIntent::PURPOSE_EXTEND,
        payload: ['new_checkout_date' => $newCheckout],
    );

    // Paystack verify says success.
    Http::fake([
        'https://api.paystack.test/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'amount' => 8_000_000,
                'reference' => $result['reference'],
                'paid_at' => Carbon::now()->toIso8601String(),
            ],
        ]),
    ]);

    $response = $this->get('http://gp-pay.keverna.test/room/portal/pay/callback?reference='.$result['reference']);
    $response->assertRedirect(route('tenant.guest.portal', ['qrSlug' => $this->room->qr_slug]));

    // Booking dates advanced.
    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($newCheckout);

    // Extension charge is settled (settled via the portal payment).
    $charge = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    expect((bool) $charge->settled)->toBeTrue();
    expect((string) $charge->settled_method)->toBe('card');

    // Intent + payment both marked done.
    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    expect($intent->status)->toBe(GuestPortalIntent::STATUS_COMPLETED);
    expect($intent->payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('Callback flashes a failure message when Paystack verify does not succeed', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'x', 'access_code' => 'a', 'reference' => 'r'],
    ])]);
    $result = app(InitiatePortalPayment::class)->handle(
        booking: $this->booking, amountNgn: 40_000, purpose: GuestPortalIntent::PURPOSE_EXTEND,
        payload: ['new_checkout_date' => Carbon::today()->addDays(2)->toDateString()],
    );

    Http::fake([
        'https://api.paystack.test/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'failed', 'reference' => $result['reference']],
        ]),
    ]);

    $this->get('http://gp-pay.keverna.test/room/portal/pay/callback?reference='.$result['reference'])
        ->assertRedirect(route('tenant.guest.portal', ['qrSlug' => $this->room->qr_slug]));

    // Booking dates unchanged.
    expect($this->booking->fresh()->checkout_date->toDateString())
        ->toBe(Carbon::today()->addDay()->toDateString());
    // Intent still pending (not marked completed or failed by the callback path).
    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    expect($intent->status)->toBe(GuestPortalIntent::STATUS_PENDING);
});

it('ResolvePortalPaymentIntent is idempotent on double-fire', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'x', 'access_code' => 'a', 'reference' => 'r'],
    ])]);
    $result = app(InitiatePortalPayment::class)->handle(
        booking: $this->booking, amountNgn: 40_000, purpose: GuestPortalIntent::PURPOSE_EXTEND,
        payload: ['new_checkout_date' => Carbon::today()->addDays(2)->toDateString()],
    );
    $intent = $result['intent'];

    $resolver = app(ResolvePortalPaymentIntent::class);
    $resolver->handle($intent);
    $resolver->handle($intent->fresh()); // second fire from webhook race

    // Only ONE Extension charge on the booking (no dupes).
    $count = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->count();
    expect($count)->toBe(1);
});
