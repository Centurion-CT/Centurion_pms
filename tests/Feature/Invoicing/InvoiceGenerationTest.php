<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreateInvoice;
use App\Actions\Invoicing\MarkInvoicePaid;
use App\Actions\Invoicing\SuggestInvoiceLines;
use App\Actions\Invoicing\VoidInvoice;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'inv-test',
        'paystack_bank_name'      => 'GTBank',
        'paystack_account_name'   => 'Maitama Suites Ltd',
        'paystack_account_number' => '0123456789',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('suggests a room line + one line per unsettled charge from a booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'nights' => 3,
        'room_total' => 90_000,
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => 'room_service',
        'description' => 'Bar tab · night 2',
        'amount' => 8_000,
        'settled' => false,
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => 'room_service',
        'description' => 'Bar tab · night 1 (already paid)',
        'amount' => 5_000,
        'settled' => true,  // must NOT appear
    ]);

    $lines = app(SuggestInvoiceLines::class)->handle($booking->fresh(['charges', 'selectedPackages', 'room']));

    expect($lines)->toHaveCount(2);
    expect($lines[0]['line_type'])->toBe(InvoiceLineType::Night->value);
    expect($lines[0]['quantity'])->toBe(3);
    expect($lines[0]['line_total'])->toBe(90_000);
    expect($lines[1]['line_type'])->toBe(InvoiceLineType::Charge->value);
    expect($lines[1]['description'])->toBe('Bar tab · night 2');
    expect($lines[1]['line_total'])->toBe(8_000);
});

it('CreateInvoice allocates a per-tenant sequence + snapshots bank details', function (): void {
    Carbon::setTestNow('2026-07-18 10:00:00');
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Amara Okafor',
        'guest_email' => 'amara@example.test',
    ]);

    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        lines: [[
            'line_type' => 'night',
            'description' => 'Room accommodation',
            'quantity' => 2, 'unit_price' => 45_000,
        ]],
        notes: 'Payment on checkout.',
    );

    expect($invoice->sequence)->toBe(1);
    expect($invoice->number)->toBe('INV-2026-000001');
    expect($invoice->status)->toBe(InvoiceStatus::Issued);
    expect($invoice->subtotal)->toBe(90_000);
    expect($invoice->total)->toBe(90_000);
    expect($invoice->bank_name)->toBe('GTBank');
    expect($invoice->bank_account_number)->toBe('0123456789');
    expect($invoice->guest_name)->toBe('Amara Okafor');
    expect($invoice->lines)->toHaveCount(1);
});

it('sequence is monotonic per tenant', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();

    $one = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 10_000]],
    );
    $two = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'B', 'quantity' => 1, 'unit_price' => 10_000]],
    );

    expect($one->sequence)->toBe(1);
    expect($two->sequence)->toBe(2);
    expect($one->number)->not->toBe($two->number);
});

it('a different tenant restarts its own sequence at 1', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 10_000]],
    );

    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'other-inv']);
    $other->makeCurrent();
    $otherProperty = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProperty)->create();
    $otherOp = Operator::factory()->for($other)->create();
    $otherBooking = Booking::factory()->for($other)->for($otherRoom)->create();

    $otherInvoice = app(CreateInvoice::class)->handle(
        $other, $otherBooking, $otherOp,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 10_000]],
    );

    expect($otherInvoice->sequence)->toBe(1);
});

it('refuses to issue an invoice with no lines', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();

    expect(fn () => app(CreateInvoice::class)->handle($this->tenant, $booking, $this->operator, []))
        ->toThrow(InvalidArgumentException::class);
});

it('MarkInvoicePaid flips status + bumps amount_paid to total', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 25_000]],
    );

    app(MarkInvoicePaid::class)->handle($invoice, $this->operator);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Paid);
    expect($fresh->amount_paid)->toBe(25_000);
    expect($fresh->paid_at)->not->toBeNull();
});

it('MarkInvoicePaid refuses to touch a void invoice', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 25_000]],
    );
    app(VoidInvoice::class)->handle($invoice, $this->operator);

    expect(fn () => app(MarkInvoicePaid::class)->handle($invoice->fresh(), $this->operator))
        ->toThrow(RuntimeException::class);
});

it('VoidInvoice refuses to void a paid invoice', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $invoice = app(CreateInvoice::class)->handle(
        $this->tenant, $booking, $this->operator,
        [['line_type' => 'night', 'description' => 'A', 'quantity' => 1, 'unit_price' => 25_000]],
    );
    app(MarkInvoicePaid::class)->handle($invoice, $this->operator);

    expect(fn () => app(VoidInvoice::class)->handle($invoice->fresh(), $this->operator))
        ->toThrow(RuntimeException::class);
});

it('Invoice::balanceOwed subtracts amount_paid from total, floored at zero', function (): void {
    $inv = Invoice::query()->create([
        'tenant_id' => $this->tenant->id,
        'status' => InvoiceStatus::Issued,
        'subtotal' => 50_000, 'amount_paid' => 20_000, 'total' => 50_000,
    ]);
    expect($inv->balanceOwed())->toBe(30_000);

    $inv->forceFill(['amount_paid' => 60_000])->save();
    expect($inv->fresh()->balanceOwed())->toBe(0);
});
