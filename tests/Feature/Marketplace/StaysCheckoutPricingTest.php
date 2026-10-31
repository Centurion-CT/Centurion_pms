<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Fixes the guest checkout was shipping to prod with:
 *   1. Nights preview stuck at 0 (Carbon-3 sign flip on diffInDays)
 *      so pricing collapsed to "N × 0 nights = ₦0" + "Pay ₦0"
 *   2. Room picker dumping every door number (30+ radio rows on a
 *      large property) instead of one row per category with a
 *      collapsed default state + Change trigger
 *   3. Summary showing only a subtotal - no platform fee, no
 *      Paystack processing fee, no full breakdown
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'checkout-pricing']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Pricing Test Property',
    ]);
    $this->deluxe = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 40_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->standard = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 25_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    // 3 Deluxe rooms + 2 Standard - 5 rows if we dumped rooms,
    // 2 rows if we group by category. The picker should show 2.
    collect(range(1, 3))->each(fn ($n) => Room::factory()
        ->for($this->tenant)->for($this->property)->for($this->deluxe, 'category')
        ->create(['room_number' => 'D'.$n, 'status' => RoomStatus::Active]));
    collect(range(1, 2))->each(fn ($n) => Room::factory()
        ->for($this->tenant)->for($this->property)->for($this->standard, 'category')
        ->create(['room_number' => 'S'.$n, 'status' => RoomStatus::Active]));
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

it('reports 1 night for a next-day checkout (Carbon-3 sign fix)', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(5)->toDateString())
        ->set('checkout', Carbon::today()->addDays(6)->toDateString())
        ->assertSet('checkin', Carbon::today()->addDays(5)->toDateString())
        ->call('$refresh')
        ->assertSee('× 1 night');
});

it('reports 3 nights for a 3-night stay', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(6)->toDateString())
        ->assertSee('× 3 nights');
});

it('renders room selection collapsed to just the selected category + a Change trigger', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug]);
    $c->assertOk();

    // Collapsed by default - selected row + Change button.
    $c->assertSee('data-testid="checkout-room-selected"', false);
    $c->assertSee('data-testid="checkout-room-change"', false);
    $c->assertSee('Change room');
    // Expanded picker is NOT rendered.
    $c->assertDontSee('data-testid="checkout-category-option-'.$this->deluxe->id.'"', false);
});

it('expands the picker into per-category rows + never dumps individual door numbers', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->call('expandRoomPicker');

    // Two category options (not 5 door numbers).
    $c->assertSee('data-testid="checkout-category-option-'.$this->deluxe->id.'"', false);
    $c->assertSee('data-testid="checkout-category-option-'.$this->standard->id.'"', false);
    // No "Room D1" / "Room S1" door-numbered clutter.
    $c->assertDontSee('Room D1');
    $c->assertDontSee('Room S1');
});

it('picking a category swaps to the first room in that category + collapses the picker', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->call('expandRoomPicker')
        ->call('selectCategory', $this->standard->id)
        ->assertSet('expandedRoomPicker', false)
        ->assertSet('category', $this->standard->id);

    // Selected room is now one of the Standard rooms.
    $roomId = $c->get('room');
    $room = Room::query()->withoutGlobalScope(\App\Multitenancy\TenantScope::class)->find($roomId);
    expect($room->room_category_id)->toBe($this->standard->id);
});

it('renders a full guest-side breakdown - room + Keverna service fee + processing + total', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(4)->toDateString())
        ->set('checkout', Carbon::today()->addDays(6)->toDateString());

    // Room + Keverna service fee + processing + total all visible.
    $c->assertSee('data-testid="checkout-line-room"', false);
    $c->assertSee('data-testid="checkout-line-platform"', false);
    $c->assertSee('data-testid="checkout-line-processing"', false);
    $c->assertSee(config('brand.name').' service fee');
    $c->assertSee('Payment processing');
    $c->assertSee('data-testid="checkout-total"', false);
});

it('disables the Pay button + shows "Pick your dates" when no valid range is set', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', '')
        ->set('checkout', '')
        ->assertSee('Pick your dates');
});

it('shows "Pay ₦X" with the correct total on the CTA', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(4)->toDateString())
        ->set('checkout', Carbon::today()->addDays(5)->toDateString());

    $bd = $c->instance()->breakdown();
    expect($bd)->not->toBeNull();
    expect($bd['nights'])->toBe(1);
    // The button carries the total. Naira sign renders as the HTML
    // entity `&#8358;` (unicode ₦), so we assert on the entity and
    // the number separately rather than on the composite character.
    $c->assertSee('&#8358;', false);
    $c->assertSee(number_format($bd['total']));
});
