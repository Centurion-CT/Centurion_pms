<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysAvailabilityBrowser;
use App\Models\AvailabilityBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Livewire "availability browser" pin - proves the sticky search +
 * category list actually re-renders in-place on submit / date edit
 * instead of the old plain-form full-page reload.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'avail-lw']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Livewire Availability Suites',
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    // Three rooms so we can block a couple + still have one free.
    $this->rooms = collect(range(1, 3))->map(fn ($n) => Room::factory()
        ->for($this->tenant)->for($this->property)->for($this->category, 'category')
        ->create(['room_number' => 'D'.$n, 'status' => RoomStatus::Active]));
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

function blockRoomAllNights(int $roomId, string $from, string $to): void
{
    $room = Room::query()->withoutGlobalScope(TenantScope::class)->findOrFail($roomId);
    $c = Carbon::parse($from)->startOfDay();
    $end = Carbon::parse($to)->startOfDay();
    while ($c->lessThan($end)) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $room->tenant_id,
            'room_id'   => $roomId,
            'date'      => $c->toDateString(),
            'type'      => AvailabilityBlockType::Booked,
            'booking_id' => null,
        ]);
        $c->addDay();
    }
}

it('boots with total-units when no dates come in via the URL', function (): void {
    Livewire::test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSee('3 units total');
});

it('rerenders category counts in-place when the guest submits a valid range', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(5)->toDateString();
    blockRoomAllNights($this->rooms[0]->id, $from, $to);

    Livewire::test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->set('from', $from)
        ->set('to', $to)
        ->call('submit')
        // 3 rooms total - 1 blocked = 2 free.
        ->assertSee('2 units left')
        ->assertDontSee('3 units total');
});

it('marks the category sold out when every room is blocked for the range', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(5)->toDateString();
    foreach ($this->rooms as $r) {
        blockRoomAllNights($r->id, $from, $to);
    }

    Livewire::test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->set('from', $from)->set('to', $to)
        ->call('submit')
        ->assertSee('Sold out')
        ->assertSee('data-testid="category-soldout-'.$this->category->id.'"', false);
});

it('auto-advances checkout when the guest picks a check-in in-component', function (): void {
    Livewire::test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->set('from', Carbon::today()->addDays(7)->toDateString())
        ->assertSet('to', Carbon::today()->addDays(8)->toDateString());
});

it('snaps a checkout on/before check-in to check-in +1', function (): void {
    $ci = Carbon::today()->addDays(4)->toDateString();

    Livewire::test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->set('from', $ci)
        ->set('to', $ci)  // same day - illegal
        ->assertSet('to', Carbon::today()->addDays(5)->toDateString());
});

it('drops a URL-supplied past-date pair on mount instead of pre-filling it', function (): void {
    Livewire::withQueryParams([
        'from' => Carbon::today()->subDays(3)->toDateString(),
        'to'   => Carbon::today()->subDay()->toDateString(),
    ])
        ->test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->assertSet('from', '')
        ->assertSet('to', '');
});

it('drops a URL-supplied same-day pair on mount', function (): void {
    $day = Carbon::today()->addDays(3)->toDateString();
    Livewire::withQueryParams(['from' => $day, 'to' => $day])
        ->test(StaysAvailabilityBrowser::class, ['slug' => $this->property->public_slug])
        ->assertSet('from', '')
        ->assertSet('to', '');
});

it('embeds the availability browser on the /stays/{slug} page', function (): void {
    $r = $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]));

    $r->assertOk();
    // Livewire's `wire:snapshot` attribute on the component root
    // proves the component actually hydrated (server-side render).
    $r->assertSee('wire:snapshot', false);
    // Sticky form still there under the new render path.
    $r->assertSee('data-testid="stays-show-availbar"', false);
    $r->assertSee('data-testid="availbar-submit"', false);
});
