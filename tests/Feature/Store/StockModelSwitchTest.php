<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockModel;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;

/**
 * Property-level toggle for the inventory model. The Store nav
 * entry (and its badge) hide when the property is on
 * per_department mode.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'stock-model']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('property defaults to StoreAndRequisition', function (): void {
    expect($this->property->fresh()->stock_model)->toBe(StockModel::StoreAndRequisition);
});

it('sidebar renders the Store entry under the default stock model', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://'.$this->tenant->slug.'.keverna.test/dashboard');

    $response->assertOk();
    expect($response->getContent())->toContain('/dashboard/store');
});

it('sidebar hides the Store entry when the property is on per_department mode', function (): void {
    $this->property->forceFill(['stock_model' => StockModel::PerDepartment])->save();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://'.$this->tenant->slug.'.keverna.test/dashboard');

    $response->assertOk();
    // The Store route + its badge testid should both be absent.
    expect($response->getContent())->not->toContain('/dashboard/store');
    expect($response->getContent())->not->toContain('data-testid="nav-badge-store"');
});

it('StockModel::usesCentralStore reflects the enum case', function (): void {
    expect(StockModel::StoreAndRequisition->usesCentralStore())->toBeTrue();
    expect(StockModel::PerDepartment->usesCentralStore())->toBeFalse();
});

/* ─── F&B vocabulary ───────────────────────────────────────────── */

it('OutletType F&B cases return Item + Portion nouns', function (): void {
    foreach ([OutletType::Bar, OutletType::Restaurant, OutletType::Kitchen] as $t) {
        expect($t->isFoodAndBeverage())->toBeTrue();
        expect($t->productNoun())->toBe('Item');
        expect($t->productNoun(plural: true))->toBe('Items');
        expect($t->quantityNoun())->toBe('Portion');
        expect($t->quantityNoun(plural: true))->toBe('Portions');
    }
});

it('OutletType non-F&B cases keep Product + Unit nouns', function (): void {
    foreach ([OutletType::Laundry, OutletType::Spa, OutletType::GiftShop, OutletType::Other] as $t) {
        expect($t->isFoodAndBeverage())->toBeFalse();
        expect($t->productNoun())->toBe('Product');
        expect($t->quantityNoun())->toBe('Unit');
    }
});
