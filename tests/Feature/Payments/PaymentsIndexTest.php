<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Payments\Index as PaymentsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'payments-page']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'room_number' => 210,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Ada Manager',
    ]);
    $this->cashier = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'name' => 'Bola Cashier',
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function makePayment(Tenant $t, Room $r, array $overrides = []): Payment
{
    $booking = Booking::factory()->for($t)->for($r)->confirmed()->create([
        'guest_name' => $overrides['guest_name'] ?? 'Ada Guest',
        'guest_phone' => $overrides['guest_phone'] ?? '+2348011112222',
        'nights' => 2,
        'room_total' => 100_000,
    ]);
    return Payment::factory()->for($t)->for($booking)->succeeded()->create([
        'amount' => $overrides['amount'] ?? 100_000,
        'method' => $overrides['method'] ?? 'cash',
        'paid_at' => $overrides['paid_at'] ?? Carbon::today(),
        'collected_by_operator_id' => $overrides['collected_by'] ?? null,
    ]);
}

it('defaults to today and only shows payments paid today', function (): void {
    makePayment($this->tenant, $this->room, ['paid_at' => Carbon::today()->setTime(10, 0)]);
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
    ]);
    makePayment($this->tenant, $roomB, ['paid_at' => Carbon::yesterday()]);

    Livewire::test(PaymentsIndex::class)
        ->assertSet('dateFrom', Carbon::today()->toDateString())
        ->assertSet('dateTo', Carbon::today()->toDateString())
        ->assertViewHas('payments', fn ($p) => $p->count() === 1);
});

it('filters by payment method', function (): void {
    makePayment($this->tenant, $this->room, ['method' => 'cash']);
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
    ]);
    makePayment($this->tenant, $roomB, ['method' => 'transfer']);

    Livewire::test(PaymentsIndex::class)
        ->set('methodFilter', 'transfer')
        ->assertViewHas('payments', function ($p) {
            return $p->count() === 1 && $p->first()->method === 'transfer';
        });
});

it('filters by staff who collected the payment', function (): void {
    makePayment($this->tenant, $this->room, ['collected_by' => $this->cashier->id]);
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
    ]);
    makePayment($this->tenant, $roomB, ['collected_by' => $this->manager->id]);

    Livewire::test(PaymentsIndex::class)
        ->set('staffFilter', (string) $this->cashier->id)
        ->assertViewHas('payments', function ($p) {
            return $p->count() === 1
                && $p->first()->collected_by_operator_id === $this->cashier->id;
        });
});

it('searches by customer phone', function (): void {
    makePayment($this->tenant, $this->room, ['guest_phone' => '+2348099998888']);
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
    ]);
    makePayment($this->tenant, $roomB, ['guest_phone' => '+2348011112222']);

    Livewire::test(PaymentsIndex::class)
        ->set('search', '9999')
        ->assertViewHas('payments', function ($p) {
            return $p->count() === 1 && str_contains($p->first()->booking->guest_phone, '9999');
        });
});

it('searches by room number', function (): void {
    makePayment($this->tenant, $this->room); // room 210
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => 305,
    ]);
    makePayment($this->tenant, $roomB);

    Livewire::test(PaymentsIndex::class)
        ->set('search', '305')
        ->assertViewHas('payments', function ($p) {
            return $p->count() === 1 && (int) $p->first()->booking->room->room_number === 305;
        });
});

it('sorts by staff on demand', function (): void {
    makePayment($this->tenant, $this->room, ['collected_by' => $this->manager->id]);
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
    ]);
    makePayment($this->tenant, $roomB, ['collected_by' => $this->cashier->id]);

    Livewire::test(PaymentsIndex::class)
        ->call('toggleSort', 'staff')
        ->assertSet('sortBy', 'staff')
        ->assertSet('sortDir', 'asc');
});

it('opens a detail panel for a clicked payment', function (): void {
    $payment = makePayment($this->tenant, $this->room, ['collected_by' => $this->cashier->id]);

    Livewire::test(PaymentsIndex::class)
        ->call('openDetail', $payment->id)
        ->assertSet('openPaymentId', $payment->id)
        ->assertViewHas('openPayment', fn ($p) => $p?->id === $payment->id)
        ->assertSeeHtml('data-testid="payment-detail-panel"');
});

it('csv export streams a file whose header row includes staff and method', function (): void {
    makePayment($this->tenant, $this->room, ['collected_by' => $this->cashier->id]);

    $response = $this->get(
        'http://payments-page.'.config('keverna.central_domain').'/dashboard/payments/export.csv'
        .'?from='.Carbon::today()->toDateString().'&to='.Carbon::today()->toDateString()
    );
    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('text/csv');
    $body = $response->streamedContent();
    expect($body)->toContain('Staff')
        ->toContain('Method')
        ->toContain('Discount')
        ->toContain('Bola Cashier');
});
