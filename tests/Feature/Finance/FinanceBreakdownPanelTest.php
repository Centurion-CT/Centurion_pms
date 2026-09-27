<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Dashboard\Finance\Index as FinanceIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Finance page: click a row on the Channel / Category / Top-room
 * table → a client-side breakdown panel opens with pre-hydrated
 * bookings for that grouping. Verifies:
 *   - Row is a clickable dispatch (no wire trip)
 *   - The Alpine factory + payload map is emitted
 *   - Each breakdown key contains only the bookings that match its
 *     grouping
 */
beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'fin-breakdown']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->catA = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
        'name' => 'Deluxe studio',
    ]);
    $this->catB = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 30_000, 'min_nights' => 1,
        'name' => 'Standard',
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->catA->id,
        'room_number' => '206',
    ]);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->catB->id,
        'room_number' => '311',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
    Cache::flush();
});

it('emits clickable rows + a hydrated breakdown payload for category / room / channel', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));

    // Booking A — Deluxe / Rm 206 / Walk-in
    Booking::factory()->for($this->tenant)->for($this->roomA)->confirmed()->create([
        'guest_name' => 'Chi Guest',
        'checkin_date' => '2030-06-10',
        'checkout_date' => '2030-06-11',
        'nights' => 1,
        'total_amount' => 50_000,
        'source' => BookingSource::WalkIn,
    ]);
    // Booking B — Standard / Rm 311 / Airbnb
    Booking::factory()->for($this->tenant)->for($this->roomB)->create([
        'guest_name' => 'Ada Guest',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-06-11',
        'checkout_date' => '2030-06-13',
        'nights' => 2,
        'total_amount' => 90_000,
        'source' => BookingSource::Airbnb,
    ]);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->html();

    // Panel factory + payload emitted once.
    expect($html)->toContain('window.financeBreakdownPanel');
    expect($html)->toContain('data-testid="finance-breakdown-panel"');

    // Row dispatches for each grouping.
    expect($html)->toContain("id: 'category:{$this->catA->id}'");
    expect($html)->toContain("id: 'category:{$this->catB->id}'");
    expect($html)->toContain("id: 'room:{$this->roomA->id}'");
    expect($html)->toContain("id: 'room:{$this->roomB->id}'");
    expect($html)->toContain("id: 'channel:walk_in'");
    expect($html)->toContain("id: 'channel:airbnb'");

    // Payload contains the right guest per grouping.
    // (Loose asserts — payload is JSON-encoded so the guest name
    // appears verbatim in the serialised script.)
    expect($html)->toContain('Chi Guest');
    expect($html)->toContain('Ada Guest');
});

it('filters bookings correctly when the row is clicked (via the payload contents)', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));

    Booking::factory()->for($this->tenant)->for($this->roomA)->confirmed()->create([
        'guest_name' => 'Alpha',
        'checkin_date' => '2030-06-10',
        'total_amount' => 40_000,
        'source' => BookingSource::WalkIn,
    ]);
    Booking::factory()->for($this->tenant)->for($this->roomB)->confirmed()->create([
        'guest_name' => 'Beta',
        'checkin_date' => '2030-06-10',
        'total_amount' => 60_000,
        'source' => BookingSource::WalkIn,
    ]);

    $view = Livewire::actingAs($this->manager, 'web')->test(FinanceIndex::class);
    $breakdowns = $view->viewData('breakdowns');

    // Category A only has Alpha; Category B only has Beta.
    $catAKey = 'category:'.$this->catA->id;
    $catBKey = 'category:'.$this->catB->id;
    expect($breakdowns[$catAKey]['bookings'])->toHaveCount(1);
    expect($breakdowns[$catAKey]['bookings'][0]['guest'])->toBe('Alpha');
    expect($breakdowns[$catBKey]['bookings'])->toHaveCount(1);
    expect($breakdowns[$catBKey]['bookings'][0]['guest'])->toBe('Beta');

    // Channel key aggregates across both.
    expect($breakdowns['channel:walk_in']['bookings'])->toHaveCount(2);
});

/* ── Cash flow week ── */

it('cash flow week rows are clickable and their payload carries daily rows', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    // Two payments on different days in the same ISO week.
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'amount' => 120_000,
        'paid_at' => Carbon::parse('2030-06-10 12:00:00'),
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'amount' => 30_000,
        'paid_at' => Carbon::parse('2030-06-12 15:00:00'),
    ]);

    $view = Livewire::actingAs($this->manager, 'web')->test(FinanceIndex::class);
    $breakdowns = $view->viewData('breakdowns');

    // The week 2030-W24 covers Jun 10 & Jun 12.
    $weekKey = 'week:'.Carbon::parse('2030-06-10')->isoWeekYear()
        .'-W'.str_pad((string) Carbon::parse('2030-06-10')->isoWeek(), 2, '0', STR_PAD_LEFT);
    expect($breakdowns)->toHaveKey($weekKey);
    expect($breakdowns[$weekKey]['kind'])->toBe('daily');
    // Two distinct days should have activity in the daily array.
    $activeDays = collect($breakdowns[$weekKey]['daily'])->filter(fn ($d) => $d['collected'] > 0);
    expect($activeDays)->toHaveCount(2);

    // Row markup dispatches the same key.
    expect($view->html())->toContain("id: '{$weekKey}'");
});

/* ── Ledger single-payment ── */

it('ledger rows are clickable and their payload is a single-transaction detail', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->roomA)->confirmed()->create([
        'guest_name' => 'Ledger Guest',
        'checkin_date' => '2030-06-10',
        'total_amount' => 60_000,
        'source' => BookingSource::WalkIn,
    ]);
    $p = Payment::factory()->for($this->tenant)->succeeded()->create([
        'amount' => 60_000,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::parse('2030-06-11 10:00:00'),
        'booking_id' => $booking->id,
    ]);

    $view = Livewire::actingAs($this->manager, 'web')->test(FinanceIndex::class);
    $breakdowns = $view->viewData('breakdowns');

    $key = 'ledger:'.$p->id;
    expect($breakdowns)->toHaveKey($key);
    expect($breakdowns[$key]['kind'])->toBe('ledger_detail');
    expect($breakdowns[$key]['title'])->toBe('Ledger Guest');
    expect($breakdowns[$key]['open_url'])->toContain($booking->booking_ref);

    // Row markup dispatches the same key.
    expect($view->html())->toContain("id: 'ledger:{$p->id}'");
});
