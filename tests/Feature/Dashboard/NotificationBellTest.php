<?php

declare(strict_types=1);

use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\NotificationBell;
use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'bell']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        // Never seen anything before — bell should surface everything.
        'last_notifications_seen_at' => null,
    ]);
    $this->actingAs($this->operator, 'web');
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Bell Guest',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('surfaces new guest requests as bell items', function (): void {
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'room_id' => $this->room->id,
        'kind' => GuestRequestKind::Complaint->value,
        'status' => 'open',
        'notes' => 'noisy room',
        'body' => [],
    ]);

    Livewire::test(NotificationBell::class)
        ->assertSet('panelOpen', false)
        ->assertViewHas('count', 1)
        ->assertViewHas('items', fn ($items) => $items->count() === 1
            && $items[0]['kind'] === 'guest_request'
            && $items[0]['title'] === 'Bell Guest');
});

it('surfaces booking activity events (extended / checked_in / checked_out)', function (): void {
    // Two activity rows on the booking.
    \DB::table('activity_log')->insert([
        [
            'log_name' => 'booking',
            'description' => 'Extended',
            'subject_type' => Booking::class,
            'subject_id' => $this->booking->id,
            'event' => 'extended',
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'log_name' => 'booking',
            'description' => 'Checked in',
            'subject_type' => Booking::class,
            'subject_id' => $this->booking->id,
            'event' => 'checked_in',
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Livewire::test(NotificationBell::class)
        ->assertViewHas('count', 2)
        ->assertViewHas('items', fn ($items) => $items->pluck('kind_label')
            ->contains('Extended stay'));
});

it('markAllSeen bumps the seen timestamp and zeros the next count', function (): void {
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'room_id' => $this->room->id,
        'kind' => GuestRequestKind::Taxi->value,
        'status' => 'open',
        'body' => [],
    ]);

    // Simulate the operator seeing everything a moment later.
    Carbon::setTestNow(now()->addSecond());

    Livewire::test(NotificationBell::class)
        ->assertViewHas('count', 1)
        ->call('markAllSeen')
        ->assertSet('panelOpen', false)
        ->assertViewHas('count', 0);

    expect($this->operator->fresh()->last_notifications_seen_at)->not->toBeNull();
});
