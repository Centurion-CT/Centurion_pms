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
    $this->tenant = Tenant::factory()->create(['slug' => 'checkout-policy-settings']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

it('mounts with saved checkout policy values', function (): void {
    $this->property->forceFill([
        'checkout_policy' => [
            'late_checkout_enabled' => true,
            'late_checkout_grace_minutes' => 45,
            'late_checkout_hourly_fee' => 7_500,
            'late_checkout_full_day_after_hours' => 5,
        ],
    ])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->assertSet('checkoutPolicy.late_checkout_enabled', true)
        ->assertSet('checkoutPolicy.late_checkout_grace_minutes', 45)
        ->assertSet('checkoutPolicy.late_checkout_hourly_fee', 7_500)
        ->assertSet('checkoutPolicy.late_checkout_full_day_after_hours', 5);
});

it('saves the checkout policy to the property', function (): void {
    // Property form save() also touches address/name/etc — hydrate the
    // property with valid values so validation passes.
    $this->property->forceFill([
        'name' => 'Test Property',
        'address' => '1 Test Street',
        'type' => 'hotel',
    ])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('checkoutPolicy.late_checkout_enabled', true)
        ->set('checkoutPolicy.late_checkout_grace_minutes', 15)
        ->set('checkoutPolicy.late_checkout_hourly_fee', 8_000)
        ->set('checkoutPolicy.late_checkout_full_day_after_hours', 7)
        ->call('save')
        ->assertHasNoErrors();

    $policy = $this->property->fresh()->checkoutPolicy();
    expect($policy['late_checkout_enabled'])->toBeTrue();
    expect($policy['late_checkout_grace_minutes'])->toBe(15);
    expect($policy['late_checkout_hourly_fee'])->toBe(8_000);
    expect($policy['late_checkout_full_day_after_hours'])->toBe(7);
});

it('keeps hourly-fee value when policy is toggled off (survives cycle)', function (): void {
    $this->property->forceFill([
        'name' => 'Test Property',
        'address' => '1 Test Street',
        'type' => 'hotel',
    ])->save();

    // Type numbers with the switch off — the numbers should still persist
    // so a later flip-on doesn't reset back to defaults.
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('checkoutPolicy.late_checkout_enabled', false)
        ->set('checkoutPolicy.late_checkout_hourly_fee', 12_000)
        ->set('checkoutPolicy.late_checkout_full_day_after_hours', 4)
        ->call('save')
        ->assertHasNoErrors();

    $policy = $this->property->fresh()->checkoutPolicy();
    expect($policy['late_checkout_enabled'])->toBeFalse();
    expect($policy['late_checkout_hourly_fee'])->toBe(12_000);
    expect($policy['late_checkout_full_day_after_hours'])->toBe(4);
});

it('rejects out-of-range fee inputs', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('name', 'Test Property')
        ->set('address', '1 Test Street')
        ->set('checkoutPolicy.late_checkout_grace_minutes', -5)
        ->set('checkoutPolicy.late_checkout_full_day_after_hours', 40)
        ->call('save')
        ->assertHasErrors([
            'checkoutPolicy.late_checkout_grace_minutes',
            'checkoutPolicy.late_checkout_full_day_after_hours',
        ]);
});
