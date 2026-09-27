<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\BookingStatus;
use App\Enums\CreditLineStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PosSaleStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Pos\Terminal as PosTerminal;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\CreditLine;
use App\Models\CreditLineCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * POS "charge to room" can now settle against a credit line as well
 * as a booking folio. This suite exercises the routing on the
 * complete-sale action + the Terminal picker.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pos-cl']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => '306',
        'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    // Outlet + one product for the sale.
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Rooftop Bar',
        'slug' => 'rooftop-bar',
        'type' => OutletType::Bar->value,
        'tax_rate' => 0,
        'active' => true,
    ]);
    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'outlet_id' => $this->outlet->id,
        'name' => 'Cocktail',
        'price' => 5_000,
        'active' => true,
        'track_stock' => false,
    ]);

    $this->customer = Customer::create([
        'name' => 'Ada Okoye', 'phone' => '08012345678',
        'email' => 'ada@x.test', 'lifecycle' => CustomerLifecycle::Active,
    ]);
    $this->line = CreditLine::query()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'name' => 'CEO tab',
        'status' => CreditLineStatus::Active,
    ]);
    $this->line->rooms()->sync([$this->room->id]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Action layer ─────────────────────────────────────────────── */

it('room tender on a credit-line sale creates a CreditLineCharge (not a BookingCharge)', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->manager,
        cart: [['product_id' => $this->product->id, 'quantity' => 3]],
        creditLineId: $this->line->id,
    );

    app(CompletePosSale::class)->handleTenders(
        sale: $draft,
        operator: $this->manager,
        tenders: [['method' => 'room', 'amount' => 15_000]],
    );

    expect(CreditLineCharge::query()->count())->toBe(1);
    expect(BookingCharge::query()->count())->toBe(0);

    $charge = CreditLineCharge::query()->first();
    expect((int) $charge->amount)->toBe(15_000);
    expect($charge->credit_line_id)->toBe($this->line->id);
    expect($this->line->refresh()->outstandingTotal())->toBe(15_000);
});

it('room tender on a booking sale still creates a BookingCharge (unchanged)', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => \Illuminate\Support\Carbon::today()->subDay(),
        'checkout_date' => \Illuminate\Support\Carbon::today()->addDay(),
    ]);

    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->manager,
        cart: [['product_id' => $this->product->id, 'quantity' => 2]],
        bookingId: $booking->id,
    );

    app(CompletePosSale::class)->handleTenders(
        sale: $draft,
        operator: $this->manager,
        tenders: [['method' => 'room', 'amount' => 10_000]],
    );

    expect(BookingCharge::query()->count())->toBe(1);
    expect(CreditLineCharge::query()->count())->toBe(0);
});

it('CreatePosSaleDraft refuses when both booking_id and credit_line_id are set', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
    ]);

    expect(fn () => app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->manager,
        cart: [['product_id' => $this->product->id, 'quantity' => 1]],
        bookingId: $booking->id,
        creditLineId: $this->line->id,
    ))->toThrow(InvalidArgumentException::class, 'not both');
});

it('CompletePosSale refuses a room tender when neither anchor is set', function (): void {
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->manager,
        cart: [['product_id' => $this->product->id, 'quantity' => 1]],
    );

    expect(fn () => app(CompletePosSale::class)->handleTenders(
        sale: $draft,
        operator: $this->manager,
        tenders: [['method' => 'room', 'amount' => 5_000]],
    ))->toThrow(InvalidArgumentException::class, 'booking OR a credit line');
});

it('CompletePosSale refuses a room tender when the credit line is Suspended', function (): void {
    $this->line->forceFill(['status' => CreditLineStatus::Suspended])->save();
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->outlet,
        operator: $this->manager,
        cart: [['product_id' => $this->product->id, 'quantity' => 1]],
        creditLineId: $this->line->id,
    );

    expect(fn () => app(CompletePosSale::class)->handleTenders(
        sale: $draft,
        operator: $this->manager,
        tenders: [['method' => 'room', 'amount' => 5_000]],
    ))->toThrow(InvalidArgumentException::class, 'can\'t accept new charges');
});

/* ─── Terminal Livewire ────────────────────────────────────────── */

it('pickCreditLine sets resolved state + clears any prior booking selection', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PosTerminal::class, ['outlet' => $this->outlet])
        ->set('bookingIdResolved', 999)     // pretend a booking was previously picked
        ->set('bookingRef', 'KV-OLD')
        ->call('pickCreditLine', $this->line->id)
        ->assertSet('creditLineIdResolved', $this->line->id)
        ->assertSet('creditLineNameResolved', 'CEO tab')
        ->assertSet('bookingIdResolved', null)
        ->assertSet('bookingRef', '')
        ->assertDispatched('pos-credit-line-resolved');
});

it('pickCreditLine refuses a Closed line with an inline error', function (): void {
    $this->line->forceFill(['status' => CreditLineStatus::Closed])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(PosTerminal::class, ['outlet' => $this->outlet])
        ->call('pickCreditLine', $this->line->id)
        ->assertSet('creditLineIdResolved', null)
        ->assertSet('creditLineLookupError', fn ($v) => str_contains($v, 'Closed'));
});
