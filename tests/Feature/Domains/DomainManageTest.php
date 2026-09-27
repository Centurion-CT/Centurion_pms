<?php

declare(strict_types=1);

use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Livewire\Domain\Manage;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
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
    expect($this->tenant->custom_domain_verification_token)->toStartWith('keverna-verify=');

    Queue::assertPushed(VerifyCustomDomain::class,
        fn ($job) => $job->tenantId === $this->tenant->id);
});

it('shows the CNAME target + TXT token in the DNS instructions panel', function (): void {
    $this->tenant->forceFill([
        'custom_domain' => 'show-me.example.com',
        'custom_domain_verification_token' => 'keverna-verify=abc123',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->assertSeeHtml('data-testid="dns-instructions"')
        ->assertSee('cname.keverna.test')
        ->assertSee('keverna-verify=abc123');
});

it('reverify dispatches a new VerifyCustomDomain job', function (): void {
    Queue::fake();

    $this->tenant->forceFill([
        'custom_domain' => 'reverify.example.com',
        'custom_domain_verification_token' => 'keverna-verify=zzz',
        'custom_domain_status' => CustomDomainStatus::Pending,
    ])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->call('reverify');

    Queue::assertPushed(VerifyCustomDomain::class,
        fn ($job) => $job->tenantId === $this->tenant->id);
});

it('reverify is a no-op when no custom_domain is attached', function (): void {
    Queue::fake();

    Livewire::actingAs($this->operator, 'web')
        ->test(Manage::class)
        ->call('reverify');

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
