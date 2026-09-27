<?php

declare(strict_types=1);

use App\Actions\Admin\ActivateSubscription;
use App\Actions\Admin\RecordSubscriptionPayment;
use App\Actions\Admin\RenewSubscription;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Livewire\Admin\Subscriptions\Index as SubsIndex;
use App\Mail\Subscriptions\SubscriptionContractMail;
use App\Mail\Subscriptions\SubscriptionInvoiceMail;
use App\Mail\Subscriptions\SubscriptionReceiptMail;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    Mail::fake();
    $this->admin = Admin::factory()->create();
    Auth::guard('admin')->login($this->admin);
    Tenant::forgetCurrent();
});

it('activates a subscription with full-payment (no invoice mail)', function (): void {
    $tenant = Tenant::factory()->create();

    /** @var ActivateSubscription $action */
    $action = app(ActivateSubscription::class);
    $sub = $action->handle(
        tenant: $tenant,
        admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 300_000,
        amountReceived: 300_000,
        durationMonths: 12,
    );

    expect($sub->contract_amount)->toBe(300_000);
    expect($sub->amount_received)->toBe(300_000);
    expect($sub->outstanding())->toBe(0);
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($tenant->refresh()->status)->toBe(TenantStatus::Active);

    Mail::assertQueued(SubscriptionContractMail::class);
    Mail::assertQueued(SubscriptionReceiptMail::class);
    Mail::assertNotQueued(SubscriptionInvoiceMail::class);
});

it('activates with partial payment and queues an invoice for the outstanding', function (): void {
    $tenant = Tenant::factory()->create();

    /** @var ActivateSubscription $action */
    $action = app(ActivateSubscription::class);
    $sub = $action->handle(
        tenant: $tenant,
        admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 300_000,
        amountReceived: 100_000,
        durationMonths: 12,
    );

    expect($sub->outstanding())->toBe(200_000);
    expect($sub->isFullyPaid())->toBeFalse();

    Mail::assertQueued(SubscriptionContractMail::class);
    Mail::assertQueued(SubscriptionReceiptMail::class);
    Mail::assertQueued(SubscriptionInvoiceMail::class);
});

it('renews an active subscription anchored on the current expiry', function (): void {
    $tenant = Tenant::factory()->create();

    /** @var ActivateSubscription $activate */
    $activate = app(ActivateSubscription::class);
    $first = $activate->handle(
        tenant: $tenant,
        admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 300_000,
        amountReceived: 300_000,
        durationMonths: 12,
    );

    Mail::fake();

    /** @var RenewSubscription $renew */
    $renew = app(RenewSubscription::class);
    $second = $renew->handle(
        tenant: $tenant,
        admin: $this->admin,
        contractAmount: 360_000,
        amountReceived: 360_000,
        durationMonths: 12,
    );

    // New row anchors on the previous expiry, so no time is lost.
    expect($second->starts_at->timestamp)->toBe($first->expires_at->timestamp);
    expect($second->expires_at->gt($first->expires_at))->toBeTrue();
    expect($second->contract_amount)->toBe(360_000);
    expect($tenant->refresh()->subscription_expires_at->timestamp)
        ->toBe($second->expires_at->timestamp);

    Mail::assertQueued(SubscriptionContractMail::class);
    Mail::assertQueued(SubscriptionReceiptMail::class);
});

it('renew upgrades a trial to a paid plan and honours the passed plan', function (): void {
    $tenant = Tenant::factory()->create();
    // A trial sub (as created by RegisterTenantOperator).
    Subscription::query()->withoutGlobalScope(TenantScope::class)->create([
        'tenant_id' => $tenant->getKey(),
        'plan' => SubscriptionPlan::Trial->value,
        'amount' => 0,
        'contract_amount' => 0,
        'amount_received' => 0,
        'duration_months' => 12,
        'status' => SubscriptionStatus::Trial->value,
        'paystack_reference' => 'trial-'.$tenant->getKey(),
        'starts_at' => now()->subDays(1),
        'expires_at' => now()->addDays(13),
    ]);

    /** @var RenewSubscription $renew */
    $renew = app(RenewSubscription::class);
    $sub = $renew->handle(
        tenant: $tenant, admin: $this->admin,
        contractAmount: 300_000, amountReceived: 300_000,
        durationMonths: 12,
        plan: SubscriptionPlan::Annual,
    );

    expect($sub->plan)->toBe(SubscriptionPlan::Annual);
    expect($sub->status)->toBe(SubscriptionStatus::Active);
});

it('activate supersedes the tenant\'s existing trial row', function (): void {
    $tenant = Tenant::factory()->create();
    // Seed a trial row like RegisterTenantOperator does.
    $trial = Subscription::query()->withoutGlobalScope(TenantScope::class)->create([
        'tenant_id' => $tenant->getKey(),
        'plan' => SubscriptionPlan::Trial->value,
        'amount' => 0, 'contract_amount' => 0, 'amount_received' => 0,
        'duration_months' => 12,
        'status' => SubscriptionStatus::Trial->value,
        'paystack_reference' => 'trial-'.$tenant->getKey(),
        'starts_at' => now()->subDays(2), 'expires_at' => now()->addDays(12),
    ]);

    app(ActivateSubscription::class)->handle(
        tenant: $tenant, admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 300_000, amountReceived: 300_000,
        durationMonths: 12,
    );

    // Old trial row should now be Expired so it stops rendering as Active.
    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Expired);

    $activeCount = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->getKey())
        ->where('status', SubscriptionStatus::Active->value)
        ->count();
    expect($activeCount)->toBe(1);
});

it('records a follow-up payment against an outstanding subscription', function (): void {
    $tenant = Tenant::factory()->create();
    $sub = app(ActivateSubscription::class)->handle(
        tenant: $tenant, admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 300_000, amountReceived: 100_000,
        durationMonths: 12,
    );
    Mail::fake();

    // Partial payment first.
    app(RecordSubscriptionPayment::class)->handle(
        subscription: $sub,
        admin: $this->admin,
        amount: 120_000,
    );

    $sub->refresh();
    expect($sub->amount_received)->toBe(220_000);
    expect($sub->outstanding())->toBe(80_000);

    Mail::assertQueued(SubscriptionReceiptMail::class);
    Mail::assertQueued(SubscriptionInvoiceMail::class); // still outstanding

    // Final payment brings it to zero.
    Mail::fake();
    app(RecordSubscriptionPayment::class)->handle(
        subscription: $sub->fresh(),
        admin: $this->admin,
        amount: 80_000,
    );

    $sub->refresh();
    expect($sub->outstanding())->toBe(0);
    expect($sub->isFullyPaid())->toBeTrue();

    Mail::assertQueued(SubscriptionReceiptMail::class);
    Mail::assertNotQueued(SubscriptionInvoiceMail::class); // fully paid now
});

it('rejects payment amounts exceeding the outstanding', function (): void {
    $tenant = Tenant::factory()->create();
    $sub = app(ActivateSubscription::class)->handle(
        tenant: $tenant, admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 300_000, amountReceived: 250_000,
        durationMonths: 12,
    );

    app(RecordSubscriptionPayment::class)->handle(
        subscription: $sub, admin: $this->admin, amount: 200_000,
    );
})->throws(InvalidArgumentException::class);

it('rejects out-of-range duration', function (): void {
    $tenant = Tenant::factory()->create();
    app(ActivateSubscription::class)->handle(
        tenant: $tenant, admin: $this->admin,
        plan: SubscriptionPlan::Annual,
        contractAmount: 100_000, amountReceived: 100_000,
        durationMonths: 0,
    );
})->throws(InvalidArgumentException::class);

it('super-admin form activates via the Livewire index', function (): void {
    $tenant = Tenant::factory()->create();
    // Trial row so the index has something to render.
    Subscription::query()->withoutGlobalScope(TenantScope::class)->create([
        'tenant_id' => $tenant->getKey(),
        'plan' => SubscriptionPlan::Trial->value,
        'amount' => 0,
        'contract_amount' => 0,
        'amount_received' => 0,
        'duration_months' => 12,
        'status' => SubscriptionStatus::Trial->value,
        'paystack_reference' => 'trial-'.$tenant->getKey(),
        'starts_at' => now()->subDays(2),
        'expires_at' => now()->addDays(12),
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SubsIndex::class)
        ->call('openActivate', $tenant->id)
        ->assertSet('formOpen', true)
        ->assertSet('formMode', 'activate')
        ->set('formPlan', SubscriptionPlan::Annual->value)
        ->set('formContract', 300_000)
        ->set('formReceived', 150_000)
        ->set('formDuration', 12)
        ->call('saveForm')
        ->assertSet('formOpen', false);

    $latest = Subscription::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)
        ->orderByDesc('id')
        ->first();

    expect($latest->contract_amount)->toBe(300_000);
    expect($latest->amount_received)->toBe(150_000);
    expect($latest->outstanding())->toBe(150_000);
    expect($latest->status)->toBe(SubscriptionStatus::Active);

    Mail::assertQueued(SubscriptionContractMail::class);
    Mail::assertQueued(SubscriptionReceiptMail::class);
    Mail::assertQueued(SubscriptionInvoiceMail::class);
});
