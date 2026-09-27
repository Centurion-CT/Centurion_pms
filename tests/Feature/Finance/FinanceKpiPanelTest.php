<?php

declare(strict_types=1);

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
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * KPI-level breakdown panels on Finance. Clicking a tile fires
 * openKpi(key) which loads the underlying items server-side. The
 * panel view branches on kind (bookings vs payments) to render.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'fin-kpi']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

it('renders tile buttons wired to openKpi', function (): void {
    $html = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->html();
    // Every clickable tile carries a wire:click="openKpi('key')"
    // attribute — one per supported key.
    foreach (['billed', 'collected', 'outstanding', 'refunded', 'forecast'] as $key) {
        expect($html)->toContain("wire:click=\"openKpi('{$key}')\"");
    }
});

it('billed panel loads bookings whose checkin is in the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Alpha', 'checkin_date' => '2030-06-10',
        'total_amount' => 50_000,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Beta', 'checkin_date' => '2030-06-11',
        'total_amount' => 30_000,
    ]);
    // Outside the last-30-day window when today=2030-06-15.
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'OutOfWindow', 'checkin_date' => '2030-04-01',
        'total_amount' => 999_999,
    ]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->call('openKpi', 'billed');
    $panel = $view->viewData('kpiPanel');

    expect($panel['title'])->toBe('Billed');
    expect($panel['kind'])->toBe('bookings');
    $guests = collect($panel['rows'])->pluck('guest')->all();
    expect($guests)->toContain('Alpha');
    expect($guests)->toContain('Beta');
    expect($guests)->not->toContain('OutOfWindow');
    Carbon::setTestNow();
});

it('collected panel loads succeeded payments in the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Payer',
        'checkin_date' => '2030-06-10',
        'total_amount' => 60_000,
    ]);
    Payment::factory()->for($this->tenant)->succeeded()->create([
        'booking_id' => $b->id,
        'amount' => 60_000,
        'paid_at' => Carbon::parse('2030-06-11 12:00:00'),
    ]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->call('openKpi', 'collected');
    $panel = $view->viewData('kpiPanel');

    expect($panel['title'])->toBe('Cash collected');
    expect($panel['kind'])->toBe('payments');
    expect(collect($panel['rows'])->pluck('guest')->all())->toContain('Payer');
    Carbon::setTestNow();
});

it('refunded panel loads only refund transactions', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-15 09:00:00'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Cancelled,
        'guest_name' => 'RefundGuy',
        'total_amount' => 50_000,
    ]);
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $b->id,
        'amount' => 50_000,
        'status' => PaymentStatus::Refunded,
        'type' => PaymentType::Full,
        'paid_at' => Carbon::parse('2030-06-11 12:00:00'),
    ]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->call('openKpi', 'refunded');
    $panel = $view->viewData('kpiPanel');

    expect($panel['title'])->toBe('Refunds issued');
    expect(collect($panel['rows'])->pluck('guest')->all())->toContain('RefundGuy');
    Carbon::setTestNow();
});

it('outstanding panel lists bookings with balance or unsettled charges', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'HasBalance',
        'booking_status' => BookingStatus::CheckedIn,
        'balance_due' => 20_000,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Cleared',
        'booking_status' => BookingStatus::CheckedIn,
        'balance_due' => 0,
    ]);

    $view = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->call('openKpi', 'outstanding');
    $panel = $view->viewData('kpiPanel');

    $guests = collect($panel['rows'])->pluck('guest')->all();
    expect($guests)->toContain('HasBalance');
    expect($guests)->not->toContain('Cleared');
});

it('rejects unknown KPI keys silently', function (): void {
    $view = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->call('openKpi', 'not-a-thing');
    expect($view->viewData('kpiPanel'))->toBeNull();
    expect($view->get('openKpiKey'))->toBeNull();
});

it('closeKpi clears the panel', function (): void {
    $view = Livewire::actingAs($this->manager, 'web')
        ->test(FinanceIndex::class)
        ->call('openKpi', 'billed')
        ->call('closeKpi');
    expect($view->viewData('kpiPanel'))->toBeNull();
    expect($view->get('openKpiKey'))->toBeNull();
});
