<?php

declare(strict_types=1);

use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Livewire\Domain\Manage;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('keverna.custom_domain_cname_target', 'cname.keverna.test');

    $this->tenant = Tenant::factory()->create(['slug' => 'domain-ui-test']);
    $this->tenant->makeCurrent();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    Property::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the domain page for an onboarded operator', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://domain-ui-test.keverna.test/domain');

    $response->assertOk();
    $response->assertSeeLivewire(Manage::class);
});

it('redirects unauthenticated visitors to login', function (): void {
    $response = $this->get('http://domain-ui-test.keverna.test/domain');

    $response->assertRedirect(route('tenant.login'));
});

it('rejects an obviously invalid domain', function (): void {
    Queue::fake();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->set('custom_domain', 'not a domain')
        ->call('attach')
        ->assertHasErrors(['custom_domain']);

    Queue::assertNothingPushed();
});

it('attaches a domain, persists token + Pending status, and dispatches verify', function (): void {
    Queue::fake();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->set('custom_domain', 'reservations.example.com')
        ->call('attach')
        ->assertHasNoErrors();

    $this->tenant->refresh();
    expect($this->tenant->custom_domain)->toBe('reservations.example.com');
    expect($this->tenant->custom_domain_status)->toBe(CustomDomainStatus::Pending);
    expect($this->tenant->custom_domain_verification_token)->toStartWith('centurion-verify=');

    Queue::assertPushed(VerifyCustomDomain::class,
        fn ($job) => $job->tenantId === $this->tenant->id);
});

it('shows the CNAME target + TXT token in the DNS instructions panel', function (): void {
    $this->tenant->forceFill([
        'custom_domain' => 'show-me.example.com',
        'custom_domain_verification_token' => 'centurion-verify=abc123',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->assertSeeHtml('data-testid="dns-instructions"')
        ->assertSee('cname.keverna.test')
        ->assertSee('_centurion-verify.show-me.example.com')
        ->assertSee('centurion-verify=abc123');
});

it('checking again before verification dispatches a new VerifyCustomDomain job', function (): void {
    Queue::fake();

    $this->tenant->forceFill([
        'custom_domain' => 'reverify.example.com',
        'custom_domain_verification_token' => 'centurion-verify=zzz',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->call('checkAgain');

    Queue::assertPushed(VerifyCustomDomain::class,
        fn ($job) => $job->tenantId === $this->tenant->id);
});

it('checking again is a no-op when no custom_domain is attached', function (): void {
    Queue::fake();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->call('checkAgain');

    Queue::assertNotPushed(VerifyCustomDomain::class);
});

it('the domain page is gated by the onboarding wizard', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'gated-domain']);
    $unfinished = Operator::factory()->for($tenant)->unverified()->create();

    Tenant::forgetCurrent();
    $tenant->makeCurrent();

    $response = $this->actingAs($unfinished, 'web')
        ->get('http://gated-domain.keverna.test/domain');

    $response->assertRedirect(route('tenant.onboarding.property'));
});

it('hides the Domain sidebar entry when domain management is disabled', function (): void {
    config()->set('keverna.domain_management.enabled', false);

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://domain-ui-test.keverna.test/dashboard');

    $response->assertOk();
    $response->assertDontSee('domain-ui-test.keverna.test/domain', false);
});

it('shows the Domain sidebar entry when domain management is enabled', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://domain-ui-test.keverna.test/dashboard');

    $response->assertOk();
    $response->assertSee('domain-ui-test.keverna.test/domain', false);
});

it('shows the A records for a bare domain when they are configured', function (): void {
    config()->set('keverna.custom_domain_a_records', ['203.0.113.10']);
    $this->tenant->forceFill([
        'custom_domain' => 'grandpalace.ng',
        'custom_domain_verification_token' => 'centurion-verify=abc',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ])->save();

    Livewire::actingAs($this->operator, 'web')->test(Manage::class)
        ->assertSeeHtml('data-testid="a-record"')
        ->assertSee('203.0.113.10');
});

it('takes the domain without https:// or a trailing slash', function (): void {
    Queue::fake();

    Livewire::actingAs($this->operator, 'web')->test(Manage::class)
        ->set('custom_domain', 'https://Book.Example.com/')
        ->call('attach')
        ->assertHasNoErrors();

    expect($this->tenant->refresh()->custom_domain)->toBe('book.example.com');
});

it('checks a verified domain over HTTPS and makes it live when it answers for the business', function (): void {
    Http::fake(['https://book.example.com/__tenant-ping' => Http::response(['tenant_id' => $this->tenant->id])]);
    $this->tenant->forceFill([
        'custom_domain' => 'book.example.com',
        'custom_domain_status' => CustomDomainStatus::Active,
        'domain_verified_at' => now(),
    ])->save();

    Livewire::actingAs($this->operator, 'web')->test(Manage::class)
        ->call('checkAgain')
        ->assertSee('are sent to');

    expect($this->tenant->refresh()->ownDomainIsLive())->toBeTrue();
});

it('removes the domain', function (): void {
    $this->tenant->forceFill(['custom_domain' => 'gone.example.com', 'domain_verified_at' => now(), 'custom_domain_live_at' => now()])->save();

    Livewire::actingAs($this->operator, 'web')->test(Manage::class)->call('remove');

    $this->tenant->refresh();
    expect($this->tenant->custom_domain)->toBeNull()
        ->and($this->tenant->primaryHost())->toBe('domain-ui-test.keverna.test');
});

it('renames the address and carries the manager over, signed in', function (): void {
    Queue::fake();

    $response = Livewire::actingAs($this->operator, 'web')->test(Manage::class)
        ->set('slug', 'new-name')
        ->call('renameSlug')
        ->assertHasNoErrors();

    expect($this->tenant->refresh()->slug)->toBe('new-name');
    expect($response->effects['redirect'])->toStartWith('http://new-name.keverna.test/auth/post-registration?token=');

    $this->get('http://domain-ui-test.keverna.test/dashboard?x=1')
        ->assertStatus(301)->assertRedirect('http://new-name.keverna.test/dashboard?x=1');
});

it('refuses a reserved or badly formed address', function (string $slug): void {
    Livewire::actingAs($this->operator, 'web')->test(Manage::class)
        ->set('slug', $slug)
        ->call('renameSlug')
        ->assertHasErrors('slug');

    expect($this->tenant->refresh()->slug)->toBe('domain-ui-test');
})->with(['console', 'ab']);

