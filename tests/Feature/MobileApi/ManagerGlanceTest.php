<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PosSaleStatus;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Enums\StockRequestStatus;
use App\Models\Booking;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * /api/mobile/v1/manager/glance — single-shot KPIs + attention lists
 * for the mobile Home tab.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-glance']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
});

afterEach(fn () => Tenant::forgetCurrent());

function bearerForGlance(Operator $op): array
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

function glanceUrl(Tenant $tenant): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/manager/glance';
}

it('returns aggregated KPIs for a manager', function (): void {
    // Two arrivals today, one departure today, one in-house.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
        'total_amount' => 30_000, 'balance_due' => 0,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold->value,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
        'total_amount' => 20_000, 'balance_due' => 20_000,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn->value,
        'checkin_date' => Carbon::yesterday(),
        'checkout_date' => Carbon::today(),
        'total_amount' => 50_000, 'balance_due' => 10_000,
    ]);

    $response = $this->getJson(glanceUrl($this->tenant), bearerForGlance($this->manager));

    $response->assertOk();
    $kpis = $response->json('kpis');
    expect($kpis['arrivals_today'])->toBe(2);
    expect($kpis['departures_today'])->toBe(1);
    expect($kpis['in_house'])->toBe(1);
    expect($kpis['outstanding_total'])->toBe(30000);   // 20k + 10k
});

it('includes today POS revenue in kpis', function (): void {
    $bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    PosSale::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $bar->id,
        'operator_id' => $this->manager->id,
        'reference' => 'S-1',
        'status' => PosSaleStatus::Completed,
        'subtotal' => 15_000, 'tax_amount' => 0, 'total' => 15_000,
        'sold_at' => Carbon::now(),
    ]);
    PosSale::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $bar->id,
        'operator_id' => $this->manager->id,
        'reference' => 'S-2',
        'status' => PosSaleStatus::Completed,
        'subtotal' => 8_000, 'tax_amount' => 0, 'total' => 8_000,
        'sold_at' => Carbon::now()->subHour(),
    ]);
    // Yesterday's sale — should NOT count.
    PosSale::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $bar->id,
        'operator_id' => $this->manager->id,
        'reference' => 'S-Y',
        'status' => PosSaleStatus::Completed,
        'subtotal' => 999_999, 'tax_amount' => 0, 'total' => 999_999,
        'sold_at' => Carbon::yesterday(),
    ]);

    $response = $this->getJson(glanceUrl($this->tenant), bearerForGlance($this->manager));

    expect($response->json('kpis.today_pos_revenue'))->toBe(23000);
});

it('surfaces open + rejected inspection counts', function (): void {
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Pending, 'room_category_id' => $this->category->id,
    ]);
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Failed, 'room_category_id' => $this->category->id,
    ]);
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Passed, 'room_category_id' => $this->category->id,
    ]);

    $response = $this->getJson(glanceUrl($this->tenant), bearerForGlance($this->manager));

    expect($response->json('kpis.open_inspections'))->toBe(1);   // Pending only
    expect($response->json('kpis.rejected_inspections'))->toBe(1); // Failed
});

it('includes pending stock requests in the attention list', function (): void {
    $store = Store::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Central store', 'slug' => 'central', 'active' => true,
    ]);
    $bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $product = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $store->id,
        'name' => 'Guinness', 'sku' => 'GUIN',
        'price' => 0, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 100, 'active' => true,
    ]);
    StockRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $bar->id,
        'product_id' => $product->id, 'quantity' => 24,
        'status' => StockRequestStatus::Pending,
    ]);

    $response = $this->getJson(glanceUrl($this->tenant), bearerForGlance($this->manager));

    expect($response->json('kpis.pending_stock_requests'))->toBe(1);
    $list = $response->json('attention.pending_stock_requests');
    expect($list)->toHaveCount(1);
    expect($list[0]['outlet_name'])->toBe('Bar');
    expect($list[0]['product_name'])->toBe('Guinness');
});

it('refuses operators whose role isn\'t manager / frontdesk / reservation officer', function (): void {
    $response = $this->getJson(glanceUrl($this->tenant), bearerForGlance($this->housekeeper));

    $response->assertStatus(403)->assertJson(['error' => 'forbidden']);
});

it('lets a frontdesk operator through', function (): void {
    $frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);

    $response = $this->getJson(glanceUrl($this->tenant), bearerForGlance($frontdesk));

    $response->assertOk();
});
