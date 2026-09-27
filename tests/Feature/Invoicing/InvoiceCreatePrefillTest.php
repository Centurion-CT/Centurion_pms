<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Invoices\Create as InvoiceCreate;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'inv-prefill']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('prefills depositApplied from succeeded payments when guest paid in full', function (): void {
    // Regression: the invoice composer used to read only
    // booking.deposit_amount, so a walk-in who paid the full total at
    // check-in (deposit_amount = 0) still saw "Already paid: 0" and
    // was invoiced for a balance that had already been collected.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 50_000,
        'deposit_amount' => 0,
        'balance_due' => 0,
        'nights' => 1,
        'room_total' => 50_000,
    ]);
    Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 50_000,
        'type' => PaymentType::Full,
        'method' => 'cash',
    ]);

    Livewire::test(InvoiceCreate::class, ['bookingRef' => $booking->booking_ref])
        ->assertSet('depositApplied', 50_000);
});

it('subtracts refunded amounts from the prefill', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 100_000, 'deposit_amount' => 0, 'balance_due' => 0,
        'nights' => 2, 'room_total' => 100_000,
    ]);
    Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 100_000, 'type' => PaymentType::Full, 'method' => 'cash',
    ]);
    Payment::factory()->for($this->tenant)->for($booking)->create([
        'amount' => 30_000,
        'type' => PaymentType::Full,
        'status' => PaymentStatus::Refunded,
        'paid_at' => Carbon::now(),
    ]);

    Livewire::test(InvoiceCreate::class, ['bookingRef' => $booking->booking_ref])
        ->assertSet('depositApplied', 70_000);
});

it('still prefills the deposit-path amount when nothing has cleared yet on the ledger', function (): void {
    // OnHold booking: guest handed over a deposit but no Payment row
    // beyond that. depositApplied should reflect what's been paid
    // (which is deposit_amount tracked via the Payment ledger).
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'total_amount' => 80_000, 'deposit_amount' => 30_000, 'balance_due' => 50_000,
        'nights' => 2, 'room_total' => 80_000,
    ]);
    Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 30_000, 'type' => PaymentType::Deposit, 'method' => 'cash',
    ]);

    Livewire::test(InvoiceCreate::class, ['bookingRef' => $booking->booking_ref])
        ->assertSet('depositApplied', 30_000);
});

it('prefills a Corporate rate line when the booking carries a stamped discount', function (): void {
    // Regression: previously the invoice composer only synthesised
    // room / package / charge lines. A corporate booking's rate
    // saving lived on booking.corporate_discount_naira but never
    // made it onto the invoice, so the customer saw the pre-discount
    // total. The composer now emits a negative line for the saving
    // so the invoice mirrors what the corp actually owes.
    $corp = \App\Models\Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => \App\Enums\CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
    ]);
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Corp Guest',
        'total_amount' => 90_000, 'balance_due' => 90_000,
        'nights' => 2, 'room_total' => 100_000,
        'corporate_id' => $corp->id,
        'corporate_discount_naira' => 10_000,
    ]);

    $lines = Livewire::test(InvoiceCreate::class, ['bookingRef' => $booking->booking_ref])
        ->get('lines');

    $corpLine = collect($lines)->first(fn ($l) => str_contains((string) $l['description'], 'Corporate rate'));
    expect($corpLine)->not->toBeNull();
    expect((int) $corpLine['unit_price'])->toBe(-10_000);
    expect((int) $corpLine['line_total'])->toBe(-10_000);
    expect($corpLine['description'])->toContain('Acme Ltd');
});

it('invoice show renders the corporate name in Bill to for a corp booking', function (): void {
    $corp = \App\Models\Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => \App\Enums\CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'billing_email' => 'billing@acme.test',
        'primary_phone' => '+2348012345000',
        'country' => 'NG',
        'tax_id' => 'TX-12345',
    ]);
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Corp Guest',
        'guest_phone' => '+2348011111111',
        'total_amount' => 100_000, 'balance_due' => 100_000,
        'nights' => 2, 'room_total' => 100_000,
        'corporate_id' => $corp->id,
    ]);
    $invoice = app(\App\Actions\Invoicing\CreateInvoice::class)->handle(
        tenant: $this->tenant,
        booking: $booking->fresh(),
        operator: $this->operator,
        lines: app(\App\Actions\Invoicing\SuggestInvoiceLines::class)->handle($booking->fresh()),
        notes: null, dueAt: null, issue: true, initialAmountPaid: 0,
    );

    Livewire::test(\App\Livewire\Dashboard\Invoices\Show::class, ['invoice' => $invoice->id])
        ->assertSee('data-testid="invoice-bill-to-corp"', false)
        ->assertSee('Acme Ltd')
        ->assertSee('billing@acme.test')
        ->assertSee('TX-12345')
        // Guest still shown for reference under the corp block.
        ->assertSee('Corp Guest');
});

it('invoice show falls back to guest name in Bill to for a non-corp booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Regular Walkin',
        'guest_email' => 'walkin@example.test',
        'total_amount' => 50_000, 'balance_due' => 0,
        'nights' => 1, 'room_total' => 50_000,
    ]);
    $invoice = app(\App\Actions\Invoicing\CreateInvoice::class)->handle(
        tenant: $this->tenant,
        booking: $booking->fresh(),
        operator: $this->operator,
        lines: app(\App\Actions\Invoicing\SuggestInvoiceLines::class)->handle($booking->fresh()),
        notes: null, dueAt: null, issue: true, initialAmountPaid: 0,
    );

    Livewire::test(\App\Livewire\Dashboard\Invoices\Show::class, ['invoice' => $invoice->id])
        ->assertDontSee('data-testid="invoice-bill-to-corp"', false)
        ->assertSee('Regular Walkin')
        ->assertSee('walkin@example.test');
});

it('regenerate re-runs SuggestInvoiceLines against the current booking state', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 50_000, 'balance_due' => 50_000,
        'nights' => 1, 'room_total' => 50_000,
    ]);

    $component = Livewire::test(InvoiceCreate::class, ['bookingRef' => $booking->booking_ref]);
    $lineCountBefore = count($component->get('lines'));

    // Add a charge to the folio AFTER the composer opened. Regenerate
    // must pick it up without a page reload.
    \App\Models\BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'type' => \App\Enums\BookingChargeType::FoodBeverage->value,
        'description' => 'Rooftop bar tab',
        'amount' => 12_000, 'settled' => false,
    ]);

    $component->call('regenerate');
    expect(count($component->get('lines')))->toBe($lineCountBefore + 1);
});
