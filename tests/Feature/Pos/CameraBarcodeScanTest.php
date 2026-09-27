<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Inventory\ProductForm;
use App\Livewire\Dashboard\Pos\Terminal;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'camera-scan']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the camera-scan trigger on the POS terminal with the right target + submit selectors', function (): void {
    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(Terminal::class, ['outlet' => $this->bar])
        ->assertSeeHtml('data-testid="pos-scan-camera"')
        ->assertSeeHtml('data-camera-scan')
        ->assertSeeHtml('data-camera-scan-target="#pos-scan-input"')
        ->assertSeeHtml('data-camera-scan-submit="#pos-scan-add"');
});

it('renders the camera-scan trigger on the product form pointing at the barcode input', function (): void {
    Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['outlet' => $this->bar])
        ->assertSeeHtml('data-testid="product-barcode-camera"')
        ->assertSeeHtml('data-camera-scan-target="#p-barcode"');
});
