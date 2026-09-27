<?php

declare(strict_types=1);

use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\GuestRequests\Index as GuestRequestsIndex;
use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\GuestPortal\GuestNotifier;
use Livewire\Livewire;

/**
 * When an operator marks a guest request resolved, we WhatsApp the
 * guest a heads-up. Delivery is best-effort (caught + logged);
 * failures never block the resolve action.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gr-notify']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create(['name' => 'Notifier Inn']);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => now(),
        'phone' => '+2348011111111',
        'phone_verified_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('sends a WhatsApp notice when a request is resolved', function (): void {
    $sent = [];
    app()->instance(GuestNotifier::class, new class ($sent) extends GuestNotifier {
        public function __construct(private array &$out) {
            parent::__construct(app(\App\Services\Sendpipe\SendpipeClient::class));
        }
        public function sendRequestResolvedNotice(string $phone, string $message): void
        {
            $this->out[] = ['phone' => $phone, 'message' => $message];
        }
    });

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_phone' => '+2348022222222',
    ]);
    $req = GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $booking->id,
        'room_id' => $this->room->id, 'kind' => GuestRequestKind::Taxi->value,
        'status' => 'open',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(GuestRequestsIndex::class)
        ->call('resolve', $req->id);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['phone'])->toBe('+2348022222222');
    expect($sent[0]['message'])->toContain('Notifier Inn');
    expect($sent[0]['message'])->toContain('taxi request');
});

it('skips notification when the guest has no phone on file', function (): void {
    $sent = [];
    app()->instance(GuestNotifier::class, new class ($sent) extends GuestNotifier {
        public function __construct(private array &$out) {
            parent::__construct(app(\App\Services\Sendpipe\SendpipeClient::class));
        }
        public function sendRequestResolvedNotice(string $phone, string $message): void
        {
            $this->out[] = ['phone' => $phone, 'message' => $message];
        }
    });

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_phone' => '']);
    $req = GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $booking->id,
        'room_id' => $this->room->id, 'kind' => GuestRequestKind::Complaint->value,
        'status' => 'open',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(GuestRequestsIndex::class)
        ->call('resolve', $req->id);

    expect($sent)->toHaveCount(0);
    // Request still resolved regardless.
    expect($req->fresh()->status)->toBe('resolved');
});

it('notifier delivery failure does not block the resolve action', function (): void {
    app()->instance(GuestNotifier::class, new class extends GuestNotifier {
        public function __construct() {
            parent::__construct(app(\App\Services\Sendpipe\SendpipeClient::class));
        }
        public function sendRequestResolvedNotice(string $phone, string $message): void
        {
            throw new \RuntimeException('Sendpipe is down');
        }
    });

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create(['guest_phone' => '+2348033333333']);
    $req = GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $booking->id,
        'room_id' => $this->room->id, 'kind' => GuestRequestKind::Taxi->value,
        'status' => 'open',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(GuestRequestsIndex::class)
        ->call('resolve', $req->id);

    // Still marked resolved despite the notifier throw.
    expect($req->fresh()->status)->toBe('resolved');
});
