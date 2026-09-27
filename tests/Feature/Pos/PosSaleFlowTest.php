<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Actions\Pos\VoidPosSale;
use App\Enums\BookingChargeType;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PosSaleStatus;
use App\Enums\StockMovementReason;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pos-flow']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Bar',
        'type'        => OutletType::Bar,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id'        => $this->tenant->id,
        'outlet_id'        => $this->outlet->id,
        'name'             => 'Guinness Small',
        'price'            => 1500,
        'cost'             => 800,
        'track_stock'      => true,
        'quantity_on_hand' => 20,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ------------------------------------------------------------------ */
/* Cash sale                                                           */
/* ------------------------------------------------------------------ */

it('completes a cash sale: decrements stock, creates a Succeeded Payment', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet:   $this->outlet,
        operator: $this->operator,
        cart:     [['product_id' => $this->guinness->id, 'quantity' => 2]],
    );
    expect($draft->status)->toBe(PosSaleStatus::Draft);
    expect($draft->total)->toBe(3000);

    $sale = app(CompletePosSale::class)->handle(
        sale: $draft, operator: $this->operator, method: 'cash',
    );

    expect($sale->status)->toBe(PosSaleStatus::Completed);
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(18);

    $payment = Payment::query()->where('pos_sale_id', $sale->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->type)->toBe(PaymentType::PosSale);
    expect($payment->status)->toBe(PaymentStatus::Succeeded);
    expect($payment->amount)->toBe(3000);
    expect($payment->method)->toBe('cash');
});

it('is idempotent — completing an already-completed sale is a no-op', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 1]],
    );
    app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');
    app(CompletePosSale::class)->handle($draft->fresh(), $this->operator, 'cash');

    // Only one Payment, one Sold movement, only one decrement.
    expect(Payment::query()->where('pos_sale_id', $draft->id)->count())->toBe(1);
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(19);
});

/* ------------------------------------------------------------------ */
/* Charge to room                                                      */
/* ------------------------------------------------------------------ */

it('creates a BookingCharge on the guest folio when method = room', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();

    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet:    $this->outlet,
        operator:  $this->operator,
        cart:      [['product_id' => $this->guinness->id, 'quantity' => 3]],
        bookingId: $booking->id,
    );

    app(CompletePosSale::class)->handle($draft, $this->operator, 'room');

    $charge = BookingCharge::query()->where('booking_id', $booking->id)->first();
    expect($charge)->not->toBeNull();
    expect($charge->type)->toBe(BookingChargeType::FoodBeverage);
    expect($charge->amount)->toBe(4500);
    expect($charge->settled)->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* Finance integration                                                 */
/* ------------------------------------------------------------------ */

it('POS revenue lands in the FinanceSnapshot Collected KPI', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 4]],
    );
    app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');

    $kpis = app(FinanceSnapshot::class)->kpis(
        Carbon::today()->subDay(), Carbon::today(),
    );
    expect($kpis['collected'])->toBe(6000);
});

/* ------------------------------------------------------------------ */
/* Void                                                                */
/* ------------------------------------------------------------------ */

it('void restores stock and marks the payment refunded', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 5]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(15);

    app(VoidPosSale::class)->handle($sale->fresh(), $this->operator, 'Customer cancelled');

    expect($this->guinness->fresh()->quantity_on_hand)->toBe(20);       // restored
    expect(PosSale::query()->find($sale->id)->status)->toBe(PosSaleStatus::Voided);
    $payment = Payment::query()->where('pos_sale_id', $sale->id)->first();
    expect($payment->status)->toBe(PaymentStatus::Refunded);
});

it('void deletes an unsettled charge-to-room BookingCharge', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 2]],
        bookingId: $booking->id,
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'room');
    expect(BookingCharge::query()->where('booking_id', $booking->id)->count())->toBe(1);

    app(VoidPosSale::class)->handle($sale->fresh(), $this->operator, 'Wrong room');

    // Unsettled charge removed — the guest no longer owes for the voided sale.
    expect(BookingCharge::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('void requires a reason', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 1]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');

    expect(fn () => app(VoidPosSale::class)->handle($sale->fresh(), $this->operator, ''))
        ->toThrow(InvalidArgumentException::class);
});
