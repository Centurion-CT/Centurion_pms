<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Admin\PaystackFallback\Index as PaystackFallbackIndex;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create();
});

/**
 * Helper: seed a tenant with a fallback booking + its (optional) payment.
 */
function seedFallbackBooking(
    string $slug,
    int $amount,
    bool $paid = true,
    ?string $subaccount = null,
    ?string $secret = null,
): Tenant {
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'paystack_subaccount_code' => $subaccount,
        'paystack_secret_key' => $secret,
    ]);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $room = Room::factory()->for($tenant)->for($property)->create();
    $booking = Booking::factory()->for($tenant)->for($room)->create([
        'total_amount' => $amount,
    ]);

    // Emulate what InitiateBookingPayment's fallback branch logs.
    activity('booking')
        ->performedOn($booking)
        ->event('paystack_platform_fallback')
        ->withProperties([
            'tenant_id'   => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'booking_ref' => $booking->booking_ref,
            'amount'      => $amount,
        ])
        ->log('fallback');

    if ($paid) {
        Payment::factory()->for($tenant)->create([
            'booking_id' => $booking->id,
            'amount'     => $amount,
            'type'       => PaymentType::Full,
            'status'     => PaymentStatus::Succeeded,
            'paid_at'    => now(),
        ]);
    }

    Tenant::forgetCurrent();
    return $tenant;
}

/* ------------------------------------------------------------------ */
/* Aggregation                                                         */
/* ------------------------------------------------------------------ */

it('rolls up succeeded fallback amounts per tenant', function (): void {
    seedFallbackBooking('acorn-hotel', 200_000);
    seedFallbackBooking('brook-inn', 50_000);
    // Extra fallback activity for acorn-hotel that never had a matching
    // Payment (abandoned checkout). Bumps the "attempted" total, not the
    // "amount owed" — which is what the aggregation should reflect.
    $acornTenant = Tenant::query()->where('slug', 'acorn-hotel')->first();
    $acornTenant->makeCurrent();
    $acornBooking = Booking::query()->where('tenant_id', $acornTenant->id)->first();
    activity('booking')
        ->performedOn($acornBooking)
        ->event('paystack_platform_fallback')
        ->withProperties([
            'tenant_id'   => $acornTenant->id,
            'tenant_slug' => 'acorn-hotel',
            'booking_ref' => $acornBooking->booking_ref,
            'amount'      => 25_000,
        ])
        ->log('fallback');
    Tenant::forgetCurrent();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PaystackFallbackIndex::class)
        ->assertSeeHtml('data-testid="fallback-row-acorn-hotel"')
        ->assertSeeHtml('data-testid="fallback-row-brook-inn"')
        ->assertSeeHtml('data-testid="fallback-kpi-tenants">2<')
        ->assertSeeHtml('&#8358;250,000');   // 200k paid + 50k paid; the +25k extra activity has no payment
});

it('excludes abandoned checkouts from the amount-owed column', function (): void {
    // Guest initiated but never completed — no Payment row.
    seedFallbackBooking('abandoned-hotel', 300_000, paid: false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(PaystackFallbackIndex::class)
        ->assertSeeHtml('data-testid="fallback-row-abandoned-hotel"')
        ->assertSeeHtml('&#8358;0');  // amount owed
});

/* ------------------------------------------------------------------ */
/* Filter                                                              */
/* ------------------------------------------------------------------ */

it('defaults to "unresolved" filter — tenants without paystack setup', function (): void {
    seedFallbackBooking('unpaid-t', 100_000);
    seedFallbackBooking('paid-t', 100_000, subaccount: 'ACCT_now_connected');

    Livewire::actingAs($this->admin, 'admin')
        ->test(PaystackFallbackIndex::class)
        ->assertSet('viewFilter', 'unresolved')
        ->assertSeeHtml('data-testid="fallback-row-unpaid-t"')
        ->assertDontSeeHtml('data-testid="fallback-row-paid-t"');
});

it('switches to resolved to see tenants who have since connected', function (): void {
    seedFallbackBooking('unpaid-t', 100_000);
    seedFallbackBooking('paid-t', 100_000, subaccount: 'ACCT_now_connected');

    Livewire::actingAs($this->admin, 'admin')
        ->test(PaystackFallbackIndex::class)
        ->set('viewFilter', 'resolved')
        ->assertSeeHtml('data-testid="fallback-row-paid-t"')
        ->assertDontSeeHtml('data-testid="fallback-row-unpaid-t"');
});

it('shows the empty state when there are no fallback bookings', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(PaystackFallbackIndex::class)
        ->assertSeeHtml('data-testid="fallback-empty"');
});

/* ------------------------------------------------------------------ */
/* Access                                                              */
/* ------------------------------------------------------------------ */

it('requires an admin session', function (): void {
    $this->get('http://admin.keverna.test/paystack-fallback')
        ->assertRedirect('http://admin.keverna.test/login');
});
