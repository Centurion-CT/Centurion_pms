<?php

declare(strict_types=1);

use App\Actions\Bookings\InitiateBookingPayment;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.callback_url', 'http://kev.test/checkout/return');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'init-pay-test',
        'paystack_secret_key' => 'sk_op_tenant_key',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('initialises Paystack using the operator\'s secret key (not Keverna master)', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/abc',
                'access_code' => 'ac_abc',
                'reference' => 'kev_book_x',
            ],
        ]),
    ]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 50_000,
        'deposit_amount' => 0,
    ]);

    $url = app(InitiateBookingPayment::class)->handle($booking);

    expect($url)->toBe('https://checkout.paystack.test/abc');

    Http::assertSent(fn ($r) => $r->header('Authorization')[0] === 'Bearer sk_op_tenant_key'
        && $r['email'] === $booking->guest_email
        && $r['amount'] === 5_000_000   // 50,000 NGN -> 5,000,000 kobo
        && $r['metadata']['booking_id'] === $booking->id);
});

it('creates a Pending Full Payment row when the booking has no deposit', function (): void {
    Http::fake(['https://api.paystack.test/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
    ])]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 50_000, 'deposit_amount' => 0,
    ]);

    app(InitiateBookingPayment::class)->handle($booking);

    $p = Payment::query()->where('booking_id', $booking->id)->first();
    expect($p)->not->toBeNull();
    expect($p->type)->toBe(PaymentType::Full);
    expect($p->amount)->toBe(50_000);
    expect($p->status)->toBe(PaymentStatus::Pending);
    expect($p->paystack_reference)->toStartWith('kev_book_');
});

it('creates a Pending Deposit Payment for the deposit amount when booking has deposit_amount > 0', function (): void {
    Http::fake(['https://api.paystack.test/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
    ])]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 100_000,
        'deposit_amount' => 30_000,
        'balance_due' => 70_000,
    ]);

    app(InitiateBookingPayment::class)->handle($booking);

    $p = Payment::query()->where('booking_id', $booking->id)->first();
    expect($p->type)->toBe(PaymentType::Deposit);
    expect($p->amount)->toBe(30_000);
});

it('marks the Payment Failed and rethrows when Paystack init errors', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response(['status' => false], 500),
    ]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();

    $caught = null;
    try {
        app(InitiateBookingPayment::class)->handle($booking);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();

    $p = Payment::query()->where('booking_id', $booking->id)->first();
    expect($p)->not->toBeNull();
    expect($p->status)->toBe(PaymentStatus::Failed);
});

it('uses the platform master key + subaccount split when the tenant has a subaccount code', function (): void {
    config()->set('services.paystack.secret_key', 'sk_master_platform');
    Http::fake(['https://api.paystack.test/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/split', 'access_code' => 'ac', 'reference' => 'ref'],
    ])]);

    Tenant::forgetCurrent();
    $splitTenant = Tenant::factory()->create([
        'paystack_secret_key' => null,
        'paystack_subaccount_code' => 'ACCT_split_xyz',
    ]);
    $splitTenant->makeCurrent();
    $prop = Property::factory()->for($splitTenant)->create();
    $room = Room::factory()->for($splitTenant)->for($prop)->create();
    $booking = Booking::factory()->for($splitTenant)->for($room)->create([
        'total_amount' => 40_000, 'deposit_amount' => 0,
    ]);

    app(InitiateBookingPayment::class)->handle($booking);

    Http::assertSent(fn ($r) => $r->header('Authorization')[0] === 'Bearer sk_master_platform'
        && $r['subaccount'] === 'ACCT_split_xyz');
});

it('falls back to the platform Paystack account when the tenant has neither subaccount nor secret key', function (): void {
    config()->set('services.paystack.secret_key', 'sk_master_platform');
    Http::fake(['https://api.paystack.test/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/fallback', 'access_code' => 'ac', 'reference' => 'ref'],
    ])]);

    Tenant::forgetCurrent();
    $bareTenant = Tenant::factory()->create([
        'slug' => 'no-paystack-tenant',
        'paystack_secret_key' => null,
        'paystack_subaccount_code' => null,
    ]);
    $bareTenant->makeCurrent();
    $prop = Property::factory()->for($bareTenant)->create();
    $room = Room::factory()->for($bareTenant)->for($prop)->create();
    $booking = Booking::factory()->for($bareTenant)->for($room)->create([
        'total_amount' => 25_000, 'deposit_amount' => 0,
    ]);

    $url = app(InitiateBookingPayment::class)->handle($booking);

    // Guest is NOT blocked — booking proceeds through the platform account.
    expect($url)->toBe('https://checkout.paystack.test/fallback');

    // Master key used, no split configured.
    Http::assertSent(fn ($r) => $r->header('Authorization')[0] === 'Bearer sk_master_platform'
        && ! isset($r['subaccount']));

    // Ops trail — the fallback is logged so we can reconcile payouts.
    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', Booking::class)
        ->where('subject_id', $booking->id)
        ->where('event', 'paystack_platform_fallback')
        ->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['tenant_slug'])->toBe('no-paystack-tenant');
});
