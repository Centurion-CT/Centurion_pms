<?php

declare(strict_types=1);

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OutletType;
use App\Livewire\Dashboard\Inventory\RecipeForm;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'recipe-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();

    // Ingredients.
    $this->rum = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Rum (shots)', 'price' => 500, 'cost' => 300,
        'track_stock' => true, 'quantity_on_hand' => 40,
    ]);
    $this->coke = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Coke (cans)', 'price' => 400, 'cost' => 200,
        'track_stock' => true, 'quantity_on_hand' => 30,
    ]);
    // Composite.
    $this->cocktail = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Rum & Coke', 'price' => 1500, 'cost' => 0,
        'track_stock' => false,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('lets managers save a recipe via the form', function (): void {
    Livewire\Livewire::actingAs($this->operator, 'web')
        ->test(RecipeForm::class, ['product' => $this->cocktail])
        ->set('items', [
            ['component_product_id' => $this->rum->id, 'quantity' => 1],
            ['component_product_id' => $this->coke->id, 'quantity' => 1],
        ])
        ->call('save');

    expect($this->cocktail->fresh()->recipeItems)->toHaveCount(2);
});

it('completing a composite sale decrements each component by qty × recipe', function (): void {
    // Give the cocktail a recipe: 2 shots of rum + 1 can of coke per drink.
    $this->cocktail->recipeItems()->create([
        'tenant_id' => $this->tenant->id,
        'component_product_id' => $this->rum->id, 'quantity' => 2,
    ]);
    $this->cocktail->recipeItems()->create([
        'tenant_id' => $this->tenant->id,
        'component_product_id' => $this->coke->id, 'quantity' => 1,
    ]);

    // Sell 3 cocktails.
    $draft = app(CreatePosSaleDraft::class)->handle(
        outlet: $this->bar,
        operator: $this->operator,
        cart: [['product_id' => $this->cocktail->id, 'quantity' => 3]],
    );
    app(CompletePosSale::class)->handle($draft, $this->operator, 'cash');

    // 3 drinks × 2 shots = 6 rum decrements; 3 × 1 can = 3 coke decrements.
    expect($this->rum->fresh()->quantity_on_hand)->toBe(40 - 6);
    expect($this->coke->fresh()->quantity_on_hand)->toBe(30 - 3);
    // Composite itself doesn't track stock — untouched.
    expect($this->cocktail->fresh()->quantity_on_hand)->toBe(0);
});

it('rejects a recipe that references the composite itself', function (): void {
    Livewire\Livewire::actingAs($this->operator, 'web')
        ->test(RecipeForm::class, ['product' => $this->cocktail])
        ->set('items', [
            ['component_product_id' => $this->cocktail->id, 'quantity' => 1],
        ])
        ->call('save')
        ->assertHasErrors(['items']);
});
