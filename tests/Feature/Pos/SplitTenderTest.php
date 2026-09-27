<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OutletType;
use App\Enums\PaymentType;
use App\Enums\PosSaleStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'split-tender']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 100,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(fn () => Tenant::forgetCurrent());

it('splits a sale across cash and charge-to-room', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();

    // 4 × 1,500 = 6,000. Split: 2,000 cash + 4,000 room.
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar,
        operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 4]],
        bookingId: $booking->id,
    );

    $sale = app(CompletePosSale::class)->handleTenders(
        sale: $draft,
        operator: $this->operator,
        tenders: [
            ['method' => 'cash', 'amount' => 2_000],
            ['method' => 'room', 'amount' => 4_000],
        ],
    );

    expect($sale->status)->toBe(PosSaleStatus::Completed);
    // Two Payment rows, one per tender.
    $payments = Payment::query()->where('pos_sale_id', $sale->id)->get();
    expect($payments)->toHaveCount(2);
    expect($payments->pluck('type')->unique()->all())->toBe([PaymentType::PosSale]);
    expect($payments->pluck('method')->sort()->values()->all())->toBe(['cash', 'room']);
    expect($payments->sum('amount'))->toBe(6_000);

    // One BookingCharge only for the room portion.
    $charges = BookingCharge::query()->where('booking_id', $booking->id)->get();
    expect($charges)->toHaveCount(1);
    expect($charges->first()->amount)->toBe(4_000);
});

it('rejects tender sums that miss the sale total', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 2]], // ₦3,000
    );

    expect(fn () => app(CompletePosSale::class)->handleTenders(
        sale: $draft, operator: $this->operator,
        tenders: [
            ['method' => 'cash',     'amount' => 1_000],
            ['method' => 'transfer', 'amount' => 1_500], // sum 2,500 ≠ 3,000
        ],
    ))->toThrow(InvalidArgumentException::class, "doesn't match sale total");
});

it('rejects multiple room tenders on the same sale', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 4]],
        bookingId: $booking->id,
    );

    expect(fn () => app(CompletePosSale::class)->handleTenders(
        sale: $draft, operator: $this->operator,
        tenders: [
            ['method' => 'room', 'amount' => 3_000],
            ['method' => 'room', 'amount' => 3_000],
        ],
    ))->toThrow(InvalidArgumentException::class, 'Only one charge-to-room tender');
});

it('allows cash + transfer + card triple-split', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 6]], // ₦9,000
    );

    $sale = app(CompletePosSale::class)->handleTenders(
        sale: $draft, operator: $this->operator,
        tenders: [
            ['method' => 'cash',     'amount' => 3_000],
            ['method' => 'transfer', 'amount' => 4_000],
            ['method' => 'card',     'amount' => 2_000],
        ],
    );

    expect($sale->status)->toBe(PosSaleStatus::Completed);
    expect(Payment::query()->where('pos_sale_id', $sale->id)->count())->toBe(3);
    // Charge-to-room path not triggered → no BookingCharge.
    expect(BookingCharge::query()->count())->toBe(0);
});

it('single-method handle still works (backward compat)', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 1]],
    );

    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');

    expect($sale->status)->toBe(PosSaleStatus::Completed);
    expect(Payment::query()->where('pos_sale_id', $sale->id)->count())->toBe(1);
});
