<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysAvailabilityBrowser;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * The property page shows a "Return to checkout" pill at the top
 * of the rooms section when the guest arrived via the checkout's
 * "Add another room" link (?cart=X,Y). Lets them abandon the
 * add-a-room mid-flow without needing to pick another room first.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'return-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Return Test Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('does NOT render the return-to-checkout pill when no cart is present', function (): void {
    Livewire::test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->assertDontSee('data-testid="stays-show-return-checkout"', false);
});

it('renders the return-to-checkout pill when the URL carries ?cart=', function (): void {
    Livewire::withQueryParams(['cart' => (string) $this->roomA->id])
        ->test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->assertSee('data-testid="stays-show-return-checkout"', false)
        ->assertSee('Return to checkout')
        ->assertSee('1 room in cart');
});

it('pluralises the room count for a multi-room cart', function (): void {
    Livewire::withQueryParams(['cart' => $this->roomA->id.','.$this->roomB->id])
        ->test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->assertSee('2 rooms in cart');
});

it('single-room cart links back via ?room=X (falls back to legacy single-room path)', function (): void {
    $r = Livewire::withQueryParams(['cart' => (string) $this->roomA->id])
        ->test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug]);
    $html = $r->html();
    // Only one room in cart: URL uses ?room=X, not ?rooms=X.
    preg_match('/data-testid="stays-show-return-checkout"[^>]*href="([^"]+)"/', $html, $m);
    if (empty($m[1])) {
        preg_match('/<a[^>]*href="([^"]+)"[^>]*data-testid="stays-show-return-checkout"/', $html, $m);
    }
    $href = $m[1] ?? '';
    expect($href)->toContain('room='.$this->roomA->id);
    expect($href)->not->toContain('rooms=');
});

it('multi-room cart links back via ?rooms=X,Y', function (): void {
    $r = Livewire::withQueryParams(['cart' => $this->roomA->id.','.$this->roomB->id])
        ->test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug]);
    $html = $r->html();
    preg_match('/data-testid="stays-show-return-checkout"[^>]*href="([^"]+)"/', $html, $m);
    if (empty($m[1])) {
        preg_match('/<a[^>]*href="([^"]+)"[^>]*data-testid="stays-show-return-checkout"/', $html, $m);
    }
    $href = $m[1] ?? '';
    expect($href)->toContain('rooms='.$this->roomA->id.'%2C'.$this->roomB->id);
    // Multi-room path shouldn't ALSO carry ?room=<single>.
    expect($href)->not->toContain('room='.$this->roomA->id.'&');
});
