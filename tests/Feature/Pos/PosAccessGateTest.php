<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Pos\OutletPicker;
use App\Livewire\Dashboard\Pos\Terminal;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function posHost(string $slug): string
{
    return "http://{$slug}.keverna.test";
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pos-gate']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->posStaff = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
    $this->bar = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Bar',
        'type'        => OutletType::Bar,
    ]);
    $this->kitchen = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Kitchen',
        'type'        => OutletType::Kitchen,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ---------------- OutletPicker ---------------- */

it('Manager sees every outlet in the picker', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(OutletPicker::class)
        ->assertSeeHtml('data-testid="pick-outlet-bar"')
        ->assertSeeHtml('data-testid="pick-outlet-kitchen"');
});

it('PosStaff only sees assigned outlets in the picker', function (): void {
    // Assign a THIRD outlet too — otherwise the count==1 auto-redirect
    // in the picker fires and we can't inspect the rendered list.
    $second = Outlet::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'name'        => 'Lounge',
        'type'        => OutletType::Bar,
    ]);
    $this->posStaff->outlets()->attach([$this->bar->id, $second->id]);

    Livewire::actingAs($this->posStaff, 'web')
        ->test(OutletPicker::class)
        ->assertSeeHtml('data-testid="pick-outlet-bar"')
        ->assertSeeHtml('data-testid="pick-outlet-lounge"')
        ->assertDontSeeHtml('data-testid="pick-outlet-kitchen"');
});

it('PosStaff with no assignments sees the empty state', function (): void {
    Livewire::actingAs($this->posStaff, 'web')
        ->test(OutletPicker::class)
        ->assertSeeHtml('data-testid="picker-empty"');
});

/* ---------------- Terminal ---------------- */

it('Manager can open a terminal for any outlet', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->assertSet('outletId', $this->bar->id);
});

it('PosStaff cannot open a terminal for an outlet they are not assigned to', function (): void {
    // No assignment for this posStaff — the terminal 404s (mount throws).
    // Livewire::test() swallows exceptions from mount, so assert via
    // the actual HTTP request instead.
    $response = $this->actingAs($this->posStaff, 'web')
        ->get(posHost('pos-gate')."/dashboard/pos/{$this->kitchen->id}");

    $response->assertNotFound();
});

it('PosStaff can open a terminal for an assigned outlet', function (): void {
    $this->posStaff->outlets()->attach($this->bar->id);
    Livewire::actingAs($this->posStaff, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->assertSet('outletId', $this->bar->id);
});

/* ---------------- Sidebar module gate ---------------- */

it('POS module is in Manager modules list', function (): void {
    expect(OperatorRole::Manager->canAccessModule('pos'))->toBeTrue();
    expect(OperatorRole::Manager->canAccessModule('inventory'))->toBeTrue();
});

it('PosStaff can access pos module but not inventory', function (): void {
    expect(OperatorRole::PosStaff->canAccessModule('pos'))->toBeTrue();
    expect(OperatorRole::PosStaff->canAccessModule('inventory'))->toBeFalse();
});

it('Frontdesk and HouseCleaner cannot access pos or inventory', function (): void {
    foreach ([OperatorRole::Frontdesk, OperatorRole::HouseCleaner] as $role) {
        expect($role->canAccessModule('pos'))->toBeFalse();
        expect($role->canAccessModule('inventory'))->toBeFalse();
    }
});
