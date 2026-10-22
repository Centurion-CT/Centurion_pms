<?php

declare(strict_types=1);

use App\Models\Operator;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('clears saved sidebar layouts so everyone gets the install default, keeping other preferences', function (): void {
    config(['brand.sidebar_default' => 'list']);

    $tiles = Operator::factory()->for($this->tenant)->create([
        'preferences' => ['sidebar_variant' => 'tiles', 'other' => 'kept'],
    ]);
    $onlyTiles = Operator::factory()->for($this->tenant)->create([
        'preferences' => ['sidebar_variant' => 'tiles'],
    ]);
    $none = Operator::factory()->for($this->tenant)->create(['preferences' => null]);

    (require database_path('migrations/2026_10_22_190000_reset_operator_sidebar_variant.php'))->up();

    expect($tiles->fresh()->preferences)->toBe(['other' => 'kept'])
        ->and($tiles->fresh()->sidebarVariant())->toBe('list')
        ->and($onlyTiles->fresh()->preferences)->toBeNull()
        ->and($onlyTiles->fresh()->sidebarVariant())->toBe('list')
        ->and($none->fresh()->sidebarVariant())->toBe('list');
});
