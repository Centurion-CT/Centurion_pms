<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\GlobalSearch;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gsearch-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('returns nothing when the query is under two characters', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_name' => 'Amaka Onwudiwe']);

    $component = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'A')
        ->call('runSearch');

    expect($component->instance()->results)->toHaveCount(0);
});

it('finds a booking by its reference (prefix match)', function (): void {
    // booking_ref uses a prefix-only LIKE so the unique index gets
    // used on prod (LIKE '%needle%' was full-scanning). Operators
    // paste the full ref or type from the start; the prefix path
    // covers both.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-ABC12345', 'guest_name' => 'Amaka Onwudiwe',
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_name' => 'Someone Else']);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'KV-ABC')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});

it('finds a booking by guest name (partial)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_name' => 'Amaka Onwudiwe']);
    Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_name' => 'Chinedu Ike']);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'amaka')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});

it('finds a booking by email', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_email' => 'amaka@example.test',
    ]);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'amaka@example')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});

it('finds a booking by phone (partial)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_phone' => '+2348091234567',
    ]);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', '8091234567')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});

it('matches a booking ref typed in lowercase (case-insensitive)', function (): void {
    // Regression: Postgres LIKE is case-sensitive on default
    // collations, so "kv-3s8" never matched the stored "KV-3S8XYZ"
    // ref on prod. Fix uppercases the needle for the ref path.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-3S8XYZ99',
    ]);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'kv-3s8')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});

it('matches a guest name typed in mixed case', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Amaka Onwudiwe',
    ]);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'AMAKA')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});

it('caps at 8 hits per query', function (): void {
    for ($i = 0; $i < 12; $i++) {
        Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_name' => 'Test Guest '.$i]);
    }

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', 'Test Guest')
        ->call('runSearch')
        ->instance()->results;

    expect($rows)->toHaveCount(8);
});

it('finds a booking by room number so front desk can look up who is in Rm 210', function (): void {
    $room210 = Room::factory()->for($this->tenant)->for($this->property)->create(['room_number' => '210']);
    $room211 = Room::factory()->for($this->tenant)->for($this->property)->create(['room_number' => '211']);
    $b = Booking::factory()->for($this->tenant)->for($room210)->create(['guest_name' => 'In 210']);
    Booking::factory()->for($this->tenant)->for($room211)->create(['guest_name' => 'In 211']);

    $rows = Livewire::actingAs($this->manager, 'web')
        ->test(GlobalSearch::class)
        ->set('query', '210')
        ->call('runSearch')
        ->instance()->results;

    expect($rows->pluck('id')->all())->toBe([$b->id]);
});
