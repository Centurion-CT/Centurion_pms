<?php

declare(strict_types=1);

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Events\SubscriptionActivated;
use App\Jobs\Domains\ProvisionTenantSubdomain;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('services.paystack.secret_key', 'sk_test_master');
    config()->set('services.paystack.base_url', 'https://api.paystack.test');

    // SubscriptionActivated -> DispatchSubdomainProvisioning -> ProvisionTenantSubdomain.
    // We don't want the provisioning side-effects (Dokwe attach + row
    // stamping) firing during webhook tests.
    Bus::fake([ProvisionTenantSubdomain::class]);
});

function signed(array $payload): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha512', $body, 'sk_test_master');

    return [$body, $sig];
}

it('rejects a request with no signature header', function (): void {
    $response = $this->postJson('http://keverna.test/paystack/subscription-webhook', [
        'event' => 'charge.success',
        'data' => ['reference' => 'whatever'],
    ]);

    $response->assertStatus(401);
});

it('rejects a request with a tampered signature', function (): void {
    [$body, $sig] = signed(['event' => 'charge.success', 'data' => ['reference' => 'r1']]);

    $response = $this->call(
        'POST',
        'http://keverna.test/paystack/subscription-webhook',
        [],
        [],
        [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig.'tampered', 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(401);
});

it('returns 422 when the body is malformed JSON', function (): void {
    $body = '{"not": "valid-event"}';
    $sig = hash_hmac('sha512', $body, 'sk_test_master');

    $response = $this->call(
        'POST',
        'http://keverna.test/paystack/subscription-webhook',
        [],
        [],
        [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(422);
});

it('ignores non-charge.success events (200 ack)', function (): void {
    Event::fake();
    [$body, $sig] = signed([
        'event' => 'charge.failed',
        'data' => ['reference' => 'r-fail', 'amount' => 30_000_000],
    ]);

    $response = $this->call(
        'POST',
        'http://keverna.test/paystack/subscription-webhook',
        [],
        [],
        [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertOk()->assertJson(['ok' => true, 'ignored' => 'charge.failed']);
    Event::assertNotDispatched(SubscriptionActivated::class);
});

it('rejects events for unknown references', function (): void {
    [$body, $sig] = signed([
        'event' => 'charge.success',
        'data' => ['reference' => 'ghost-ref', 'amount' => 30_000_000],
    ]);

    $response = $this->call(
        'POST',
        'http://keverna.test/paystack/subscription-webhook',
        [],
        [],
        [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(422);
});

it('rejects events whose amount does not match the pending subscription', function (): void {
    $tenant = Tenant::factory()->trial()->create();
    $tenant->makeCurrent();
    $sub = Subscription::factory()->for($tenant)->failed()->create([
        'plan' => SubscriptionPlan::Annual,
        'amount' => 300_000,
        'paystack_reference' => 'amt-mismatch',
    ]);
    Tenant::forgetCurrent();

    [$body, $sig] = signed([
        'event' => 'charge.success',
        'data' => ['reference' => 'amt-mismatch', 'amount' => 100], // 1 NGN, not 300,000
    ]);

    $response = $this->call(
        'POST',
        'http://keverna.test/paystack/subscription-webhook',
        [],
        [],
        [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(422);
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Failed);
});

it('activates a pending subscription on charge.success', function (): void {
    Event::fake();

    $tenant = Tenant::factory()->trial()->create();
    $tenant->makeCurrent();
    $sub = Subscription::factory()->for($tenant)->failed()->create([
        'plan' => SubscriptionPlan::Annual,
        'amount' => 300_000,
        'paystack_reference' => 'pay-success',
    ]);
    Tenant::forgetCurrent();

    [$body, $sig] = signed([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'pay-success',
            'amount' => 30_000_000,
            'paid_at' => '2026-06-01T12:00:00.000Z',
        ],
    ]);

    $response = $this->call(
        'POST',
        'http://keverna.test/paystack/subscription-webhook',
        [],
        [],
        [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertOk()->assertJson(['ok' => true, 'activated' => true]);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->paid_at)->not->toBeNull();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
    expect($tenant->fresh()->subscription_expires_at->isFuture())->toBeTrue();

    Event::assertDispatched(SubscriptionActivated::class);
});

it('is idempotent — second webhook call for the same reference is a no-op', function (): void {
    Event::fake();

    $tenant = Tenant::factory()->trial()->create();
    $tenant->makeCurrent();
    $sub = Subscription::factory()->for($tenant)->failed()->create([
        'plan' => SubscriptionPlan::Annual,
        'amount' => 300_000,
        'paystack_reference' => 'idempotent',
    ]);
    Tenant::forgetCurrent();

    [$body, $sig] = signed([
        'event' => 'charge.success',
        'data' => ['reference' => 'idempotent', 'amount' => 30_000_000],
    ]);

    // First delivery activates.
    $this->call('POST', 'http://keverna.test/paystack/subscription-webhook', [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);

    // Second delivery acks but does not re-dispatch.
    $response = $this->call('POST', 'http://keverna.test/paystack/subscription-webhook', [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body);

    $response->assertOk()->assertJson(['ok' => true, 'idempotent' => true]);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

it('expires the previous trial row when a paid Subscription activates', function (): void {
    $tenant = Tenant::factory()->trial()->create();
    $tenant->makeCurrent();

    $trial = Subscription::factory()->for($tenant)->trial()->create();
    $paid = Subscription::factory()->for($tenant)->failed()->create([
        'plan' => SubscriptionPlan::Annual,
        'amount' => 300_000,
        'paystack_reference' => 'upgrade',
    ]);
    Tenant::forgetCurrent();

    [$body, $sig] = signed([
        'event' => 'charge.success',
        'data' => ['reference' => 'upgrade', 'amount' => 30_000_000],
    ]);

    $this->call('POST', 'http://keverna.test/paystack/subscription-webhook', [], [], [],
        ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Expired);
    expect($paid->fresh()->status)->toBe(SubscriptionStatus::Active);

    $current = $tenant->fresh()->currentSubscription();
    expect($current?->id)->toBe($paid->id);
});
