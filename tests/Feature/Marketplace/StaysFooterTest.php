<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Guest-facing Keverna Stays footer must render on every
 * marketplace surface a guest lands on (index, property detail,
 * checkout). Structural test - pins the testid + the NDPR trust
 * badge + a signature column heading + a signature link, so a
 * broken include or missing partial doesn't silently ship.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'footer-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Footer Test Property',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the stays footer on /stays', function (): void {
    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('data-testid="mp-stays-footer"', false)
        ->assertSee('data-testid="ndpr-trust-badge"', false)
        ->assertSee('Explore stays')
        ->assertSee('Stays in Lagos')
        ->assertSee('FregateLab Limited');
});

it('renders the stays footer on /stays/{slug}', function (): void {
    $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]))
        ->assertOk()
        ->assertSee('data-testid="mp-stays-footer"', false)
        ->assertSee('Explore stays');
});

it('renders the stays footer on the checkout page', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertOk()
        ->assertSee('data-testid="mp-stays-footer"', false)
        ->assertSee('Explore stays');
});

it('deep-links to popular-city stays search from the footer', function (): void {
    // City chips in the "Explore stays" column send the guest into
    // /stays?q=<city> - the search-index controller reads ?q= and
    // filters name/city/state, so a click there lands on a real
    // filtered results page.
    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('href="'.route('marketplace.stays.index', ['q' => 'Lagos']).'"', false)
        ->assertSee('href="'.route('marketplace.stays.index', ['q' => 'Abuja']).'"', false);
});

it('links to WhatsApp for guest contact', function (): void {
    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('data-testid="stays-footer-whatsapp"', false)
        ->assertSee('href="https://wa.me/', false);
});
