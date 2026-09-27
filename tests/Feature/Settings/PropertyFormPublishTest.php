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
    $this->tenant = Tenant::factory()->create(['slug' => 'publish-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create(['is_live' => false]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

it('mounts with the current is_live value', function (): void {
    $this->property->update(['is_live' => true]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSet('isLive', true);
});

it('saves the publish toggle to the property', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSet('isLive', false)
        ->set('isLive', true)
        ->call('save');

    expect($this->property->fresh()->is_live)->toBeTrue();
});

it('lets an operator flip the site back to coming soon', function (): void {
    $this->property->update(['is_live' => true]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('isLive', false)
        ->call('save');

    expect($this->property->fresh()->is_live)->toBeFalse();
});

it('surfaces the public site url in the render payload', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSeeHtml('data-testid="publish-fieldset"')
        ->assertSeeHtml('data-testid="property-is-live"');
});

it('renders the warning state when the site is not visible', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSeeHtml('Not visible')
        ->assertSeeHtml('Publish site')
        ->assertSeeHtml('aria-checked="false"');
});

it('renders the live state when the site is published', function (): void {
    $this->property->update(['is_live' => true]);

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSeeHtml('Turn site off')
        ->assertSeeHtml('aria-checked="true"')
        ->assertDontSeeHtml('Not visible');
});
