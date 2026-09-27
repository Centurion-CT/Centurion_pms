<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Mail\Inventory\LowStockDigestMail;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

it('emails managers a digest of low-stock products', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'digest-a']);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $bar = Outlet::query()->create([
        'tenant_id' => $tenant->id, 'property_id' => $property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    // Two low + one ok + one non-tracked.
    Product::query()->create([
        'tenant_id' => $tenant->id, 'outlet_id' => $bar->id,
        'name' => 'Guinness', 'sku' => 'G-01', 'price' => 1500, 'cost' => 800,
        'track_stock' => true, 'quantity_on_hand' => 2, 'low_stock_threshold' => 5,
    ]);
    Product::query()->create([
        'tenant_id' => $tenant->id, 'outlet_id' => $bar->id,
        'name' => 'Star', 'price' => 1200, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 0, 'low_stock_threshold' => 3,
    ]);
    Product::query()->create([
        'tenant_id' => $tenant->id, 'outlet_id' => $bar->id,
        'name' => 'Fanta', 'price' => 500, 'cost' => 200,
        'track_stock' => true, 'quantity_on_hand' => 100, 'low_stock_threshold' => 5,
    ]);
    Product::query()->create([
        'tenant_id' => $tenant->id, 'outlet_id' => $bar->id,
        'name' => 'Laundry', 'price' => 3000,
        'track_stock' => false, 'low_stock_threshold' => 0,
    ]);
    $manager = Operator::factory()->for($tenant)->create([
        'role' => OperatorRole::Manager, 'email' => 'mgr@keverna.test',
    ]);
    // Non-manager should be excluded.
    Operator::factory()->for($tenant)->create([
        'role' => OperatorRole::Frontdesk, 'email' => 'frontdesk@keverna.test',
    ]);

    Tenant::forgetCurrent();
    $this->artisan('inventory:send-low-stock-digest')->assertOk();

    Mail::assertQueued(LowStockDigestMail::class, function (LowStockDigestMail $mail) use ($tenant, $manager) {
        return $mail->hasTo($manager->email)
            && ! $mail->hasTo('frontdesk@keverna.test')
            && $mail->tenant->is($tenant)
            && count($mail->items) === 2
            && collect($mail->items)->pluck('name')->contains('Guinness')
            && collect($mail->items)->pluck('name')->contains('Star');
    });
});

it('does not email tenants with no low-stock products', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'digest-b']);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $bar = Outlet::query()->create([
        'tenant_id' => $tenant->id, 'property_id' => $property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    Product::query()->create([
        'tenant_id' => $tenant->id, 'outlet_id' => $bar->id,
        'name' => 'Star', 'price' => 1200,
        'track_stock' => true, 'quantity_on_hand' => 50, 'low_stock_threshold' => 5,
    ]);
    Operator::factory()->for($tenant)->create([
        'role' => OperatorRole::Manager, 'email' => 'mgr@keverna.test',
    ]);

    Tenant::forgetCurrent();
    $this->artisan('inventory:send-low-stock-digest')->assertOk();

    Mail::assertNothingQueued();
});

it('skips tenants with no managers', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'digest-c']);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $bar = Outlet::query()->create([
        'tenant_id' => $tenant->id, 'property_id' => $property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    Product::query()->create([
        'tenant_id' => $tenant->id, 'outlet_id' => $bar->id,
        'name' => 'Guinness', 'price' => 1500,
        'track_stock' => true, 'quantity_on_hand' => 1, 'low_stock_threshold' => 5,
    ]);

    Tenant::forgetCurrent();
    $this->artisan('inventory:send-low-stock-digest')->assertOk();

    Mail::assertNothingQueued();
});
