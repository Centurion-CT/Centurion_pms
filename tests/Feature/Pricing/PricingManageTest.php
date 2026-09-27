<?php

declare(strict_types=1);

use App\Enums\DepositType;
use App\Livewire\Dashboard\Pricing\Manage;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pricing-ui-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders /dashboard/rooms/{room}/pricing for an onboarded operator', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://pricing-ui-test.keverna.test/dashboard/rooms/'.$this->room->id.'/pricing');

    $response->assertOk();
    $response->assertSeeLivewire(Manage::class);
});

it('saves discount + deposit settings on the room', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('weekly_discount_percent', 10)
        ->set('monthly_discount_percent', 25)
        ->set('deposit_type', 'fixed')
        ->set('deposit_value', 12_000)
        ->call('saveSettings')
        ->assertHasNoErrors();

    $room = $this->room->fresh();
    expect($room->weekly_discount_percent)->toBe(10);
    expect($room->monthly_discount_percent)->toBe(25);
    expect($room->deposit_type)->toBe(DepositType::Fixed);
    expect($room->deposit_value)->toBe(12_000);
});

it('rejects discount % outside 0..100', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('weekly_discount_percent', 150)
        ->call('saveSettings')
        ->assertHasErrors(['weekly_discount_percent']);
});

it('adds a seasonal override + appears in the listing', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('override_start', '2026-12-20')
        ->set('override_end', '2026-12-27')
        ->set('override_rate', 60_000)
        ->set('override_label', 'Festive')
        ->call('addOverride')
        ->assertHasNoErrors();

    $price = RoomPrice::query()->where('room_id', $this->room->id)->first();
    expect($price)->not->toBeNull();
    expect($price->rate)->toBe(60_000);
    expect($price->label)->toBe('Festive');
});

it('rejects an override whose range overlaps an existing one', function (): void {
    RoomPrice::factory()->for($this->room)->for($this->tenant)->create([
        'start_date' => '2026-12-20',
        'end_date' => '2026-12-27',
        'rate' => 60_000,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('override_start', '2026-12-22')
        ->set('override_end', '2026-12-30')
        ->set('override_rate', 70_000)
        ->call('addOverride')
        ->assertHasErrors(['override_start']);

    expect(RoomPrice::count())->toBe(1);
});

it('allows adjacent non-overlapping ranges (gap of one day or contiguous start)', function (): void {
    RoomPrice::factory()->for($this->room)->for($this->tenant)->create([
        'start_date' => '2026-12-20',
        'end_date' => '2026-12-25',
        'rate' => 60_000,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('override_start', '2026-12-26')
        ->set('override_end', '2026-12-31')
        ->set('override_rate', 70_000)
        ->call('addOverride')
        ->assertHasNoErrors();

    expect(RoomPrice::count())->toBe(2);
});

it('rejects end before start', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('override_start', '2026-12-30')
        ->set('override_end', '2026-12-20')
        ->set('override_rate', 50_000)
        ->call('addOverride')
        ->assertHasErrors(['override_end']);
});

it('deletes an override', function (): void {
    $price = RoomPrice::factory()->for($this->room)->for($this->tenant)->create();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->call('deleteOverride', $price->id);

    expect(RoomPrice::find($price->id))->toBeNull();
});

it('shows a live preview breakdown for a valid date range', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('preview_checkin', '2026-06-01')
        ->set('preview_checkout', '2026-06-04')
        ->assertSee('₦90,000');   // 3 × 30,000
});

it('shows the empty preview hint when dates are invalid', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class, ['room' => $this->room])
        ->set('preview_checkin', '2026-06-04')
        ->set('preview_checkout', '2026-06-01')
        ->assertSeeHtml('data-testid="preview-empty"');
});

it('404s when an operator opens the pricing page for another tenant\'s room', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-pricing-ui']);
    $otherProperty = Property::factory()->for($otherTenant)->create();
    Tenant::forgetCurrent();
    $otherTenant->makeCurrent();
    $foreignRoom = Room::factory()->for($otherTenant)->for($otherProperty)->create();
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://pricing-ui-test.keverna.test/dashboard/rooms/'.$foreignRoom->id.'/pricing');

    $response->assertNotFound();
});
