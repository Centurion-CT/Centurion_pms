<?php

declare(strict_types=1);

use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('persists a room with all PRD §9 fields', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe King Suite',
        'type' => 'Suite',
        'capacity_adults' => 2,
        'capacity_children' => 1,
        'base_rate' => 45_000,
        'min_nights' => 1,
    ]);

    expect($room->name)->toBe('Deluxe King Suite');
    expect($room->type)->toBe('Suite');
    expect($room->capacity_adults)->toBe(2);
    expect($room->capacity_children)->toBe(1);
    expect($room->base_rate)->toBe(45_000);
    expect($room->booking_type)->toBe(RoomBookingType::ShortLet);
    expect($room->status)->toBe(RoomStatus::Active);
});

it('casts booking_type + status to enums', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->both()->create();

    expect($room->booking_type)->toBe(RoomBookingType::Both);
    expect($room->booking_type->allowsShortLet())->toBeTrue();
    expect($room->booking_type->allowsLongStay())->toBeTrue();
    expect($room->isLongStayCapable())->toBeTrue();
});

it('belongs to a property', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();

    expect($room->property)->toBeInstanceOf(Property::class);
    expect($room->property->id)->toBe($this->property->id);
});

it('exposes property->rooms() HasMany', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->count(3)->create();

    expect($this->property->rooms)->toHaveCount(3);
});

it('computes maxOccupancy = adults + children', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 3,
        'capacity_children' => 2,
    ]);

    expect($room->maxOccupancy())->toBe(5);
});

it('isLongStayCapable() requires both booking_type allowance AND monthly_rate set', function (): void {
    $shortOnly = Room::factory()->for($this->tenant)->for($this->property)->create();
    expect($shortOnly->isLongStayCapable())->toBeFalse();

    $bothWithoutRate = Room::factory()->for($this->tenant)->for($this->property)->create([
        'booking_type' => RoomBookingType::Both,
        'monthly_rate' => null,
    ]);
    expect($bothWithoutRate->isLongStayCapable())->toBeFalse();

    $bothWithRate = Room::factory()->for($this->tenant)->for($this->property)->both()->create();
    expect($bothWithRate->isLongStayCapable())->toBeTrue();
});

it('active() scope returns only active rooms', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->count(2)->create();
    Room::factory()->for($this->tenant)->for($this->property)->inactive()->create();

    expect(Room::query()->active()->count())->toBe(2);
});

it('soft-deletes (data retained for audit + booking history)', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $id = $room->id;

    $room->delete();

    expect(Room::find($id))->toBeNull();
    expect(Room::withTrashed()->find($id))->not->toBeNull();
});

it('is tenant-scoped — a room created under tenant A is invisible to tenant B', function (): void {
    Room::factory()->for($this->tenant)->for($this->property)->create();

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();

    expect(Room::count())->toBe(0);
});

it('tenant A cannot find tenant B rooms via direct ID guess', function (): void {
    $myRoom = Room::factory()->for($this->tenant)->for($this->property)->create();

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();

    expect(Room::find($myRoom->id))->toBeNull();
});

it('tenant A cannot update tenant B rooms via where()->update()', function (): void {
    $myRoom = Room::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'My Suite',
    ]);

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();

    $affected = Room::query()->where('id', $myRoom->id)->update(['name' => 'TAMPERED']);
    expect($affected)->toBe(0);

    Tenant::forgetCurrent();
    expect(
        Room::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('id', $myRoom->id)
            ->first()
            ->name
    )->toBe('My Suite');
});
