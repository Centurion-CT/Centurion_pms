<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * When the guest types a phone number on the checkout form, we
 * look up existing customers by that phone (property's tenant
 * first, then anywhere in Keverna Stays) and autofill their
 * name + email. Repeat guests skip the retype; first-timers see
 * a normal empty form.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'phone-lookup']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Phone Lookup Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('autofills name + email when the phone matches a customer on this property\'s tenant', function (): void {
    // Prior customer at this property.
    Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Ada Nwokolo',
        'email' => 'ada@example.test',
        'phone' => '+2348030000000',
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestPhone', '+234 803 000 0000')  // deliberately formatted differently
        ->assertSet('guestName', 'Ada Nwokolo')
        ->assertSet('guestEmail', 'ada@example.test');
});

it('autofills across tenants (cross-property match) when the same phone exists on another tenant', function (): void {
    // Different tenant + property - customer stayed there once.
    $otherTenant = Tenant::factory()->create(['slug' => 'other-tenant']);
    Customer::query()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Chika Anike',
        'email' => 'chika@example.test',
        'phone' => '+2348030000000',
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestPhone', '+2348030000000')
        ->assertSet('guestName', 'Chika Anike')
        ->assertSet('guestEmail', 'chika@example.test');
});

it('prefers the property\'s own tenant when the same phone exists in multiple tenants', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-t']);
    Customer::query()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Stale Duplicate',
        'email' => 'stale@example.test',
        'phone' => '+2348030000000',
    ]);
    // Same phone, THIS property's tenant - should win the ordering.
    Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Current Property Match',
        'email' => 'current@example.test',
        'phone' => '+2348030000000',
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestPhone', '+2348030000000')
        ->assertSet('guestName', 'Current Property Match')
        ->assertSet('guestEmail', 'current@example.test');
});

it('does not overwrite a name / email the guest has already typed', function (): void {
    Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'DB Name',
        'email' => 'db@example.test',
        'phone' => '+2348030000000',
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestName', 'Typed Name')
        ->set('guestEmail', 'typed@example.test')
        ->set('guestPhone', '+2348030000000')
        // Both typed values preserved - lookup only fills blanks.
        ->assertSet('guestName', 'Typed Name')
        ->assertSet('guestEmail', 'typed@example.test');
});

it('skips the lookup when the phone has fewer than 8 digits', function (): void {
    // Even if a customer somehow existed with a 5-digit phone,
    // the lookup gate wouldn't fire.
    Customer::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Shouldnt Match',
        'email' => 'nope@example.test',
        'phone' => '12345',
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestPhone', '12345')
        ->assertSet('guestName', '')
        ->assertSet('guestEmail', '');
});

it('leaves the form blank when no customer matches the phone', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestPhone', '+2348030000000')
        ->assertSet('guestName', '')
        ->assertSet('guestEmail', '');
});

it('renders the phone field first on the checkout markup', function (): void {
    // Structural test - phone input appears in the rendered HTML
    // before the name input.
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug]);
    $html = $c->html();
    $phonePos = strpos($html, 'data-testid="checkout-guest-phone"');
    $namePos  = strpos($html, 'data-testid="checkout-guest-name"');
    $emailPos = strpos($html, 'data-testid="checkout-guest-email"');

    expect($phonePos)->toBeLessThan($namePos, 'phone must precede name');
    expect($namePos)->toBeLessThan($emailPos, 'name must precede email');
});
