<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBooking;
use App\Events\BookingConfirmed;
use App\Livewire\Dashboard\NotificationsBell;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- Dispatch ---------- */

it('writes a notification row when BookingConfirmed fires', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    BookingConfirmed::dispatch($booking);

    $row = DatabaseNotification::query()->latest('created_at')->first();
    expect($row)->not->toBeNull();
    expect($row->getAttribute('tenant_id'))->toBe($this->tenant->id);
    expect($row->data['event'])->toBe('confirmed');
    expect($row->data['booking_ref'])->toBe($booking->booking_ref);
});

it('writes a notification row when CancelBooking runs', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    app(CancelBooking::class)->handle($booking);

    $row = DatabaseNotification::query()->where('notifiable_id', $this->operator->id)->latest('created_at')->first();
    expect($row)->not->toBeNull();
    expect($row->data['event'])->toBe('cancelled');
});

it('fans out one notification per operator on the tenant', function (): void {
    Operator::factory()->for($this->tenant)->create();
    Operator::factory()->for($this->tenant)->create();

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);

    expect(DatabaseNotification::query()->count())->toBe(3);
});

it('is isolated — tenant B operators do not get tenant A notifications', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();
    $operatorB = Operator::factory()->for($tenantB)->create();

    expect(DatabaseNotification::query()
        ->where('notifiable_id', $operatorB->id)
        ->count())->toBe(0);
});

/* ---------- Bell UI ---------- */

it('shows the unread count in the bell', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);
    BookingConfirmed::dispatch($booking);

    Livewire::actingAs($this->operator, 'web')
        ->test(NotificationsBell::class)
        ->assertSeeHtml('data-testid="bell-unread-count"')
        ->assertSee('2');
});

it('opens the dropdown and shows notification items', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);

    Livewire::actingAs($this->operator, 'web')
        ->test(NotificationsBell::class)
        ->call('toggle')
        ->assertSeeHtml('data-testid="bell-dropdown"')
        ->assertSeeHtml('data-testid="bell-item"');
});

it('marks a single notification as read', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);
    $notification = DatabaseNotification::query()
        ->where('notifiable_id', $this->operator->id)
        ->first();
    expect($notification->read_at)->toBeNull();

    Livewire::actingAs($this->operator, 'web')
        ->test(NotificationsBell::class)
        ->call('markRead', $notification->id);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks all notifications as read at once', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);
    BookingConfirmed::dispatch($booking);

    Livewire::actingAs($this->operator, 'web')
        ->test(NotificationsBell::class)
        ->call('markAllRead');

    $unread = DatabaseNotification::query()
        ->where('notifiable_id', $this->operator->id)
        ->whereNull('read_at')
        ->count();
    expect($unread)->toBe(0);
});

it('does NOT let operator A mark operator B\'s notification read', function (): void {
    $operatorB = Operator::factory()->for($this->tenant)->create();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    BookingConfirmed::dispatch($booking);

    $bsNotification = DatabaseNotification::query()
        ->where('notifiable_id', $operatorB->id)
        ->first();
    expect($bsNotification)->not->toBeNull();

    // Operator A tries to mark operator B's row as read.
    Livewire::actingAs($this->operator, 'web')
        ->test(NotificationsBell::class)
        ->call('markRead', $bsNotification->id);

    expect($bsNotification->fresh()->read_at)->toBeNull();
});
