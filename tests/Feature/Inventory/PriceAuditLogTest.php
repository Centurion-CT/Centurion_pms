<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Inventory\OutletForm;
use App\Livewire\Dashboard\Inventory\ProductForm;
use App\Models\Activity;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'audit-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->outlet = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar, 'tax_rate' => 7.5, 'active' => true,
    ]);
    $this->guinness = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->outlet->id,
        'name' => 'Guinness', 'price' => 1500, 'cost' => 700,
        'track_stock' => true, 'quantity_on_hand' => 20,
        'active' => true, 'low_stock_threshold' => 0,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('editing a product price logs an activity with old + new values', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('price', 2_000)
        ->call('save');

    $log = Activity::query()
        ->where('log_name', 'product')
        ->where('subject_type', Product::class)
        ->where('subject_id', $this->guinness->id)
        ->where('event', 'updated')
        ->orderByDesc('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->properties['attributes']['price'] ?? null)->toBe(2_000);
    expect($log->properties['old']['price'] ?? null)->toBe(1_500);
    expect($log->causer_id)->toBe($this->manager->id);
});

it('editing an outlet tax rate logs an activity with old + new values', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(OutletForm::class, ['outlet' => $this->outlet])
        ->set('tax_rate', '10')
        ->call('save');

    $log = Activity::query()
        ->where('log_name', 'outlet')
        ->where('subject_type', Outlet::class)
        ->where('subject_id', $this->outlet->id)
        ->where('event', 'updated')
        ->orderByDesc('id')
        ->first();

    expect($log)->not->toBeNull();
    // tax_rate is stored as string (decimal column); Spatie snapshots
    // whatever the model exposes.
    expect((float) ($log->properties['attributes']['tax_rate'] ?? 0))->toBe(10.0);
    expect((float) ($log->properties['old']['tax_rate'] ?? 0))->toBe(7.5);
});

it('a non-money field change also lands in the log', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->set('name', 'Guinness Extra Cold')
        ->call('save');

    $log = Activity::query()
        ->where('log_name', 'product')
        ->where('subject_id', $this->guinness->id)
        ->where('event', 'updated')
        ->orderByDesc('id')
        ->first();

    expect($log->properties['attributes']['name'] ?? null)->toBe('Guinness Extra Cold');
    expect($log->properties['old']['name'] ?? null)->toBe('Guinness');
});

it('a save with no dirty fields does not create an updated activity', function (): void {
    $before = Activity::query()
        ->where('subject_type', Product::class)
        ->where('subject_id', $this->guinness->id)
        ->where('event', 'updated')
        ->count();

    Livewire::actingAs($this->manager, 'web')
        ->test(ProductForm::class, ['product' => $this->guinness])
        ->call('save');

    // dontSubmitEmptyLogs — no dirty fields, no update-event row
    expect(Activity::query()
        ->where('subject_type', Product::class)
        ->where('subject_id', $this->guinness->id)
        ->where('event', 'updated')
        ->count())->toBe($before);
});
