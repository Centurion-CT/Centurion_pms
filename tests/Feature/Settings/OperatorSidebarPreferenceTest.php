<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'prefs-test']);
    $this->tenant->makeCurrent();
    // Subscription + property + onboarding-complete operator so
    // RequireCompleteOnboarding + EnforceSubscriptionAccess middleware
    // don't redirect the endpoint hit before it reaches the controller.
    Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => Carbon::now(),
        'phone_verification_skipped_at' => Carbon::now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('defaults to the tiles variant when no preference has been set', function (): void {
    expect($this->operator->sidebarVariant())->toBe('tiles');
});

it('persists a valid variant pick to operator.preferences', function (): void {
    $this->actingAs($this->operator, 'web');

    $response = $this->putJson(route('tenant.preferences.sidebar-variant'), [
        'variant' => 'list',
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'variant' => 'list']);
    expect($this->operator->fresh()->sidebarVariant())->toBe('list');
    expect($this->operator->fresh()->preferences['sidebar_variant'])->toBe('list');
});

it('flips back to tiles cleanly after picking list', function (): void {
    $this->actingAs($this->operator, 'web');

    $this->putJson(route('tenant.preferences.sidebar-variant'), ['variant' => 'list']);
    $this->putJson(route('tenant.preferences.sidebar-variant'), ['variant' => 'tiles']);

    expect($this->operator->fresh()->sidebarVariant())->toBe('tiles');
});

it('rejects an unknown variant', function (): void {
    $this->actingAs($this->operator, 'web');

    $response = $this->putJson(route('tenant.preferences.sidebar-variant'), [
        'variant' => 'grid',
    ]);

    $response->assertStatus(422);
    expect($this->operator->fresh()->sidebarVariant())->toBe('tiles');
});

it('refuses when nobody is signed in', function (): void {
    $response = $this->putJson(route('tenant.preferences.sidebar-variant'), [
        'variant' => 'list',
    ]);

    // Guest hit → redirect to login (302) or 401 depending on
    // middleware stack. Either way, the DB must not be touched.
    expect(in_array($response->status(), [302, 401, 419], true))->toBeTrue();
    expect($this->operator->fresh()->sidebarVariant())->toBe('tiles');
});

it('persists even when the tenant is in subscription-read-only mode', function (): void {
    // Simulate the exact condition that broke the pick in the field:
    // AppServiceProvider's write guard is active. Without the bypass
    // the save silently throws SubscriptionExpiredException.
    app()->instance('billing.read_only', true);

    $this->actingAs($this->operator, 'web');
    $response = $this->putJson(route('tenant.preferences.sidebar-variant'), [
        'variant' => 'list',
    ]);

    $response->assertOk();
    expect($this->operator->fresh()->sidebarVariant())->toBe('list');

    app()->forgetInstance('billing.read_only');
});

it('saveSidebarVariant via the Livewire Profile component persists to the DB', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(\App\Livewire\Dashboard\Settings\Profile::class)
        ->call('saveSidebarVariant', 'list');

    expect($this->operator->fresh()->sidebarVariant())->toBe('list');
});

it('next authed page renders the saved variant on the <html> tag', function (): void {
    // Attribute lives ON <html> (not in a script) so Livewire's
    // wire:navigate morph carries it forward. If we ever regress to
    // setting it only via a script, this test fails.
    $this->operator->forceFill([
        'preferences' => ['sidebar_variant' => 'list'],
    ])->save();

    $this->actingAs($this->operator, 'web');

    $html = $this->get(route('tenant.settings.profile'))->content();

    expect($html)->toContain('data-sidebar-variant="list"');
});

it('preserves other preference keys when saving the sidebar', function (): void {
    // Simulate a future pref already living in the bag.
    $this->operator->forceFill([
        'preferences' => ['density' => 'compact', 'sidebar_variant' => 'tiles'],
    ])->save();

    $this->actingAs($this->operator, 'web');
    $this->putJson(route('tenant.preferences.sidebar-variant'), ['variant' => 'list'])
        ->assertOk();

    $prefs = $this->operator->fresh()->preferences;
    expect($prefs['sidebar_variant'])->toBe('list');
    expect($prefs['density'])->toBe('compact');
});
