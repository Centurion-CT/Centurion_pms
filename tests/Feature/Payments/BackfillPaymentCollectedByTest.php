<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pay-backfill']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->cashier = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'name' => 'Bola Cashier',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function historicalPayment(Tenant $t, Room $r): Payment
{
    $booking = Booking::factory()->for($t)->for($r)->confirmed()->create();
    return Payment::factory()->for($t)->for($booking)->succeeded()->create([
        'amount' => 40_000,
        'method' => 'cash',
        'paid_at' => Carbon::now()->subDays(3),
        'collected_by_operator_id' => null,
    ]);
}

function logManualPaymentActivity(Tenant $t, Booking $b, Operator $op, int $paymentId): Activity
{
    return Activity::query()->create([
        'log_name' => 'booking',
        'description' => "Operator {$op->name} recorded a payment.",
        'event' => 'manual_payment_recorded',
        'subject_type' => Booking::class,
        'subject_id' => $b->id,
        'causer_type' => Operator::class,
        'causer_id' => $op->id,
        'tenant_id' => $t->id,
        'properties' => [
            'booking_ref' => $b->booking_ref,
            'amount' => 40_000,
            'method' => 'cash',
            'payment_id' => $paymentId,
        ],
    ]);
}

it('stamps collected_by from a matching activity_log entry', function (): void {
    $payment = historicalPayment($this->tenant, $this->room);
    logManualPaymentActivity($this->tenant, $payment->booking, $this->cashier, $payment->id);

    Artisan::call('payments:backfill-collected-by');

    expect($payment->fresh()->collected_by_operator_id)->toBe($this->cashier->id);
});

it('leaves untouched payments that already carry a collector', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    $payment = Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'collected_by_operator_id' => $this->cashier->id,
    ]);

    // Stray activity row for a *different* operator — should NOT
    // overwrite the explicit collector.
    $strayOperator = Operator::factory()->for($this->tenant)->create();
    logManualPaymentActivity($this->tenant, $booking, $strayOperator, $payment->id);

    Artisan::call('payments:backfill-collected-by');

    expect($payment->fresh()->collected_by_operator_id)->toBe($this->cashier->id);
});

it('skips payments with no matching activity row', function (): void {
    $payment = historicalPayment($this->tenant, $this->room);
    // No activity created for this payment.

    Artisan::call('payments:backfill-collected-by');

    expect($payment->fresh()->collected_by_operator_id)->toBeNull();
});

it('runs as a no-op under --dry-run', function (): void {
    $payment = historicalPayment($this->tenant, $this->room);
    logManualPaymentActivity($this->tenant, $payment->booking, $this->cashier, $payment->id);

    Artisan::call('payments:backfill-collected-by', ['--dry-run' => true]);

    expect($payment->fresh()->collected_by_operator_id)->toBeNull();
});

it('is idempotent across repeated runs', function (): void {
    $payment = historicalPayment($this->tenant, $this->room);
    logManualPaymentActivity($this->tenant, $payment->booking, $this->cashier, $payment->id);

    Artisan::call('payments:backfill-collected-by');
    $firstValue = $payment->fresh()->collected_by_operator_id;
    Artisan::call('payments:backfill-collected-by');
    $secondValue = $payment->fresh()->collected_by_operator_id;

    expect($firstValue)->toBe($this->cashier->id);
    expect($secondValue)->toBe($this->cashier->id);
});
