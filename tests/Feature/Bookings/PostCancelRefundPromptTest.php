<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * When a reservation is cancelled and there's held cash (paid −
 * refunded > 0), the Show page pops a refund modal so the operator
 * can process the money back on the spot. If they close it, the
 * booking still shows up on the Refunds page for later — but the
 * "did they see it?" question is answered by the prompt.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'post-cancel-refund']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Ada Manager',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('opens the post-cancel refund modal when there is held cash', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 80_000,
        'checkin_date' => Carbon::today()->addDay()->toDateString(),
        'checkout_date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $b->id, 'amount' => 80_000,
        'type' => PaymentType::Full, 'paid_at' => Carbon::now()->subDay(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('cancel')
        ->assertSet('showPostCancelRefund', true)
        ->assertSet('postCancelHeld', 80_000)
        ->assertSet('postCancelAmount', 80_000);

    // Booking is cancelled either way.
    expect($b->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
});

it('does NOT open the modal when there was no payment', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 80_000,
        'checkin_date' => Carbon::today()->addDay()->toDateString(),
        'checkout_date' => Carbon::today()->addDays(2)->toDateString(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('cancel')
        ->assertSet('showPostCancelRefund', false);
});

it('does NOT re-open the modal when the paid amount was already fully refunded', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 60_000,
        'checkin_date' => Carbon::today()->addDay()->toDateString(),
        'checkout_date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $b->id, 'amount' => 60_000,
        'type' => PaymentType::Full, 'paid_at' => Carbon::now()->subDay(),
    ]);
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $b->id, 'amount' => 60_000,
        'status' => PaymentStatus::Refunded, 'type' => PaymentType::Full,
        'paid_at' => Carbon::now(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('cancel')
        ->assertSet('showPostCancelRefund', false);
});

it('submitPostCancelRefund records a refund + closes the modal', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 50_000,
        'checkin_date' => Carbon::today()->addDay()->toDateString(),
        'checkout_date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $b->id, 'amount' => 50_000,
        'type' => PaymentType::Full, 'paid_at' => Carbon::now()->subDay(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('cancel')
        ->set('postCancelAmount', 50_000)
        ->set('postCancelMethod', 'transfer')
        ->set('postCancelNote', 'ref 12345')
        ->call('submitPostCancelRefund')
        ->assertSet('showPostCancelRefund', false)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    $refund = Payment::query()
        ->where('booking_id', $b->id)
        ->where('status', PaymentStatus::Refunded)
        ->latest('id')->first();
    expect($refund)->not->toBeNull();
    expect((int) $refund->amount)->toBe(50_000);
    expect((string) $refund->method)->toBe('transfer');
});

it('rejects a refund larger than the held cash', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'total_amount' => 40_000,
        'checkin_date' => Carbon::today()->addDay()->toDateString(),
        'checkout_date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $b->id, 'amount' => 40_000,
        'type' => PaymentType::Full, 'paid_at' => Carbon::now()->subDay(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('cancel')
        ->set('postCancelAmount', 999_999)
        ->call('submitPostCancelRefund')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    expect(Payment::query()->where('booking_id', $b->id)
        ->where('status', PaymentStatus::Refunded)->count())->toBe(0);
});
