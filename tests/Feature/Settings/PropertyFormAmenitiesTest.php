<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Settings\PropertyForm;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'amenities-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'amenities' => ['Free Wi-Fi', '24/7 backup power'],
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

it('mounts with the existing amenities as a sequential list', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSet('amenities', ['Free Wi-Fi', '24/7 backup power']);
});

it('normalises amenities on save: trims, dedupes case-insensitively, drops empties', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('amenities', [
            '  Free Wi-Fi  ',
            'FREE wi-fi',        // dupe (case-insensitive)
            '',                   // dropped
            'Swimming pool',
            'Swimming Pool',      // dupe
        ])
        ->call('save')
        ->assertHasNoErrors();

    $this->property->refresh();
    expect($this->property->amenities)->toBe(['Free Wi-Fi', 'Swimming pool']);
});

it('caps amenities at 60 items on save even if the client posts more', function (): void {
    $tooMany = collect(range(1, 80))->map(fn (int $n) => "Item {$n}")->all();

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('amenities', $tooMany)
        ->call('save')
        ->assertHasErrors(['amenities']);

    $this->property->refresh();
    expect($this->property->amenities)->toBe(['Free Wi-Fi', '24/7 backup power']);
});

it('accepts exactly 60 items', function (): void {
    $exact = collect(range(1, 60))->map(fn (int $n) => "Item {$n}")->all();

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('amenities', $exact)
        ->call('save')
        ->assertHasNoErrors();

    $this->property->refresh();
    expect(count($this->property->amenities))->toBe(60);
});

it('validates each amenity is a string no longer than 100 chars', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('amenities', ['Free Wi-Fi', str_repeat('A', 101)])
        ->call('save')
        ->assertHasErrors(['amenities.*']);
});

it('persists a fresh amenity set + clears the column when emptied', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('amenities', [])
        ->call('save')
        ->assertHasNoErrors();

    $this->property->refresh();
    expect($this->property->amenities)->toBeNull();
});
