<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Pos\Terminal;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'kiosk-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Guinness', 'price' => 1500, 'track_stock' => true, 'quantity_on_hand' => 10,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('PosStaff sees the terminal in the kiosk shell — no dashboard sidebar', function (): void {
    $posStaff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
    $posStaff->outlets()->attach($this->bar->id);

    // Livewire's dynamic layout via ->layout() is exercised at HTTP render
    // time, so hit the actual route rather than mounting the component
    // via Livewire::test (which skips the layout wrap).
    $this->actingAs($posStaff, 'web');
    $response = $this->get(route('tenant.pos.terminal', ['outlet' => $this->bar]));
    $response->assertOk();
    $response->assertSee('data-testid="pos-kiosk-shell"', escape: false);
    $response->assertSee('data-testid="pos-kiosk-logout"', escape: false);
    $response->assertSee('data-testid="pos-kiosk-switch-user"', escape: false);
    $response->assertDontSee('data-testid="dash-sidebar"', escape: false);
});

it('Managers still get the full dashboard shell on the terminal', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    $this->actingAs($manager, 'web');
    $response = $this->get(route('tenant.pos.terminal', ['outlet' => $this->bar]));
    $response->assertOk();
    $response->assertSee('data-testid="dash-sidebar"', escape: false);
    $response->assertDontSee('data-testid="pos-kiosk-shell"', escape: false);
});
