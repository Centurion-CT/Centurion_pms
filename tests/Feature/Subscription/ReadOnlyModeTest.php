<?php

declare(strict_types=1);

use App\Enums\SubscriptionPlan;
use App\Exceptions\SubscriptionExpiredException;
use App\Livewire\Dashboard\Home as DashboardHome;
use App\Livewire\Subscription\Manage;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['slug' => 'read-only-tests']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('shows the read-only bar on the dashboard when the trial has ended', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://read-only-tests.keverna.test/dashboard')
        ->assertOk()
        ->assertSeeHtml('data-testid="billing-readonly-bar"')
        ->assertSee('Your trial has ended.');
});

it('does NOT show the read-only bar when the trial is still live', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->addDays(3),
    ]);

    $this->actingAs($this->operator, 'web')
        ->get('http://read-only-tests.keverna.test/dashboard')
        ->assertOk()
        ->assertDontSeeHtml('data-testid="billing-readonly-bar"')
        ->assertDontSeeHtml('data-billing-readonly=');
});

it('adds the body data-attribute so the CSS dimmer engages on read-only pages', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);

    // The <body> attribute is what the CSS selector `body[data-billing-readonly]`
    // hangs off — its presence is what dims all .lp-btn--primary / .lp-pill /
    // etc. buttons across the shell.
    $this->actingAs($this->operator, 'web')
        ->get('http://read-only-tests.keverna.test/dashboard')
        ->assertSeeHtml('data-billing-readonly="1"');
});

it('keeps the upgrade button clickable on the subscription page via data-billing-safe', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);

    // Without the opt-out, the dimmer would kill pointer events on .lp-pill
    // and the tenant could never actually renew. Assert the opt-out attribute
    // is present on the upgrade button.
    $this->actingAs($this->operator, 'web')
        ->get('http://read-only-tests.keverna.test/subscription')
        ->assertOk()
        ->assertSeeHtml('data-testid="upgrade-btn" data-billing-safe');
});

it('blocks writes on tenant-scoped models when the trial has ended', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);

    // Hit an authed route so the middleware flags the request as read-only.
    // The get() populates the container flag; the subsequent save() in the
    // same request cycle would throw. In tests we simulate the flag directly
    // so we can assert on the exception without dispatching a Livewire write.
    app()->instance('billing.read_only', true);

    expect(fn () => Room::factory()->for($this->tenant)->create())
        ->toThrow(SubscriptionExpiredException::class);
});

it('still allows Subscription writes so the tenant can renew', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);
    app()->instance('billing.read_only', true);

    // Creating a fresh Subscription row (what InitiateSubscriptionUpgrade
    // does) must not throw — that's the whole renewal path.
    $fresh = Subscription::factory()->for($this->tenant)->create([
        'plan' => SubscriptionPlan::Annual,
    ]);

    expect($fresh->exists)->toBeTrue();
});

it('lets an expired-trial tenant reach the upgrade page and initiate Paystack', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create([
        'expires_at' => Carbon::now()->subDay(),
    ]);
    config()->set('services.paystack.secret_key', 'sk_test_master');
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.callback_url', 'http://keverna.test/checkout/return');
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/renew',
                'access_code' => 'ac_renew',
                'reference' => 'kev_sub_annual_renew',
            ],
        ], 200),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->call('upgrade')
        ->assertRedirect('https://checkout.paystack.test/renew');
});
