<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Invoicing\MarkInvoicePaid;
use App\Actions\Invoicing\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Invoices\Index as InvoicesIndex;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'follow-up-t']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ---------- follow-up 1: auto-settle charges on invoice paid ---------- */

it('flips referenced BookingCharges to settled when MarkInvoicePaid runs', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $barCharge = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => 'room_service', 'description' => 'Bar tab',
        'amount' => 8_000, 'settled' => false,
    ]);
    $laundryCharge = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => 'laundry', 'description' => 'Laundry',
        'amount' => 3_500, 'settled' => false,
    ]);

    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [
            ['line_type' => 'night', 'description' => 'Room', 'quantity' => 1, 'unit_price' => 40_000],
            ['line_type' => 'charge', 'source_type' => BookingCharge::class, 'source_id' => $barCharge->id,
             'description' => 'Bar tab', 'quantity' => 1, 'unit_price' => 8_000],
        ]
    );

    app(MarkInvoicePaid::class)->handle($invoice, $this->operator);

    // Bar tab was on the invoice → auto-settled.
    expect($barCharge->fresh()->settled)->toBeTrue();
    expect($barCharge->fresh()->settled_method)->toContain('invoice:INV-');
    // Laundry was NOT on the invoice → left untouched.
    expect($laundryCharge->fresh()->settled)->toBeFalse();
});

/* ---------- follow-up 2: reconcile Payments against invoices ---------- */

it('RecordInvoicePayment creates a Payment row + bumps invoice.amount_paid', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'Room', 'quantity' => 2, 'unit_price' => 30_000]],
    );

    $payment = app(RecordInvoicePayment::class)->handle(
        $invoice, $this->operator, amount: 20_000, method: 'cash', referenceNote: 'front desk',
    );

    expect($payment->type)->toBe(PaymentType::Invoice);
    expect($payment->method)->toBe('cash');
    expect($payment->invoice_id)->toBe($invoice->id);
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    expect(str_starts_with($payment->paystack_reference, 'manual-'))->toBeTrue();

    $fresh = $invoice->fresh();
    expect($fresh->amount_paid)->toBe(20_000);
    // Partial payment flips status to Partial (was Issued before
    // InvoiceStatus::Partial was introduced) — the balance is still
    // outstanding on receivables, just no longer untouched.
    expect($fresh->status)->toBe(InvoiceStatus::Partial);
    expect($fresh->balanceOwed())->toBe(40_000);
});

it('auto-flips invoice to Paid + settles charges once total is fully recorded', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $charge = BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => 'room_service', 'description' => 'Bar tab',
        'amount' => 5_000, 'settled' => false,
    ]);
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [
            ['line_type' => 'charge', 'source_type' => BookingCharge::class, 'source_id' => $charge->id,
             'description' => 'Bar tab', 'quantity' => 1, 'unit_price' => 5_000],
        ]
    );

    app(RecordInvoicePayment::class)->handle($invoice, $this->operator, 2_000, 'cash');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Partial);
    expect($charge->fresh()->settled)->toBeFalse();

    app(RecordInvoicePayment::class)->handle($invoice->fresh(), $this->operator, 3_000, 'cash');
    $final = $invoice->fresh();
    expect($final->status)->toBe(InvoiceStatus::Paid);
    expect($final->amount_paid)->toBe(5_000);
    expect($final->paid_at)->not->toBeNull();
    expect($charge->fresh()->settled)->toBeTrue();
});

it('refuses to record a payment against a void or draft invoice', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'Room', 'quantity' => 1, 'unit_price' => 30_000]],
        issue: false,
    );

    expect(fn () => app(RecordInvoicePayment::class)->handle($invoice, $this->operator, 5_000, 'cash'))
        ->toThrow(\RuntimeException::class);
});

it('rejects non-positive payment amounts', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'Room', 'quantity' => 1, 'unit_price' => 30_000]],
    );

    expect(fn () => app(RecordInvoicePayment::class)->handle($invoice, $this->operator, 0, 'cash'))
        ->toThrow(\InvalidArgumentException::class);
});

/* ---------- follow-up 3: tenant-wide invoices index ---------- */

it('lists every invoice for the current tenant', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_name' => 'Amara']);
    app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 10_000]],
    );
    app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'B', 'quantity' => 1, 'unit_price' => 20_000]],
    );

    Livewire::actingAs($this->operator, 'web')
        ->test(InvoicesIndex::class)
        ->assertSee('INV-')
        ->assertSee('Amara');
});

it('index filters by status', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $issued = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 10_000]],
    );
    $paid = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'B', 'quantity' => 1, 'unit_price' => 20_000]],
    );
    app(MarkInvoicePaid::class)->handle($paid, $this->operator);

    Livewire::actingAs($this->operator, 'web')
        ->test(InvoicesIndex::class)
        ->set('statusFilter', 'paid')
        ->assertSee($paid->number)
        ->assertDontSee($issued->number);
});

it('index does not leak invoices from other tenants', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 10_000]],
    );

    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'other-idx']);
    $other->makeCurrent();
    $otherProp = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    $otherOp = Operator::factory()->for($other)->create();
    $otherBooking = Booking::factory()->for($other)->for($otherRoom)->create(['guest_name' => 'Other Guest']);
    $otherInvoice = app(CreateInvoice::class)->handle(
        $other, $otherBooking, $otherOp,
        [['line_type' => 'night', 'description' => 'X', 'quantity' => 1, 'unit_price' => 99_000]],
    );

    // Rendering as the OTHER tenant's operator should NOT surface the
    // first tenant's invoices — BelongsToTenant global scope enforces
    // this at the query level; the test is defense in depth.
    Livewire::actingAs($otherOp, 'web')
        ->test(InvoicesIndex::class)
        ->assertSee($otherInvoice->number)
        ->assertSee('Other Guest');

    // Meanwhile the total count for the other tenant is 1 (its own),
    // not 2 (both).
    expect(Invoice::query()->count())->toBe(1);
});
