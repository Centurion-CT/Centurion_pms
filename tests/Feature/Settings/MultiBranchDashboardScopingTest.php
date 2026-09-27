<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PropertyType;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Payments\Index as PaymentsIndex;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\ReportSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Confirms the sidebar branch switcher meaningfully filters the
 * dashboard: booking + payment + report totals show only the
 * currently-selected branch's numbers.
 *
 * Setup pattern: two branches (Lagos + Abuja), each with one room
 * and one booking. Switching the operator into Lagos should hide
 * Abuja's row/total across every scoped surface.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'branch-scope']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->lagos = Property::factory()->for($this->tenant)->create([
        'name' => 'Lagos',
        'type' => PropertyType::Hotel,
    ]);
    $this->abuja = Property::factory()->for($this->tenant)->create([
        'name' => 'Abuja',
        'type' => PropertyType::Hotel,
    ]);

    $this->lagosCat = RoomCategory::factory()->for($this->tenant)->for($this->lagos)->create(['name' => 'L-Std', 'base_rate' => 40_000]);
    $this->lagosRoom = Room::factory()->for($this->tenant)->for($this->lagos)->for($this->lagosCat, 'category')->create();

    $this->abujaCat = RoomCategory::factory()->for($this->tenant)->for($this->abuja)->create(['name' => 'A-Std', 'base_rate' => 60_000]);
    $this->abujaRoom = Room::factory()->for($this->tenant)->for($this->abuja)->for($this->abujaCat, 'category')->create();

    // One booking per branch — both CheckedIn today, both with a
    // succeeded payment. Switcher should split the totals cleanly.
    $this->lagosBooking = Booking::factory()->for($this->tenant)->for($this->lagosRoom)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDays(2),
        'total_amount' => 40_000,
        'room_total' => 40_000,
        'nights' => 2,
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $this->lagosBooking->id,
        'amount' => 40_000,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::now(),
    ]);

    $this->abujaBooking = Booking::factory()->for($this->tenant)->for($this->abujaRoom)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDays(2),
        'total_amount' => 60_000,
        'room_total' => 60_000,
        'nights' => 2,
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $this->abujaBooking->id,
        'amount' => 60_000,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::now(),
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => now(),
        'phone' => '+2348012345678',
        'phone_verified_at' => now(),
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('Reservations in-house count only shows the picked branch', function (): void {
    // Operator picks Lagos.
    $this->manager->forceFill(['current_property_id' => $this->lagos->id])->save();

    Livewire::actingAs($this->manager->fresh(), 'web')
        ->test(ReservationsIndex::class)
        ->assertViewHas('counts', fn (array $c) => $c['in_house'] === 1);

    // Now switch to Abuja — same test, different count.
    $this->manager->forceFill(['current_property_id' => $this->abuja->id])->save();

    Livewire::actingAs($this->manager->fresh(), 'web')
        ->test(ReservationsIndex::class)
        ->assertViewHas('counts', fn (array $c) => $c['in_house'] === 1);
});

it('FinanceSnapshot kpis filter by current branch', function (): void {
    $from = Carbon::today()->subDay();
    $to = Carbon::today()->addDay();
    $snapshot = app(FinanceSnapshot::class);

    // Lagos context — only its ₦40k booking counts.
    $this->manager->forceFill(['current_property_id' => $this->lagos->id])->save();
    $this->actingAs($this->manager->fresh(), 'web');
    $lagosKpis = $snapshot->kpis($from, $to);
    expect((int) $lagosKpis['billed'])->toBe(40_000);
    expect((int) $lagosKpis['collected'])->toBe(40_000);

    // Force a fresh FinanceSnapshot instance because the current-property
    // id is cached per instance for the request cycle.
    app()->forgetInstance(FinanceSnapshot::class);

    // Abuja context — only its ₦60k booking counts.
    $this->manager->forceFill(['current_property_id' => $this->abuja->id])->save();
    $this->actingAs($this->manager->fresh(), 'web');
    $abujaKpis = app(FinanceSnapshot::class)->kpis($from, $to);
    expect((int) $abujaKpis['billed'])->toBe(60_000);
    expect((int) $abujaKpis['collected'])->toBe(60_000);
});

it('ReportSnapshot revenue filters by current branch', function (): void {
    $from = Carbon::today()->subDay();
    $to = Carbon::today()->addDay();

    $this->manager->forceFill(['current_property_id' => $this->lagos->id])->save();
    $this->actingAs($this->manager->fresh(), 'web');
    $lagosRev = app(ReportSnapshot::class)->revenue($from, $to);
    expect((int) $lagosRev['total'])->toBe(40_000);
    expect((int) $lagosRev['bookings'])->toBe(1);

    app()->forgetInstance(ReportSnapshot::class);

    $this->manager->forceFill(['current_property_id' => $this->abuja->id])->save();
    $this->actingAs($this->manager->fresh(), 'web');
    $abujaRev = app(ReportSnapshot::class)->revenue($from, $to);
    expect((int) $abujaRev['total'])->toBe(60_000);
    expect((int) $abujaRev['bookings'])->toBe(1);
});

it('sitemap.xml lists every branch\'s URLs for a multi-property tenant', function (): void {
    // Live so sitemap accepts them (isSectionVisible checks aren't in
    // the sitemap loop but rooms need active).
    $this->lagos->update(['is_live' => true]);
    $this->abuja->update(['is_live' => true]);

    $response = $this->get('http://branch-scope.keverna.test/sitemap.xml');

    $response->assertOk();
    $body = (string) $response->getContent();
    // Each branch landing.
    expect($body)->toContain('/branches/'.$this->lagos->slug.'/');
    expect($body)->toContain('/branches/'.$this->abuja->slug.'/');
    // Per-branch rooms browse.
    expect($body)->toContain('/branches/'.$this->lagos->slug.'/rooms');
    expect($body)->toContain('/branches/'.$this->abuja->slug.'/rooms');
    // Individual rooms.
    expect($body)->toContain('/branches/'.$this->lagos->slug.'/rooms/'.$this->lagosRoom->id);
    expect($body)->toContain('/branches/'.$this->abuja->slug.'/rooms/'.$this->abujaRoom->id);
});
