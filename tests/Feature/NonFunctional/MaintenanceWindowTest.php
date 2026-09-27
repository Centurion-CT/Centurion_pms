<?php

declare(strict_types=1);

use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\MaintenanceWindow;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config()->set('keverna.maintenance_window', [
        'enabled' => true,
        'warn_hours_before' => 2,
        'start_hour' => 2,
        'end_hour' => 4,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    Tenant::forgetCurrent();
});

/* ---------- State machine ---------- */

it('reports inactive when the window is disabled', function (): void {
    config()->set('keverna.maintenance_window.enabled', false);
    Carbon::setTestNow(Carbon::parse('2030-08-15 02:30:00'));

    expect(MaintenanceWindow::current()['state'])->toBe('inactive');
});

it('reports active when now() is inside the window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-15 02:30:00'));

    $window = MaintenanceWindow::current();
    expect($window['state'])->toBe('active');
    expect($window['hours_until'])->toBe(0);
});

it('reports upcoming when within warn-hours-before of today\'s window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-15 01:00:00'));

    $window = MaintenanceWindow::current();
    expect($window['state'])->toBe('upcoming');
    expect($window['hours_until'])->toBe(1);
});

it('reports upcoming for tomorrow\'s window when warn-ahead crosses midnight', function (): void {
    // warn_hours_before=3: 23:30 + 3hr = 02:30 next day, which is past 02:00
    // tomorrow → we should be warning about tomorrow's window.
    config()->set('keverna.maintenance_window.warn_hours_before', 3);
    Carbon::setTestNow(Carbon::parse('2030-08-15 23:30:00'));

    $window = MaintenanceWindow::current();
    expect($window['state'])->toBe('upcoming');
    expect($window['start']->format('Y-m-d H:i'))->toBe('2030-08-16 02:00');
});

it('reports inactive during the day outside both windows', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-15 14:00:00'));

    expect(MaintenanceWindow::current()['state'])->toBe('inactive');
});

/* ---------- Banner rendering ---------- */

it('renders the banner on the operator dashboard during the active window', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-15 02:30:00'));

    $tenant = Tenant::factory()->create(['slug' => 'mw-test']);
    $tenant->makeCurrent();
    Property::factory()->live()->for($tenant)->create();
    $operator = Operator::factory()->for($tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://mw-test.keverna.test/dashboard');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="maintenance-banner"');
    $response->assertSee('scheduled maintenance');
});

it('does NOT render the banner when the window is disabled', function (): void {
    config()->set('keverna.maintenance_window.enabled', false);
    Carbon::setTestNow(Carbon::parse('2030-08-15 02:30:00'));

    $tenant = Tenant::factory()->create(['slug' => 'mw-off']);
    $tenant->makeCurrent();
    Property::factory()->live()->for($tenant)->create();
    $operator = Operator::factory()->for($tenant)->create();

    $response = $this->actingAs($operator, 'web')
        ->get('http://mw-off.keverna.test/dashboard');

    $response->assertOk();
    $response->assertDontSeeHtml('data-testid="maintenance-banner"');
});
