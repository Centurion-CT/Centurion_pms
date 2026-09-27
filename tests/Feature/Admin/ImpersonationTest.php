<?php

declare(strict_types=1);

use App\Actions\Admin\IssueImpersonationToken;
use App\Livewire\Admin\Tenants\Show as AdminTenantsShow;
use App\Models\Admin;
use App\Models\AdminImpersonation;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create(['name' => 'Super Admin']);
    $this->tenant = Tenant::factory()->create(['slug' => 'impersonate-target']);
    $this->tenant->makeCurrent();
    Property::factory()->live()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['name' => 'Operator One']);
    Tenant::forgetCurrent();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- Issue ---------- */

it('issues a 15-minute single-use token and audit-logs the issue', function (): void {
    $url = app(IssueImpersonationToken::class)->handle($this->tenant, $this->admin);

    expect($url)->toStartWith('https://'.$this->tenant->primaryHost().'/admin-impersonate/');

    $row = AdminImpersonation::query()->first();
    expect($row)->not->toBeNull();
    expect($row->admin_id)->toBe($this->admin->id);
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->used_at)->toBeNull();
    $minutes = abs((float) $row->expires_at->diffInMinutes(Carbon::now()));
    expect($minutes)->toBeGreaterThanOrEqual(14.0)->toBeLessThanOrEqual(15.1);
});

it('exposes the impersonation URL on the tenant detail page', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(AdminTenantsShow::class, ['tenant' => $this->tenant])
        ->call('impersonate');

    expect($component->get('impersonationUrl'))->toContain('/admin-impersonate/');
});

/* ---------- Consume ---------- */

it('lands the admin as the first operator and stamps used_at on consume', function (): void {
    $url = app(IssueImpersonationToken::class)->handle($this->tenant, $this->admin);
    $token = basename(parse_url($url, PHP_URL_PATH));

    $response = $this->get('http://impersonate-target.keverna.test/admin-impersonate/'.$token);

    $response->assertRedirect(route('tenant.dashboard'));

    $row = AdminImpersonation::query()->first();
    expect($row->used_at)->not->toBeNull();
    expect($row->operator_id)->toBe($this->operator->id);

    // Operator should now be authenticated on the web guard.
    expect(auth()->guard('web')->check())->toBeTrue();
    expect(auth()->guard('web')->id())->toBe($this->operator->id);

    // Session marker for the banner.
    expect(session('impersonator_admin_id'))->toBe($this->admin->id);
});

it('rejects a token used twice (single-use)', function (): void {
    $url = app(IssueImpersonationToken::class)->handle($this->tenant, $this->admin);
    $token = basename(parse_url($url, PHP_URL_PATH));

    $this->get('http://impersonate-target.keverna.test/admin-impersonate/'.$token)
        ->assertRedirect();

    // Second hit on the same token should 404.
    $this->get('http://impersonate-target.keverna.test/admin-impersonate/'.$token)
        ->assertNotFound();
});

it('rejects an expired token', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $url = app(IssueImpersonationToken::class)->handle($this->tenant, $this->admin);
    $token = basename(parse_url($url, PHP_URL_PATH));

    Carbon::setTestNow(Carbon::parse('2030-08-01 10:30:00')); // 30 min later, past the 15-min TTL

    $this->get('http://impersonate-target.keverna.test/admin-impersonate/'.$token)
        ->assertNotFound();
});

it('rejects a wrong token plaintext', function (): void {
    app(IssueImpersonationToken::class)->handle($this->tenant, $this->admin);

    $this->get('http://impersonate-target.keverna.test/admin-impersonate/not-the-real-token')
        ->assertNotFound();
});

it('rejects a token issued for a different tenant', function (): void {
    Tenant::forgetCurrent();
    $otherTenant = Tenant::factory()->create(['slug' => 'impersonate-other']);
    $otherTenant->makeCurrent();
    Property::factory()->live()->for($otherTenant)->create();
    Operator::factory()->for($otherTenant)->create();
    Tenant::forgetCurrent();

    $url = app(IssueImpersonationToken::class)->handle($otherTenant, $this->admin);
    $token = basename(parse_url($url, PHP_URL_PATH));

    // Hit the OTHER tenant's URL on THIS tenant's host — should 404.
    $this->get('http://impersonate-target.keverna.test/admin-impersonate/'.$token)
        ->assertNotFound();
});

/* ---------- Banner + end ---------- */

it('renders the impersonation banner on tenant pages during impersonation', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->withSession(['impersonator_admin_id' => $this->admin->id])
        ->get('http://impersonate-target.keverna.test/dashboard');

    $response->assertOk();
    $response->assertSeeHtml('data-testid="impersonation-banner"');
});

it('ends impersonation by logging the operator out + bouncing to admin', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->withSession(['impersonator_admin_id' => $this->admin->id])
        ->post('http://impersonate-target.keverna.test/end-impersonation');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/tenants/'.$this->tenant->id);
    expect(auth()->guard('web')->check())->toBeFalse();
});
