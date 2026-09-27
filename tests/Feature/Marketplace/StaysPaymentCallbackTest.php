<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Marketplace Paystack callback - the piece that turns a redirected
 * guest back on our domain into a Confirmed booking. Every failure
 * mode is a redirect to /stays/pay/failed?reason=<code>, so the tests
 * assert both the confirmed-path AND the specific failure codes.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'callback-test',
        'paystack_subaccount_code' => 'ACCT_x',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create(['is_listed_publicly' => true]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 20_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active]);
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 40_000,
        'balance_due' => 0,
        'booking_status' => BookingStatus::Pending,
    ]);
    $this->payment = Payment::factory()->for($this->tenant)->for($this->booking)->create([
        'amount' => 40_000,
        'type' => PaymentType::Full,
        'status' => PaymentStatus::Pending,
        'paystack_reference' => 'kev_stays_1_abc',
    ]);
    Tenant::forgetCurrent();
    Event::fake([\App\Events\BookingConfirmed::class]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('redirects to /stays/pay/failed?reason=missing-reference when the callback has no reference', function (): void {
    $this->get(route('marketplace.stays.pay.callback'))
        ->assertRedirect(route('marketplace.stays.pay.failed', ['reason' => 'missing-reference']));
});

it('redirects to failed?reason=unknown-reference for a made-up reference', function (): void {
    $this->get(route('marketplace.stays.pay.callback', ['reference' => 'kev_stays_bogus']))
        ->assertRedirect(route('marketplace.stays.pay.failed', ['reason' => 'unknown-reference']));
});

it('marks the payment Succeeded + redirects to /stays/pay/success/{ref} on a good verify', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'amount' => 4_000_000, // 40,000 NGN -> kobo
                'reference' => 'kev_stays_1_abc',
                'paid_at' => '2026-09-08T09:15:00+01:00',
            ],
        ]),
    ]);

    $this->get(route('marketplace.stays.pay.callback', ['reference' => 'kev_stays_1_abc']))
        ->assertRedirect(route('marketplace.stays.pay.success', ['booking_ref' => $this->booking->booking_ref]));

    $payment = Payment::query()->withoutGlobalScope(TenantScope::class)->find($this->payment->id);
    expect($payment->status)->toEqual(PaymentStatus::Succeeded);

    $booking = Booking::query()->withoutGlobalScope(TenantScope::class)->find($this->booking->id);
    expect($booking->booking_status)->toEqual(BookingStatus::Confirmed);

    Event::assertDispatched(\App\Events\BookingConfirmed::class);
});

it('rejects amount-tampered verifications', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'amount' => 100_000, // wrong: 1000 NGN, expected 4,000,000
                'reference' => 'kev_stays_1_abc',
                'paid_at' => '2026-09-08T09:15:00+01:00',
            ],
        ]),
    ]);

    $this->get(route('marketplace.stays.pay.callback', ['reference' => 'kev_stays_1_abc']))
        ->assertRedirect(route('marketplace.stays.pay.failed', ['reason' => 'amount-mismatch']));

    $payment = Payment::query()->withoutGlobalScope(TenantScope::class)->find($this->payment->id);
    expect($payment->status)->toEqual(PaymentStatus::Pending);
});

it('handles non-success verifications gracefully', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'abandoned',
                'amount' => 4_000_000,
                'reference' => 'kev_stays_1_abc',
            ],
        ]),
    ]);

    $this->get(route('marketplace.stays.pay.callback', ['reference' => 'kev_stays_1_abc']))
        ->assertRedirect(route('marketplace.stays.pay.failed', ['reason' => 'not-success']));

    $payment = Payment::query()->withoutGlobalScope(TenantScope::class)->find($this->payment->id);
    expect($payment->status)->toEqual(PaymentStatus::Pending);
});

it('is idempotent - a second callback for an already-succeeded payment goes straight to success', function (): void {
    $this->tenant->makeCurrent();
    $this->payment->forceFill(['status' => PaymentStatus::Succeeded])->save();
    Tenant::forgetCurrent();

    Http::fake();

    $this->get(route('marketplace.stays.pay.callback', ['reference' => 'kev_stays_1_abc']))
        ->assertRedirect(route('marketplace.stays.pay.success', ['booking_ref' => $this->booking->booking_ref]));

    // No Paystack call should have been made on the idempotent path.
    Http::assertNothingSent();
});

it('leaves no tenant leaked in central context after processing', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success', 'amount' => 4_000_000,
                'reference' => 'kev_stays_1_abc',
            ],
        ]),
    ]);
    expect(Tenant::current())->toBeNull();

    $this->get(route('marketplace.stays.pay.callback', ['reference' => 'kev_stays_1_abc']));

    expect(Tenant::current())->toBeNull();
});

it('renders the pay-success page for a confirmed booking', function (): void {
    $this->get(route('marketplace.stays.pay.success', ['booking_ref' => $this->booking->booking_ref]))
        ->assertOk()
        ->assertSee($this->booking->booking_ref)
        ->assertSee($this->booking->guest_name)
        ->assertSee('40,000');
});

it('renders the pay-failed page with a friendly reason', function (): void {
    $this->get(route('marketplace.stays.pay.failed', ['reason' => 'not-success']))
        ->assertOk()
        ->assertSee("card issuer declined");
});
