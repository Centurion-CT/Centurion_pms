<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PosSaleStatus;
use App\Models\Booking;
use App\Models\HousekeepingStatus;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * /api/mobile/v1/outlets + /outlets/{id}/products + /outlets/{id}/sales.
 * Every write delegates to CreatePosSaleDraft + CompletePosSale so
 * this suite mostly asserts the HTTP contract + access gates +
 * idempotency behaviour.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-pos']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();

    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Kitchen', 'type' => OutletType::Restaurant,
    ]);

    $this->beer = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness Small', 'sku' => 'GUIN-SM',
        'barcode' => '5000213024300',
        'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
    ]);
    $this->wine = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'House Red',
        'price' => 4000, 'cost' => 2000,
        'track_stock' => true, 'quantity_on_hand' => 10,
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->staff = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::PosStaff]);
    $this->bar->operators()->attach($this->staff->id);
});

afterEach(fn () => Tenant::forgetCurrent());

function bearerForOperator(Operator $op): array
{
    $device = MobileDevice::query()->create([
        'tenant_id' => $op->tenant_id,
        'operator_id' => $op->id,
        'platform' => 'ios',
        'device_name' => 'T',
        'app_version' => '1.0.0',
        'paired_at' => now(),
        'last_seen_at' => now(),
    ]);
    $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function posUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').$path;
}

it('outlets index returns every active outlet for managers', function (): void {
    $response = $this->getJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets'),
        bearerForOperator($this->manager),
    );

    $response->assertOk();
    expect(count($response->json('data')))->toBe(2);
});

it('outlets index for PosStaff filters to attached outlets only', function (): void {
    $response = $this->getJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets'),
        bearerForOperator($this->staff),
    );

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Bar']);   // Kitchen is not attached
});

it('products search matches on barcode when the ?barcode= param is passed', function (): void {
    $response = $this->getJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/products?barcode=5000213024300'),
        bearerForOperator($this->manager),
    );

    $response->assertOk();
    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.name'))->toBe('Guinness Small');
});

it('products search matches on name substring via ?q=', function (): void {
    $response = $this->getJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/products?q=guin'),
        bearerForOperator($this->manager),
    );

    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.name'))->toBe('Guinness Small');
});

it('createSale — one-shot cart + tender POST persists a Completed sale', function (): void {
    $response = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/sales'),
        [
            'client_key' => 'sale-abc-12345',
            'cart' => [
                ['product_id' => $this->beer->id, 'quantity' => 2],
                ['product_id' => $this->wine->id, 'quantity' => 1],
            ],
            'tenders' => [
                ['method' => 'cash', 'amount' => 7000, 'reference_note' => 'Table 3'],
            ],
        ],
        array_merge(bearerForOperator($this->manager), ['Idempotency-Key' => 'sale-idempot-1']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($response->json('sale.status'))->toBe(PosSaleStatus::Completed->value);
    expect($response->json('sale.total'))->toBe(7000);
    expect(count($response->json('sale.lines')))->toBe(2);
});

it('createSale with a duplicate client_key returns the same sale (idempotent replay)', function (): void {
    $body = [
        'client_key' => 'duplicate-key-xyz',
        'cart' => [['product_id' => $this->beer->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 1500]],
    ];

    $first = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/sales'),
        $body,
        array_merge(bearerForOperator($this->manager), ['Idempotency-Key' => 'dup-first-attempt']),
    );
    $first->assertOk();
    $ref = $first->json('sale.reference');

    // Second POST with the SAME client_key but DIFFERENT
    // Idempotency-Key: MobileIdempotency middleware won't
    // short-circuit (different keys), so the controller-level
    // client_key dedup is what saves us.
    $second = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/sales'),
        $body,
        array_merge(bearerForOperator($this->manager), ['Idempotency-Key' => 'dup-second-attempt']),
    );
    $second->assertOk();
    expect($second->json('sale.reference'))->toBe($ref);
    expect(PosSale::query()->count())->toBe(1);
});

it('createSale rejects PosStaff hitting an outlet they are not attached to', function (): void {
    $response = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->kitchen->id.'/sales'),
        [
            'client_key' => 'unauth-key-abc123',
            'cart' => [['product_id' => $this->beer->id, 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amount' => 1500]],
        ],
        array_merge(bearerForOperator($this->staff), ['Idempotency-Key' => 'blocked-1']),
    );

    $response->assertStatus(403)->assertJson(['error' => 'outlet_forbidden']);
    expect(PosSale::query()->count())->toBe(0);
});

it('createSale with an unknown booking_ref returns 422', function (): void {
    $response = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/sales'),
        [
            'client_key' => 'unknown-booking-key',
            'booking_ref' => 'KV-DOES-NOT-EXIST',
            'cart' => [['product_id' => $this->beer->id, 'quantity' => 1]],
            'tenders' => [['method' => 'room', 'amount' => 1500]],
        ],
        array_merge(bearerForOperator($this->manager), ['Idempotency-Key' => 'unknown-b']),
    );

    $response->assertStatus(422)->assertJson(['error' => 'booking_not_found']);
});

it('createSale can charge to an in-house booking room', function (): void {
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $cat->id,
    ]);
    $booking = Booking::factory()->for($this->tenant)->for($room)->create([
        'booking_status' => \App\Enums\BookingStatus::CheckedIn->value,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::tomorrow(),
    ]);

    $response = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/sales'),
        [
            'client_key' => 'room-charge-abc',
            'booking_ref' => $booking->booking_ref,
            'cart' => [['product_id' => $this->beer->id, 'quantity' => 2]],
            'tenders' => [['method' => 'room', 'amount' => 3000]],
        ],
        array_merge(bearerForOperator($this->manager), ['Idempotency-Key' => 'room-charge-1']),
    );

    $response->assertOk();
    $sale = PosSale::query()->first();
    expect($sale->booking_id)->toBe($booking->id);
});

it('showSale returns the receipt when the caller has outlet access', function (): void {
    $create = $this->postJson(
        posUrl($this->tenant, '/api/mobile/v1/outlets/'.$this->bar->id.'/sales'),
        [
            'client_key' => 'receipt-abc-key',
            'cart' => [['product_id' => $this->beer->id, 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amount' => 1500]],
        ],
        array_merge(bearerForOperator($this->manager), ['Idempotency-Key' => 'receipt-1']),
    );
    $ref = $create->json('sale.reference');

    $get = $this->getJson(
        posUrl($this->tenant, '/api/mobile/v1/sales/'.$ref),
        bearerForOperator($this->manager),
    );

    $get->assertOk();
    expect($get->json('data.reference'))->toBe($ref);
});
