<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Actions\Pos\RefundPosSaleLines;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\Pos\SaleShow;
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
    $this->tenant = Tenant::factory()->create(['slug' => 'refund-test']);
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
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('refunds one line partially: restores stock + writes a Refunded payment', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 4]], // ₦6,000
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');
    $line = $sale->lines->first();

    expect($this->guinness->fresh()->quantity_on_hand)->toBe(100 - 4);

    $return = app(RefundPosSaleLines::class)->handle(
        sale: $sale,
        operator: $this->operator,
        returns: [['sale_line_id' => $line->id, 'quantity' => 1]],
        reason: 'Guest sent one back',
        refundMethod: 'cash',
    );

    expect($return->refund_amount)->toBe(1_500);
    // Stock: 96 + 1 restored = 97
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(97);
    // Original Payment stays Succeeded.
    $succeeded = Payment::query()->where('pos_sale_id', $sale->id)->where('status', PaymentStatus::Succeeded->value)->first();
    expect($succeeded->amount)->toBe(6_000);
    // A new Refunded Payment for 1,500.
    $refund = Payment::query()->where('pos_sale_id', $sale->id)->where('status', PaymentStatus::Refunded->value)->first();
    expect($refund->amount)->toBe(1_500);
    expect($refund->method)->toBe('cash');
});

it('stops the operator from refunding more than was sold', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 2]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');
    $line = $sale->lines->first();

    expect(fn () => app(RefundPosSaleLines::class)->handle(
        sale: $sale, operator: $this->operator,
        returns: [['sale_line_id' => $line->id, 'quantity' => 99]],
        reason: 'x',
    ))->toThrow(InvalidArgumentException::class, 'still refundable');
});

it('supports multiple partial refunds against the same line', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 4]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');
    $line = $sale->lines->first();

    // Two returns: 1 then 2. Third refund of 2 more should fail (only 1 left).
    app(RefundPosSaleLines::class)->handle($sale, $this->operator, [['sale_line_id' => $line->id, 'quantity' => 1]], 'a');
    app(RefundPosSaleLines::class)->handle($sale, $this->operator, [['sale_line_id' => $line->id, 'quantity' => 2]], 'b');

    expect(fn () => app(RefundPosSaleLines::class)->handle(
        $sale, $this->operator, [['sale_line_id' => $line->id, 'quantity' => 2]], 'c',
    ))->toThrow(InvalidArgumentException::class);

    expect($this->guinness->fresh()->quantity_on_hand)->toBe(100 - 4 + 3);
});

it('charge-to-room refund reduces the BookingCharge', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();

    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 4]],
        bookingId: $booking->id,
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'room');

    $charge = BookingCharge::query()->where('booking_id', $booking->id)->first();
    expect($charge->amount)->toBe(6_000);

    // Refund 2 of 4 → BookingCharge should drop to ₦3,000.
    app(RefundPosSaleLines::class)->handle(
        sale: $sale, operator: $this->operator,
        returns: [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 2]],
        reason: 'wrong order', refundMethod: 'room',
    );

    $charge->refresh();
    expect($charge->amount)->toBe(3_000);
});

it('composite product refund restores each component per the recipe', function (): void {
    $rum = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Rum', 'price' => 500, 'track_stock' => true, 'quantity_on_hand' => 30,
    ]);
    $coke = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Coke', 'price' => 400, 'track_stock' => true, 'quantity_on_hand' => 20,
    ]);
    $cocktail = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Rum & Coke', 'price' => 1500, 'track_stock' => false,
    ]);
    $cocktail->recipeItems()->create(['tenant_id' => $this->tenant->id, 'component_product_id' => $rum->id, 'quantity' => 2]);
    $cocktail->recipeItems()->create(['tenant_id' => $this->tenant->id, 'component_product_id' => $coke->id, 'quantity' => 1]);

    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $cocktail->id, 'quantity' => 3]], // 3 drinks
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');

    expect($rum->fresh()->quantity_on_hand)->toBe(30 - 6);
    expect($coke->fresh()->quantity_on_hand)->toBe(20 - 3);

    // Refund 2 drinks → restore 4 rum + 2 coke
    app(RefundPosSaleLines::class)->handle(
        sale: $sale, operator: $this->operator,
        returns: [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 2]],
        reason: 'complaints',
    );

    expect($rum->fresh()->quantity_on_hand)->toBe(30 - 6 + 4);
    expect($coke->fresh()->quantity_on_hand)->toBe(20 - 3 + 2);
});

it('managers can trigger a refund from the sale-show component', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar, operator: $this->operator,
        cart: [['product_id' => $this->guinness->id, 'quantity' => 3]],
    );
    $sale = app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');

    Livewire\Livewire::actingAs($this->operator, 'web')
        ->test(SaleShow::class, ['sale' => $sale])
        ->call('openRefund')
        ->set('refundQty.'.$sale->lines->first()->id, 1)
        ->set('refundReason', 'test return')
        ->set('refundMethod', 'cash')
        ->call('confirmRefund')
        ->assertHasNoErrors();

    expect($sale->refresh()->returns)->toHaveCount(1);
});
