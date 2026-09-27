<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Refunds\Index as RefundsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Refunds module — surfaces bookings that took cash but never got
 * a refund logged. Cancelled + no-show, held = paid − refunded > 0.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'refunds-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Ada Manager',
    ]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);
    $this->porter = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Porter,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

function seedPaidBooking(Tenant $t, Room $r, BookingStatus $status, string $guest, int $amount): Booking
{
    $b = Booking::factory()->for($t)->for($r)->create([
        'guest_name' => $guest,
        'booking_status' => $status,
        'total_amount' => $amount,
        'balance_due' => 0,
    ]);
    Payment::factory()->for($t)->succeeded()->create([
        'booking_id' => $b->id,
        'amount' => $amount,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::now()->subDay(),
    ]);
    return $b;
}

/* ── List semantics ── */

it('shows cancelled + no-show bookings where cash is still held', function (): void {
    $cancelledPaid = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Kept Cash', 60_000);
    $noShow = seedPaidBooking($this->tenant, $this->room, BookingStatus::NoShow, 'Noshow Guest', 40_000);
    // Cancelled but fully refunded — should NOT appear.
    $refunded = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Refunded Guest', 30_000);
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $refunded->id,
        'amount' => 30_000,
        'status' => PaymentStatus::Refunded,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::now(),
    ]);
    // Checked-out (not cancelled) — should NOT appear even if paid.
    seedPaidBooking($this->tenant, $this->room, BookingStatus::CheckedOut, 'Normal Stay', 90_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->assertSee('Kept Cash')
        ->assertSee('Noshow Guest')
        ->assertDontSee('Refunded Guest')
        ->assertDontSee('Normal Stay');
});

it('surfaces the partial-refund remainder (held > 0 but < paid)', function (): void {
    $b = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Partial Refund', 100_000);
    // Refund only ₦40k of the ₦100k
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $b->id,
        'amount' => 40_000,
        'status' => PaymentStatus::Refunded,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::now(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->assertSee('Partial Refund')
        // ₦60k still held.
        ->assertSeeHtml('&#8358;60,000');
});

it('status filter narrows to just cancelled or just no-show', function (): void {
    seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Ada Cancelled', 60_000);
    seedPaidBooking($this->tenant, $this->room, BookingStatus::NoShow, 'Ola Noshow', 40_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->set('statusFilter', 'cancelled')
        ->assertSee('Ada Cancelled')
        ->assertDontSee('Ola Noshow');

    Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->set('statusFilter', 'no_show')
        ->assertSee('Ola Noshow')
        ->assertDontSee('Ada Cancelled');
});

it('KPI totals reflect the filtered set', function (): void {
    seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'A', 60_000);
    seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'B', 40_000);
    seedPaidBooking($this->tenant, $this->room, BookingStatus::NoShow, 'C', 30_000);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->html();
    expect($html)->toMatch('/Unresolved.*3/s');
    // Total held ₦130k.
    expect($html)->toContain('&#8358;130,000');
});

/* ── Role gate ── */

it('front desk operator can access the refunds page', function (): void {
    $response = $this->actingAs($this->frontdesk, 'web')
        ->get('http://refunds-test.keverna.test/dashboard/refunds');
    $response->assertOk();
});

it('porter (no refunds module) is redirected away', function (): void {
    $response = $this->actingAs($this->porter, 'web')
        ->get('http://refunds-test.keverna.test/dashboard/refunds');
    $response->assertRedirect();
});

/* ── Detail panel ── */

it('emits a client-side panel payload with cancellation trail for each row', function (): void {
    $b = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Trail Guest', 60_000);
    // Log a cancel event with a note + causer so the panel has something to show.
    activity('booking')
        ->causedBy($this->manager)
        ->performedOn($b)
        ->event('cancelled')
        ->withProperties(['deposit_forfeit' => true, 'refund_requested' => false, 'days_until_checkin' => 0])
        ->log('Operator Ada Manager cancelled booking '.$b->booking_ref.'.');

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class);
    $panels = $view->viewData('panels');

    expect($panels)->toHaveKey($b->id);
    expect($panels[$b->id]['guest_name'])->toBe('Trail Guest');
    expect($panels[$b->id]['held'])->toBe(60_000);
    expect($panels[$b->id]['cancel'])->not->toBeNull();
    expect($panels[$b->id]['cancel']['kind'])->toBe('cancelled');
    expect($panels[$b->id]['cancel']['by'])->toContain('Manager');
    expect($panels[$b->id]['cancel']['deposit_forfeit'])->toBeTrue();

    // Row markup dispatches the same id.
    expect($view->html())->toContain("refunds-panel-open', { id: {$b->id} }");
});

/* ── Record refund from panel ── */

it('records a refund inline via the panel + caps at the held amount', function (): void {
    $b = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Refund Me', 80_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->call('recordRefund', $b->id, 30_000, 'cash', 'partial refund test')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    // A refund Payment row landed against the booking.
    $refund = Payment::query()
        ->where('booking_id', $b->id)
        ->where('status', PaymentStatus::Refunded)
        ->latest('id')->first();
    expect($refund)->not->toBeNull();
    expect((int) $refund->amount)->toBe(30_000);
    expect((string) $refund->method)->toBe('cash');
});

it('refuses to over-refund past the held amount', function (): void {
    $b = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Cap Test', 40_000);

    Livewire::actingAs($this->manager, 'web')
        ->test(RefundsIndex::class)
        ->call('recordRefund', $b->id, 999_999, 'cash', null)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    expect(Payment::query()->where('booking_id', $b->id)
        ->where('status', PaymentStatus::Refunded)->count())->toBe(0);
});

/* ── CSV export ── */

it('CSV export streams only pending-refund rows', function (): void {
    $keep = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Kept Cash', 60_000);
    $refunded = seedPaidBooking($this->tenant, $this->room, BookingStatus::Cancelled, 'Refunded', 30_000);
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $refunded->id,
        'amount' => 30_000,
        'status' => PaymentStatus::Refunded,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::now(),
    ]);

    $this->actingAs($this->manager, 'web');
    $response = $this->get(route('tenant.refunds.export'));
    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Kept Cash');
    expect($csv)->toContain($keep->booking_ref);
    // The fully-refunded booking (held = 0) shouldn't be in the
    // export. Assert against its ref, not the word "Refunded" —
    // that string legitimately appears as a column header + title.
    expect($csv)->not->toContain($refunded->booking_ref);
});
