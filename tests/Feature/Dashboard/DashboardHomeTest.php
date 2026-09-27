<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Livewire\Dashboard\Home;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'dash-home']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['name' => 'Op Home']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
    Cache::flush();
});

/* ---------- HTTP render ---------- */

it('renders the dashboard home for an onboarded operator', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://dash-home.keverna.test/dashboard');

    $response->assertOk();
    $response->assertSeeLivewire(Home::class);
    // Global Livewire sync indicator must render on every authed page —
    // it's what surfaces "syncing" / "connection issue" back to the
    // front desk when the network is flaky.
    $response->assertSeeHtml('data-testid="wire-sync-status"');
});

/* ---------- Empty state ---------- */

it('shows empty states when there is no data', function (): void {
    Room::query()->forceDelete(); // also drop the seed room

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-testid="no-arrivals"')
        ->assertSeeHtml('data-testid="no-departures"')
        ->assertSeeHtml('data-testid="no-rooms-heatmap"')
        ->assertSeeHtml('data-testid="no-outstanding"')
        ->assertSeeHtml('data-testid="no-recent"');
});

/* ---------- Populated state ---------- */

it('lists today\'s arrivals and departures', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Arrives Today',
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Departs Today',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-15',
    ]);
    // (Intentionally NOT creating a future-dated booking here — Recent
    // Bookings shows the latest 5 regardless of checkin date, so a far-
    // future guest would also appear and the assertions would conflict.
    // The "exclusion" guarantee is covered by widget-level assertions: the
    // arrival/departure widgets only render rows that match today's date.)

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-testid="arrival-row"')
        ->assertSeeHtml('data-testid="departure-row"')
        ->assertSee('Arrives Today')
        ->assertSee('Departs Today');
});

it('shows outstanding-balance bookings', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'guest_name' => 'Owes Money',
        'total_amount' => 100_000,
        'deposit_amount' => 30_000,
        'balance_due' => 70_000,
        'balance_due_at' => Carbon::tomorrow(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSee('Owes Money')
        ->assertSee('₦70,000');
});

it('shows the 14-day heatmap with cells marked as free/booked/blocked', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));

    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2030-09-16']);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2030-09-17', 'type' => AvailabilityBlockType::Blocked]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-state="booked"')
        ->assertSeeHtml('data-state="blocked"')
        ->assertSeeHtml('data-state="free"');
});

it('shows occupancy percent in the widget', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));

    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->createMany([
            ['date' => '2030-09-10'], ['date' => '2030-09-11'], ['date' => '2030-09-12'],
        ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-testid="occupancy-percent"')
        ->assertSee('10%');  // 3 of 30 = 10%
});

/* ---------- Cache + isolation ---------- */

it('caches the snapshot per tenant for 60s and forget() drops it', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Mr First',
        'checkin_date' => Carbon::today(),
    ]);

    /** @var DashboardSnapshot $svc */
    $svc = app(DashboardSnapshot::class);
    $first = $svc->build();
    expect($first['today']['arrivals']->count())->toBe(1);

    // Add another arrival but don't bust the cache — the cached value still applies.
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Mr Second',
        'checkin_date' => Carbon::today(),
    ]);
    $stillCached = $svc->build();
    expect($stillCached['today']['arrivals']->count())->toBe(1);

    DashboardSnapshot::forget();
    $fresh = $svc->build();
    expect($fresh['today']['arrivals']->count())->toBe(2);
});

it('does NOT show another tenant\'s arrivals on this dashboard', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Mine Today',
        'checkin_date' => '2030-09-15',
    ]);

    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'dash-home-other']);
    $other->makeCurrent();
    $otherProp = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    Booking::factory()->for($other)->for($otherRoom)->confirmed()->create([
        'guest_name' => 'Their Today',
        'checkin_date' => '2030-09-15',
    ]);
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSee('Mine Today')
        ->assertDontSee('Their Today');
});

it('exposes today\'s arrivals through the quick check-in trigger + list', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Ada Arriving',
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-testid="dash-quick-checkin-trigger"')
        ->assertSeeHtml('data-testid="dash-quick-checkin-count"')
        ->assertSeeHtml('data-testid="dash-quick-checkin-panel"')
        ->assertSee('Ada Arriving');
});

it('quickCheckIn flips a Confirmed same-day booking to CheckedIn', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Bola Confirming',
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('quickCheckIn', $b->id);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('Today at a glance surfaces in-house guests with per-row Extend + Check out actions', function (): void {
    // In-house guest whose booked checkout is a few nights away —
    // shouldn't appear in the arrivals/departures spotlight, should
    // appear in the new "Currently in-house" subsection with quick
    // actions rendered per row.
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    $inHouseGuest = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Chi Occupies',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-18', // 3 nights out
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-testid="widget-today-inhouse"')
        ->assertSee('Chi Occupies')
        ->assertSeeHtml('data-testid="widget-today-inhouse-extend-'.$inHouseGuest->booking_ref.'"')
        ->assertSeeHtml('data-testid="widget-today-inhouse-checkout-'.$inHouseGuest->booking_ref.'"')
        // Detail panel (client-side, rendered inline via x-teleport) has
        // a Print action that pops a thermal-receipt window using the
        // panel data. Assert the button + JS factory are wired.
        ->assertSeeHtml('data-testid="widget-today-inhouse-panel-print"')
        ->assertSeeHtml('window.inHouseGuestPanel');
});

it('quick check-out panel also lists early-departure candidates (not just today)', function (): void {
    // Regression: front desk complained the check-out panel didn't
    // show guests who wanted to leave early. It was scoped to
    // today['departures'] (checkout_date == today) only.
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada DueToday',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-15',
        'balance_due' => 0,
    ]);
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create();
    Booking::factory()->for($this->tenant)->for($roomB)->create([
        'guest_name' => 'Bola EarlyLeaver',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-18', // booked to stay another 3 nights
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSee('Ada DueToday')
        ->assertSee('Bola EarlyLeaver')
        ->assertSeeHtml('data-testid="dash-quick-checkout-tag-today"');
});

it('exposes today\'s departures through the quick check-out trigger + list', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Cee Departing',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-15',
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->assertSeeHtml('data-testid="dash-quick-checkout-trigger"')
        ->assertSeeHtml('data-testid="dash-quick-checkout-count"')
        ->assertSeeHtml('data-testid="dash-quick-checkout-panel"')
        ->assertSee('Cee Departing');
});

it('quickCheckOut flips a CheckedIn zero-balance booking to CheckedOut', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Dupe Departing',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-15',
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('quickCheckOut', $b->id);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedOut);
});

it('quickCheckOut refuses when the guest still owes money', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 09:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Emeka Owes',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-13',
        'checkout_date' => '2030-09-15',
        'balance_due' => 30_000,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Home::class)
        ->call('quickCheckOut', $b->id);

    // Server-side guard fires (runBookingAction dispatches a notify,
    // does NOT throw), so the booking stays CheckedIn.
    expect($b->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('does NOT surface another tenant\'s activity feed on this dashboard', function (): void {
    // Regression: DashboardSnapshot previously queried Spatie's base Activity
    // model directly, bypassing App\Models\Activity's tenant global scope, so
    // a brand-new tenant would see other tenants' "checked in", "wallet
    // payment recorded", etc. rows on their dashboard.
    $other = Tenant::factory()->create(['slug' => 'dash-home-activity-other']);

    DB::table('activity_log')->insert([
        [
            'tenant_id' => $other->getKey(),
            'log_name' => 'booking',
            'description' => 'checked in',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => $this->tenant->getKey(),
            'log_name' => 'booking',
            'description' => 'mine only',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    /** @var DashboardSnapshot $svc */
    $svc = app(DashboardSnapshot::class);
    $snap = $svc->build();

    $descriptions = $snap['activity']->pluck('description')->all();
    expect($descriptions)->toContain('mine only');
    expect($descriptions)->not->toContain('checked in');
});
