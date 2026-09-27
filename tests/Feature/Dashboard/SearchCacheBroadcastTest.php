<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Events\BookingSearchCacheMutated;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cache-broadcast']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    Carbon::setTestNow(Carbon::create(2026, 9, 25, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('broadcasts an upsert on booking creation inside the cache window', function (): void {
    Event::fake([BookingSearchCacheMutated::class]);

    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    Event::assertDispatched(BookingSearchCacheMutated::class, function ($event) {
        return $event->kind === 'upsert'
            && $event->tenantId === $this->tenant->id
            && ($event->payload['booking_ref'] ?? null) !== null;
    });
});

it('broadcasts a delete when a booking flips to cancelled', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    Event::fake([BookingSearchCacheMutated::class]);
    $b->update(['booking_status' => BookingStatus::Cancelled]);

    Event::assertDispatched(BookingSearchCacheMutated::class, function ($event) use ($b) {
        return $event->kind === 'delete'
            && ($event->payload['id'] ?? null) === $b->id;
    });
});

it('does not broadcast when an unrelated column changes', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    Event::fake([BookingSearchCacheMutated::class]);
    // touch a column NOT in AFFECTING_COLUMNS
    $b->update(['special_requests' => 'Late check-in requested']);

    Event::assertNotDispatched(BookingSearchCacheMutated::class);
});

it('routes broadcasts on the tenant private channel with the correct alias', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->addDays(2),
    ]);

    $event = BookingSearchCacheMutated::upsertFor($b->fresh(['room', 'room.property']));

    expect($event->broadcastOn()->name)->toBe('private-tenant.'.$this->tenant->id.'.bookings');
    expect($event->broadcastAs())->toBe('booking.search-cache.mutated');

    $wire = $event->broadcastWith();
    expect($wire['kind'])->toBe('upsert');
    expect($wire['payload']['id'])->toBe($b->id);
});

it('delete event carries just the id', function (): void {
    $event = BookingSearchCacheMutated::deleteFor($this->tenant->id, 42);

    expect($event->kind)->toBe('delete');
    expect($event->broadcastWith())->toBe([
        'kind' => 'delete',
        'payload' => ['id' => 42],
    ]);
});
