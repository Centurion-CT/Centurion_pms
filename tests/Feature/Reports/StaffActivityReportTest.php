<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Reports\Index as ReportsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Staff-activity tab on the Reports page and its CSV export.
 *
 * The tab reads activity_log rows caused by Operators, groups their
 * role into a department, and lets a manager filter by
 * dept / staff / date range. The CSV export mirrors those filters
 * so a manager can hand a shift review to Finance.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'staff-report']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();

    // A manager to view the Reports page (role:reports gate) + a few
    // operators across departments to attribute actions to.
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'name' => 'Ada Manager',
    ]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'name' => 'Chi Frontdesk',
    ]);
    $this->porter = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Porter,
        'name' => 'Ola Porter',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/** Seed an activity log entry attributed to an operator on a specific date. */
function seedActivity(Operator $op, string $logName, string $event, string $desc, Carbon $when): void
{
    $activity = activity($logName)
        ->causedBy($op)
        ->event($event)
        ->log($desc);
    // Backdate for range assertions. `log()` returns the Activity
    // instance in Spatie's laravel-activitylog.
    $activity->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
}

/* ── Livewire wiring ── */

it('renders the staff-activity tab with the seeded rows in the current window', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'Front desk checked in guest', Carbon::now()->subDays(2));
    seedActivity($this->porter, 'housekeeping', 'porter_verified', 'Porter verified room 12', Carbon::now()->subDays(1));

    Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'staff')
        ->set('preset', 'last_7d')
        ->assertSee('Front desk checked in guest')
        ->assertSee('Porter verified room 12')
        ->assertSee('Chi Frontdesk')
        ->assertSee('Ola Porter');
    Carbon::setTestNow();
});

it('department filter narrows the list to just that group', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'Front desk checked in guest', Carbon::now()->subDays(1));
    seedActivity($this->porter, 'housekeeping', 'porter_verified', 'Porter verified room 12', Carbon::now()->subDays(1));

    Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'staff')
        ->set('preset', 'last_7d')
        ->set('departmentFilter', 'housekeeping')
        ->assertSee('Porter verified room 12')
        ->assertDontSee('Front desk checked in guest');
    Carbon::setTestNow();
});

it('staff picker limits the list to a single operator', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'FD action A', Carbon::now()->subDay());
    seedActivity($this->porter, 'housekeeping', 'porter_verified', 'Porter action B', Carbon::now()->subDay());

    Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'staff')
        ->set('preset', 'last_7d')
        ->set('staffFilter', (string) $this->frontdesk->id)
        ->assertSee('FD action A')
        ->assertDontSee('Porter action B');
    Carbon::setTestNow();
});

it('date range clamps rows outside the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'Recent action', Carbon::now()->subDay());
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'Old action', Carbon::now()->subMonths(3));

    Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'staff')
        ->set('preset', 'last_7d')
        ->assertSee('Recent action')
        ->assertDontSee('Old action');
    Carbon::setTestNow();
});

it('KPIs count total actions + active staff in the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'A', Carbon::now()->subDay());
    seedActivity($this->frontdesk, 'booking', 'checked_out', 'B', Carbon::now()->subDay());
    seedActivity($this->porter, 'housekeeping', 'porter_verified', 'C', Carbon::now()->subDay());

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(ReportsIndex::class)
        ->set('tab', 'staff')
        ->set('preset', 'last_7d')
        ->html();

    expect($html)->toContain('data-testid="staff-kpis"');
    // Total actions = 3, active staff = 2 (frontdesk + porter).
    // Just assert the numbers appear in the KPI block; not asserting
    // exact HTML formatting to keep the test tolerant.
    expect($html)->toMatch('/Total actions.*3/s');
    expect($html)->toMatch('/Active staff.*2/s');
    Carbon::setTestNow();
});

/* ── CSV export ── */

it('CSV export streams rows matching the current filter set', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'FD action', Carbon::now()->subDay());
    seedActivity($this->porter, 'housekeeping', 'porter_verified', 'Porter action', Carbon::now()->subDay());

    $this->actingAs($this->manager, 'web');
    $response = $this->get(route('tenant.reports.staff-activity.export', [
        'from' => Carbon::now()->subDays(7)->toDateString(),
        'to' => Carbon::now()->toDateString(),
        'dept' => 'housekeeping',
    ]));

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Staff activity export');
    expect($csv)->toContain('Porter action');
    expect($csv)->not->toContain('FD action');
    Carbon::setTestNow();
});

it('CSV export attributes rows to the operator name + department', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00'));
    seedActivity($this->frontdesk, 'booking', 'checked_in', 'One action', Carbon::now()->subDay());

    $this->actingAs($this->manager, 'web');
    $response = $this->get(route('tenant.reports.staff-activity.export', [
        'from' => Carbon::now()->subDays(7)->toDateString(),
        'to' => Carbon::now()->toDateString(),
    ]));

    $csv = $response->streamedContent();
    expect($csv)->toContain('Chi Frontdesk');
    expect($csv)->toContain('Front desk');
    expect($csv)->toContain('One action');
    Carbon::setTestNow();
});
