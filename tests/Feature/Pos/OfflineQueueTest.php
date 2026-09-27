<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PosSaleStatus;
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

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'offline-pos']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('heartbeat endpoint returns 200 for authenticated operators', function (): void {
    $this->actingAs($this->manager, 'web');
    $this->get('/dashboard/pos/heartbeat')->assertOk()->assertSee('ok');
});

it('flushes a queued cash sale via the offline queue endpoint', function (): void {
    $this->actingAs($this->manager, 'web');

    $response = $this->postJson('/dashboard/pos/queue', [
        'client_key' => 'test-key-1',
        'outlet_id'  => $this->bar->id,
        'cart'       => [['product_id' => $this->guinness->id, 'quantity' => 2]],
        'tenders'    => [['method' => 'cash', 'amount' => 3_000]],
        'notes'      => 'Queued during outage',
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'client_key' => 'test-key-1']);

    $sale = PosSale::query()->latest('id')->first();
    expect($sale->status)->toBe(PosSaleStatus::Completed);
    expect($sale->total)->toBe(3_000);
    expect(Payment::query()->where('pos_sale_id', $sale->id)->where('status', PaymentStatus::Succeeded->value)->count())->toBe(1);
    expect($this->guinness->fresh()->quantity_on_hand)->toBe(18);
});

it('flushes a queued charge-to-room sale, creating the BookingCharge', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $booking = Booking::factory()->for($this->tenant)->for($room)->confirmed()->create();
    $this->actingAs($this->manager, 'web');

    $this->postJson('/dashboard/pos/queue', [
        'client_key'   => 'test-key-2',
        'outlet_id'    => $this->bar->id,
        'cart'         => [['product_id' => $this->guinness->id, 'quantity' => 1]],
        'tenders'      => [['method' => 'room', 'amount' => 1_500]],
        'booking_ref'  => $booking->booking_ref,
    ])->assertOk();

    expect(BookingCharge::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('rejects a queued sale for an outlet the pos-staff is not assigned to', function (): void {
    $posStaff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
    // No outlet assignment.

    $this->actingAs($posStaff, 'web');

    $this->postJson('/dashboard/pos/queue', [
        'client_key' => 'test-key-3',
        'outlet_id'  => $this->bar->id,
        'cart'       => [['product_id' => $this->guinness->id, 'quantity' => 1]],
        'tenders'    => [['method' => 'cash', 'amount' => 1_500]],
    ])->assertStatus(403);

    expect(PosSale::query()->count())->toBe(0);
});

it('rejects a queued sale with a missing booking_ref when a room tender is present', function (): void {
    $this->actingAs($this->manager, 'web');

    $this->postJson('/dashboard/pos/queue', [
        'client_key' => 'test-key-4',
        'outlet_id'  => $this->bar->id,
        'cart'       => [['product_id' => $this->guinness->id, 'quantity' => 2]],
        'tenders'    => [['method' => 'room', 'amount' => 3_000]],
    ])->assertStatus(422)->assertJsonPath('error', 'booking_ref_required');
});

it('rejects a queued sale whose tender amounts do not match cart total', function (): void {
    $this->actingAs($this->manager, 'web');

    $response = $this->postJson('/dashboard/pos/queue', [
        'client_key' => 'test-key-5',
        'outlet_id'  => $this->bar->id,
        'cart'       => [['product_id' => $this->guinness->id, 'quantity' => 2]], // ₦3,000
        'tenders'    => [['method' => 'cash', 'amount' => 999]],
    ]);
    // handleTenders throws → server_error 422.
    $response->assertStatus(422)->assertJsonPath('ok', false);
});

it('unauthenticated queue POST is rejected with 401', function (): void {
    $this->postJson('/dashboard/pos/queue', [
        'client_key' => 'test-key-6',
        'outlet_id'  => $this->bar->id,
        'cart'       => [['product_id' => $this->guinness->id, 'quantity' => 1]],
        'tenders'    => [['method' => 'cash', 'amount' => 1_500]],
    ])->assertStatus(401);
});
