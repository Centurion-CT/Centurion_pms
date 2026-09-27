<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Membership\Businesses as MembershipBusinesses;
use App\Livewire\Dashboard\Membership\Members as MembershipMembers;
use App\Livewire\Dashboard\Membership\Plans as MembershipPlans;
use App\Livewire\Dashboard\Membership\Show as MembershipShow;
use App\Models\Customer;
use App\Models\MembershipBusiness;
use App\Enums\PaymentStatus;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Models\MembershipPlan;
use App\Models\MembershipSubscription;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * End-to-end coverage of the operator's Membership day-to-day:
 * spin up a business + plan, subscribe a customer, renew, extend,
 * cancel. Payment rows follow through so revenue is auditable.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gym-club']);
    $this->tenant->makeCurrent();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('creates a business via the inline form', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipBusinesses::class)
        ->call('newBusiness')
        ->set('name', 'Gym')
        ->set('description', 'Ground-floor gym')
        ->call('save')
        ->assertDispatched('notify');

    expect(MembershipBusiness::query()->where('name', 'Gym')->exists())->toBeTrue();
});

it('creates a plan under a business', function (): void {
    $biz = MembershipBusiness::query()->create([
        'name' => 'Spa', 'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipPlans::class)
        ->call('newPlan')
        ->set('businessId', $biz->id)
        ->set('name', 'Monthly Spa')
        ->set('price', 15_000)
        ->set('validityDays', 30)
        ->call('save')
        ->assertDispatched('notify');

    $plan = MembershipPlan::query()->where('name', 'Monthly Spa')->first();
    expect($plan)->not->toBeNull();
    expect((int) $plan->price)->toBe(15_000);
    expect((int) $plan->validity_days)->toBe(30);
});

it('subscribes a customer + writes an initial Payment row', function (): void {
    $biz = MembershipBusiness::query()->create(['name' => 'Gym', 'active' => true]);
    $plan = MembershipPlan::query()->create([
        'business_id' => $biz->id,
        'name' => 'Monthly Gym', 'price' => 15_000, 'validity_days' => 30, 'active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Ada Member',
        'phone' => '+2348011111111',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipMembers::class)
        ->call('toggleSubscribe')
        ->set('customerId', $customer->id)
        ->set('businessId', $biz->id)
        ->set('planId', $plan->id)
        ->set('paymentAmount', 15_000)
        ->set('paymentMethod', 'cash')
        ->call('subscribe')
        ->assertDispatched('notify');

    $sub = MembershipSubscription::query()->where('customer_id', $customer->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe(MembershipSubscription::STATUS_ACTIVE);
    // Expiry = start + validity_days.
    expect($sub->expires_at->toDateString())->toBe(
        $sub->started_at->copy()->addDays(30)->toDateString(),
    );

    $payment = $sub->payments()->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(15_000);
    expect($payment->membership_kind)->toBe(MembershipPayment::KIND_INITIAL);
    expect($payment->method)->toBe('cash');

    // Regression: the receipt now lives on the general Payment ledger
    // so it also surfaces on the Payments page.
    $ledgerRow = Payment::query()
        ->where('membership_subscription_id', $sub->id)
        ->first();
    expect($ledgerRow)->not->toBeNull();
    expect((int) $ledgerRow->amount)->toBe(15_000);
    expect($ledgerRow->status)->toEqual(PaymentStatus::Succeeded);
});

it('renew extends expires_at by the plan validity + writes a KIND_RENEWAL payment', function (): void {
    $biz = MembershipBusiness::query()->create(['name' => 'Gym', 'active' => true]);
    $plan = MembershipPlan::query()->create([
        'business_id' => $biz->id,
        'name' => 'Monthly', 'price' => 20_000, 'validity_days' => 30, 'active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Test Member',
        'phone' => '+234801'.uniqid(),
    ]);
    $started = Carbon::today()->subDays(20);
    $currentExpires = Carbon::today()->addDays(10);
    $sub = MembershipSubscription::query()->create([
        'business_id' => $biz->id,
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'member_ref' => 'MB-TEST01',
        'started_at' => $started,
        'expires_at' => $currentExpires,
        'status' => MembershipSubscription::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipShow::class, ['memberRef' => $sub->member_ref])
        ->call('toggleRenew')
        ->set('renewPlanId', $plan->id)
        ->set('renewAmount', 20_000)
        ->set('renewMethod', 'cash')
        ->call('renew');

    $sub->refresh();
    // Active sub: expiry stacks on top of the remaining 10 days.
    expect($sub->expires_at->toDateString())->toBe(
        $currentExpires->copy()->addDays(30)->toDateString(),
    );
    $renewal = $sub->payments()->where('membership_kind', MembershipPayment::KIND_RENEWAL)->first();
    expect($renewal)->not->toBeNull();
    expect((int) $renewal->amount)->toBe(20_000);
});

it('extend adds N days without a fresh period + logs KIND_EXTENSION', function (): void {
    $biz = MembershipBusiness::query()->create(['name' => 'Gym', 'active' => true]);
    $plan = MembershipPlan::query()->create([
        'business_id' => $biz->id, 'name' => 'Monthly',
        'price' => 20_000, 'validity_days' => 30, 'active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Test Member',
        'phone' => '+234801'.uniqid(),
    ]);
    $currentExpires = Carbon::today()->addDays(5);
    $sub = MembershipSubscription::query()->create([
        'business_id' => $biz->id, 'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'member_ref' => 'MB-EXT02',
        'started_at' => Carbon::today()->subDays(25),
        'expires_at' => $currentExpires,
        'status' => MembershipSubscription::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipShow::class, ['memberRef' => $sub->member_ref])
        ->call('toggleExtend')
        ->set('extendDays', 7)
        ->set('extendAmount', 5_000)
        ->set('extendMethod', 'cash')
        ->call('extend');

    $sub->refresh();
    expect($sub->expires_at->toDateString())->toBe(
        $currentExpires->copy()->addDays(7)->toDateString(),
    );
    $ext = $sub->payments()->where('membership_kind', MembershipPayment::KIND_EXTENSION)->first();
    expect($ext)->not->toBeNull();
    expect((int) $ext->amount)->toBe(5_000);
});

it('cancel flips status + records cancellation reason', function (): void {
    $biz = MembershipBusiness::query()->create(['name' => 'Spa', 'active' => true]);
    $plan = MembershipPlan::query()->create([
        'business_id' => $biz->id, 'name' => 'Monthly',
        'price' => 10_000, 'validity_days' => 30, 'active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Test Member',
        'phone' => '+234801'.uniqid(),
    ]);
    $sub = MembershipSubscription::query()->create([
        'business_id' => $biz->id, 'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'member_ref' => 'MB-CAN03',
        'started_at' => Carbon::today(),
        'expires_at' => Carbon::today()->addDays(30),
        'status' => MembershipSubscription::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipShow::class, ['memberRef' => $sub->member_ref])
        ->call('toggleCancel')
        ->set('cancelReason', 'Member relocated')
        ->call('cancel');

    $sub->refresh();
    expect($sub->status)->toBe(MembershipSubscription::STATUS_CANCELLED);
    expect($sub->cancellation_reason)->toBe('Member relocated');
    expect($sub->cancelled_at)->not->toBeNull();
});

it('take payment records a KIND_OTHER row without changing dates', function (): void {
    $biz = MembershipBusiness::query()->create(['name' => 'Spa', 'active' => true]);
    $plan = MembershipPlan::query()->create([
        'business_id' => $biz->id, 'name' => 'Monthly',
        'price' => 10_000, 'validity_days' => 30, 'active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Test Member',
        'phone' => '+234801'.uniqid(),
    ]);
    $expires = Carbon::today()->addDays(15);
    $sub = MembershipSubscription::query()->create([
        'business_id' => $biz->id, 'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'member_ref' => 'MB-PAY04',
        'started_at' => Carbon::today()->subDays(15),
        'expires_at' => $expires,
        'status' => MembershipSubscription::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(MembershipShow::class, ['memberRef' => $sub->member_ref])
        ->call('togglePayment')
        ->set('paymentAmount', 2_500)
        ->set('paymentMethod', 'transfer')
        ->set('paymentNote', 'Late top-up')
        ->call('takePayment');

    $sub->refresh();
    expect($sub->expires_at->toDateString())->toBe($expires->toDateString());
    $p = $sub->payments()->latest('id')->first();
    expect($p)->not->toBeNull();
    expect($p->membership_kind)->toBe(MembershipPayment::KIND_OTHER);
    expect((int) $p->amount)->toBe(2_500);
    expect($p->reference_note)->toBe('Late top-up');
});
