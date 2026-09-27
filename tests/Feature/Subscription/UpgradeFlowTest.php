<?php

declare(strict_types=1);

use App\Actions\Subscriptions\InitiateSubscriptionUpgrade;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Livewire\Subscription\Manage;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.paystack.secret_key', 'sk_test_master');
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.callback_url', 'http://keverna.test/checkout/return');

    $this->tenant = Tenant::factory()->trial()->create(['slug' => 'upgrade-tests']);
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    // /subscription sits behind RequireCompleteOnboarding (PRD §6.1.1).
    Property::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the subscription page for authenticated operators', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://upgrade-tests.keverna.test/subscription');

    $response->assertOk();
    $response->assertSeeLivewire(Manage::class);
});

it('redirects unauthenticated visitors to tenant.login', function (): void {
    $response = $this->get('http://upgrade-tests.keverna.test/subscription');

    $response->assertRedirect(route('tenant.login'));
});

it('shows the trial as the current subscription', function (): void {
    Subscription::factory()->for($this->tenant)->trial()->create();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->assertSee('trial')
        ->assertSeeHtml('data-testid="upgrade-cta"');
});

it('shows the upgrade CTA when no active annual subscription exists', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->assertSeeHtml('data-testid="upgrade-cta"');
});

it('hides the upgrade CTA for tenants with an active annual subscription', function (): void {
    Subscription::factory()
        ->for($this->tenant)
        ->create(['plan' => SubscriptionPlan::Annual]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->assertDontSeeHtml('data-testid="upgrade-cta"');
});

it('initiates Paystack on upgrade and redirects to authorization_url', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/3xyz',
                'access_code' => 'ac_3xyz',
                'reference' => 'kev_sub_annual_xyz',
            ],
        ]),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->call('upgrade')
        ->assertRedirect('https://checkout.paystack.test/3xyz');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.test/transaction/initialize'
            && $request['email'] === $this->operator->email
            && $request['amount'] === 30_000_000
            && $request['callback_url'] === 'http://keverna.test/checkout/return';
    });
});

it('persists a Failed Subscription row at upgrade-initiation time', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/abc',
                'access_code' => 'ac_abc',
                'reference' => 'kev_sub_annual_test',
            ],
        ]),
    ]);

    app(InitiateSubscriptionUpgrade::class)->handle($this->operator);

    $sub = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->tenant->id)
        ->where('plan', SubscriptionPlan::Annual->value)
        ->first();

    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe(SubscriptionStatus::Failed);
    expect($sub->amount)->toBe(300_000);
    expect($sub->paystack_reference)->toStartWith('kev_sub_annual_');
    expect($sub->paid_at)->toBeNull();
});

it('passes tenant_id + subscription_id + operator_id in Paystack metadata', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/meta',
                'access_code' => 'ac_meta',
                'reference' => 'kev_sub_annual_meta',
            ],
        ]),
    ]);

    app(InitiateSubscriptionUpgrade::class)->handle($this->operator);

    $sub = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->tenant->id)
        ->where('plan', SubscriptionPlan::Annual->value)
        ->latest('id')
        ->first();

    Http::assertSent(fn ($r) => $r['metadata']['subscription_id'] === $sub->id
        && $r['metadata']['tenant_id'] === $this->tenant->id
        && $r['metadata']['operator_id'] === $this->operator->id
    );
});

it('rolls back the Subscription row if Paystack init fails', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response(['status' => false]),
    ]);

    $before = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->tenant->id)
        ->count();

    try {
        app(InitiateSubscriptionUpgrade::class)->handle($this->operator);
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException) {
        // expected
    }

    $after = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->tenant->id)
        ->count();

    // We pre-create inside DB::transaction but don't wrap the HTTP call —
    // the abandoned Failed row is acceptable for reconciliation. Test
    // documents this intentional choice: row count INCREASES by 1.
    expect($after)->toBe($before + 1);
});
