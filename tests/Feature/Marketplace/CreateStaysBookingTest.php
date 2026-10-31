<?php

declare(strict_types=1);

use App\Actions\Bookings\BookingValidationException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Marketplace\CreateStaysBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * End-to-end test of the marketplace booking service that bridges the
 * central-domain checkout into the per-tenant CreateBooking pipeline
 * and kicks off Paystack with the subaccount split.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'stays-book-test',
        'email' => 'ops@stays.test',
        'paystack_subaccount_code' => 'ACCT_test123',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'city' => 'Lagos',
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active]);
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

it('creates a booking + payment + returns a Paystack auth url', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/marketplace-abc',
                'access_code' => 'ac_marketplace',
                'reference' => 'kev_stays_x',
            ],
        ]),
    ]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug' => $this->property->public_slug,
        'room_id'       => $this->room->id,
        'guest_name'    => 'Ada Nwokolo',
        'guest_email'   => 'ada@example.test',
        'guest_phone'   => '+2348030000000',
        'adults'        => 2,
        'children'      => 0,
        'checkin'       => Carbon::today()->addDays(3)->toDateString(),
        'checkout'      => Carbon::today()->addDays(5)->toDateString(),
    ], callbackUrl: 'http://central.test/stays/pay/callback');

    expect($result['authorization_url'])->toBe('https://checkout.paystack.test/marketplace-abc');
    expect($result['booking_ref'])->toStartWith('KV-');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();
    expect((int) $booking->tenant_id)->toBe($this->tenant->id);
    expect((int) $booking->room_id)->toBe($this->room->id);
    expect($booking->guest_name)->toBe('Ada Nwokolo');
    expect($booking->guest_email)->toBe('ada@example.test');

    $payment = Payment::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_id', $booking->id)
        ->firstOrFail();
    expect($payment->status)->toEqual(PaymentStatus::Pending);
    expect($payment->type)->toEqual(PaymentType::Full);
    expect($payment->paystack_reference)->toStartWith('kev_stays_');
});

it('routes Paystack init via the tenant subaccount (98/2 split)', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    app(CreateStaysBooking::class)->handle([
        'property_slug' => $this->property->public_slug,
        'room_id'       => $this->room->id,
        'guest_name'    => 'Test Guest',
        'guest_email'   => 'test@example.test',
        'guest_phone'   => '+2348030000000',
        'adults'        => 1,
        'children'      => 0,
        'checkin'       => Carbon::today()->addDays(3)->toDateString(),
        'checkout'      => Carbon::today()->addDays(4)->toDateString(),
    ], callbackUrl: 'http://central.test/callback');

    Http::assertSent(fn ($r) => $r->url() === 'https://api.paystack.test/transaction/initialize'
        && $r['subaccount'] === 'ACCT_test123'
        && $r['bearer'] === 'subaccount'
        && $r['callback_url'] === 'http://central.test/callback'
        && str_starts_with($r['reference'], 'kev_stays_'));
});

it('leaves the business it ran for current on the way out', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);
    // Callers run on the business's own address, with it current.
    expect(Tenant::current()?->id)->toBe($this->tenant->id);

    app(CreateStaysBooking::class)->handle([
        'property_slug' => $this->property->public_slug,
        'room_id'       => $this->room->id,
        'guest_name'    => 'X', 'guest_email' => 'x@x.test', 'guest_phone' => '+234800',
        'adults' => 1, 'children' => 0,
        'checkin' => Carbon::today()->addDays(2)->toDateString(),
        'checkout' => Carbon::today()->addDays(3)->toDateString(),
    ], callbackUrl: 'http://x/y');

    expect(Tenant::current()?->id)->toBe($this->tenant->id);
});

it('rejects an off-market property even when the slug resolves', function (): void {
    $this->property->forceFill(['is_listed_publicly' => false])->save();

    expect(fn () => app(CreateStaysBooking::class)->handle([
        'property_slug' => $this->property->public_slug,
        'room_id'       => $this->room->id,
        'guest_name'    => 'X', 'guest_email' => 'x@x.test', 'guest_phone' => '+234800',
        'adults' => 1, 'children' => 0,
        'checkin' => Carbon::today()->addDays(2)->toDateString(),
        'checkout' => Carbon::today()->addDays(3)->toDateString(),
    ], callbackUrl: 'http://x/y'))->toThrow(BookingValidationException::class, 'no longer available');
});

it('rejects an inactive room', function (): void {
    $this->room->forceFill(['status' => RoomStatus::Inactive])->save();

    expect(fn () => app(CreateStaysBooking::class)->handle([
        'property_slug' => $this->property->public_slug,
        'room_id'       => $this->room->id,
        'guest_name'    => 'X', 'guest_email' => 'x@x.test', 'guest_phone' => '+234800',
        'adults' => 1, 'children' => 0,
        'checkin' => Carbon::today()->addDays(2)->toDateString(),
        'checkout' => Carbon::today()->addDays(3)->toDateString(),
    ], callbackUrl: 'http://x/y'))->toThrow(BookingValidationException::class, 'no longer bookable');
});
