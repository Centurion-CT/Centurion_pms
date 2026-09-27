<?php

declare(strict_types=1);

use App\Livewire\Admin\Auth\Login as AdminLogin;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create([
        'email' => 'admin@keverna.test',
        'password' => bcrypt('correct-horse-battery'),
        'is_active' => true,
    ]);
});

it('renders the admin login page', function (): void {
    $response = $this->get('http://admin.keverna.test/admin/login');
    $response->assertOk();
    $response->assertSeeLivewire(AdminLogin::class);
});

it('logs an active admin in with correct credentials', function (): void {
    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'correct-horse-battery')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('http://admin.keverna.test/admin');

    expect(auth()->guard('admin')->check())->toBeTrue();
});

it('honours an intended URL when it is on the admin host', function (): void {
    // The Authenticate middleware writes this key when it bounces a
    // guest to /admin/login. On success we want to send them there.
    $intended = 'http://'.config('keverna.admin_domain').'/admin/tenants';
    session()->put('url.intended', $intended);

    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'correct-horse-battery')
        ->call('authenticate')
        ->assertRedirect($intended);
});

it('drops a cross-domain intended URL and falls back to the admin dashboard', function (): void {
    // SESSION_DOMAIN=.keverna.com shares session cookies across
    // subdomains. If a page on keverna.com or a tenant subdomain
    // seeded url.intended with a cross-domain URL, admin login must
    // NOT honour it — otherwise a successful admin sign-in bounces
    // to the marketing landing or a random tenant page.
    session()->put('url.intended', 'http://'.config('keverna.central_domain').'/some-marketing-page');

    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'correct-horse-battery')
        ->call('authenticate')
        ->assertRedirect(route('admin.dashboard'));
});

it('drops a tenant-subdomain intended URL and falls back to the admin dashboard', function (): void {
    session()->put('url.intended', 'http://citistay'.config('keverna.tenant_domain_suffix').'/dashboard');

    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'correct-horse-battery')
        ->call('authenticate')
        ->assertRedirect(route('admin.dashboard'));
});

it('rejects wrong credentials', function (): void {
    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'nope')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('rejects a deactivated admin even with correct credentials', function (): void {
    $this->admin->forceFill(['is_active' => false])->save();

    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'correct-horse-battery')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('stamps last_login_at on a successful login', function (): void {
    expect($this->admin->last_login_at)->toBeNull();

    Livewire::test(AdminLogin::class)
        ->set('email', 'admin@keverna.test')
        ->set('password', 'correct-horse-battery')
        ->call('authenticate');

    expect($this->admin->fresh()->last_login_at)->not->toBeNull();
});

/* ---------- Protected routes ---------- */

it('redirects unauthenticated requests to the admin login (not the tenant login)', function (): void {
    $response = $this->get('http://admin.keverna.test/admin/tenants');
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
});

it('allows an authenticated admin to reach the dashboard', function (): void {
    $response = $this->actingAs($this->admin, 'admin')
        ->get('http://admin.keverna.test/admin');
    $response->assertOk();
    $response->assertSeeHtml('data-testid="admin-counters"');
});

it('does NOT let an operator (web guard) authenticate on the admin routes', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $operator = Operator::factory()->for($tenant)->create();
    Tenant::forgetCurrent();

    // Operator authenticated under `web` guard, NOT `admin` — should still
    // be redirected to admin login by auth:admin middleware.
    $response = $this->actingAs($operator, 'web')
        ->get('http://admin.keverna.test/admin');
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
});

it('logs the admin out and bounces to login', function (): void {
    $response = $this->actingAs($this->admin, 'admin')
        ->post('http://admin.keverna.test/admin/logout');
    $response->assertRedirect('http://admin.keverna.test/admin/login');
    expect(auth()->guard('admin')->check())->toBeFalse();
});
