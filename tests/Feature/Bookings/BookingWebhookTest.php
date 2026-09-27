<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'booking-hook',
        'paystack_secret_key' => 'sk_op_tenant_key',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

function signedForTenant(array $payload, string $key): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha512', $body, $key);

    return [$body, $sig];
}

it('rejects an unsigned webhook with 401', function (): void {
    $response = $this->postJson(
        'http://booking-hook.keverna.test/paystack/booking-webhook',
        ['event' => 'charge.success', 'data' => ['reference' => 'x']],
    );
    $response->assertStatus(401);
});

it('rejects a signature signed with the WRONG operator\'s key', function (): void {
    [$body, $sig] = signedForTenant(
        ['event' => 'charge.success', 'data' => ['reference' => 'x', 'amount' => 100]],
        'sk_op_OTHER_tenant',
    );

    $response = $this->call(
        'POST', 'http://booking-hook.keverna.test/paystack/booking-webhook',
        [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(401);
});

it('rejects unknown references with 422', function (): void {
    [$body, $sig] = signedForTenant(
        ['event' => 'charge.success', 'data' => ['reference' => 'ghost', 'amount' => 100]],
        'sk_op_tenant_key',
    );

    $response = $this->call(
        'POST', 'http://booking-hook.keverna.test/paystack/booking-webhook',
        [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(422);
});

it('rejects amount mismatch with 422 and leaves the payment Pending', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 50_000,
    ]);
    $payment = Payment::factory()->for($this->tenant)->for($booking)->create([
        'paystack_reference' => 'mismatch-ref',
        'amount' => 50_000,
        'type' => PaymentType::Full,
    ]);

    [$body, $sig] = signedForTenant(
        ['event' => 'charge.success', 'data' => ['reference' => 'mismatch-ref', 'amount' => 100]],
        'sk_op_tenant_key',
    );

    $response = $this->call(
        'POST', 'http://booking-hook.keverna.test/paystack/booking-webhook',
        [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(422);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Pending);
});

it('flips booking to Confirmed on a verified Full charge.success', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 50_000,
    ]);
    $payment = Payment::factory()->for($this->tenant)->for($booking)->create([
        'paystack_reference' => 'happy-full',
        'amount' => 50_000,
        'type' => PaymentType::Full,
    ]);

    [$body, $sig] = signedForTenant([
        'event' => 'charge.success',
        'data' => ['reference' => 'happy-full', 'amount' => 5_000_000, 'paid_at' => '2030-08-01T12:00:00Z'],
    ], 'sk_op_tenant_key');

    $response = $this->call(
        'POST', 'http://booking-hook.keverna.test/paystack/booking-webhook',
        [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertOk()->assertJson(['ok' => true, 'confirmed' => true]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    expect($payment->fresh()->paid_at)->not->toBeNull();
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Succeeded);
});

it('flips booking to OnHold (not Confirmed) on a Deposit charge.success', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 100_000, 'deposit_amount' => 30_000, 'balance_due' => 70_000,
    ]);
    $payment = Payment::factory()->for($this->tenant)->for($booking)->create([
        'paystack_reference' => 'happy-deposit',
        'amount' => 30_000,
        'type' => PaymentType::Deposit,
    ]);

    [$body, $sig] = signedForTenant([
        'event' => 'charge.success',
        'data' => ['reference' => 'happy-deposit', 'amount' => 3_000_000],
    ], 'sk_op_tenant_key');

    $response = $this->call(
        'POST', 'http://booking-hook.keverna.test/paystack/booking-webhook',
        [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertOk();
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::OnHold);
    expect($booking->fresh()->balance_due)->toBe(70_000); // still owed
});

it('is idempotent — second delivery for the same reference is a no-op', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $payment = Payment::factory()->for($this->tenant)->for($booking)->create([
        'paystack_reference' => 'idempotent',
        'amount' => 50_000,
        'type' => PaymentType::Full,
    ]);
    $booking->forceFill(['total_amount' => 50_000])->save();

    [$body, $sig] = signedForTenant([
        'event' => 'charge.success',
        'data' => ['reference' => 'idempotent', 'amount' => 5_000_000],
    ], 'sk_op_tenant_key');

    $first = $this->call('POST', 'http://booking-hook.keverna.test/paystack/booking-webhook', [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body);
    $first->assertOk();

    $second = $this->call('POST', 'http://booking-hook.keverna.test/paystack/booking-webhook', [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body);

    $second->assertOk()->assertJson(['ok' => true, 'idempotent' => true]);
});

it('ignores non-charge.success events (returns 200 ack)', function (): void {
    [$body, $sig] = signedForTenant([
        'event' => 'charge.failed', 'data' => ['reference' => 'x'],
    ], 'sk_op_tenant_key');

    $response = $this->call('POST', 'http://booking-hook.keverna.test/paystack/booking-webhook', [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body);

    $response->assertOk()->assertJson(['ok' => true, 'ignored' => 'charge.failed']);
});
