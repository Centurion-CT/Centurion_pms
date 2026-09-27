<?php

declare(strict_types=1);

use App\Livewire\Admin\Tenants\Destroy;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Auth\TotpService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Super-admin tenant delete flow. Every guard is checked in
 * isolation so a bug in any one of them doesn't silently open the
 * gate for a wrong-code / wrong-slug / no-2FA attempt.
 */
beforeEach(function (): void {
    $this->totp = new TotpService();

    // A super-admin already enrolled in 2FA, with a fresh session
    // 2FA-pass flag set (the challenge-per-request TOTP happens
    // inside the destroy form itself).
    $this->admin = Admin::factory()->super()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => Carbon::now()->subDay(),
    ]);
    session()->put('admin.2fa.passed', true);

    // Fresh tenant + property + room + a live booking, so the
    // cascade actually has something to delete + we can count.
    $this->tenant = Tenant::factory()->create(['slug' => 'to-be-nuked']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $property = Property::factory()->for($this->tenant)->create();
    $cat = RoomCategory::factory()->for($this->tenant)->for($property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $room = Room::factory()->for($this->tenant)->for($property)->for($cat, 'category')->create();
    Booking::factory()->for($this->tenant)->for($room)->create();

    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

function currentTotp(TotpService $svc, string $secret): string
{
    $r = new ReflectionMethod(TotpService::class, 'computeCode');
    $r->setAccessible(true);
    return $r->invoke($svc, $secret, intdiv(time(), 30));
}

it('refuses to delete when the slug confirmation is wrong', function (): void {
    $good = currentTotp($this->totp, 'JBSWY3DPEHPK3PXP');

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Destroy::class, ['tenant' => $this->tenant])
        ->set('slugConfirm', 'wrong-slug')
        ->set('reason', 'testing')
        ->set('totpCode', $good)
        ->call('destroy')
        ->assertHasErrors('slugConfirm');

    expect(Tenant::query()->withoutGlobalScope(TenantScope::class)->find($this->tenant->id))
        ->not->toBeNull();
});

it('refuses to delete when the TOTP code is wrong', function (): void {
    $this->actingAs($this->admin, 'admin');
    Livewire::test(Destroy::class, ['tenant' => $this->tenant])
        ->set('slugConfirm', 'to-be-nuked')
        ->set('reason', 'testing')
        ->set('totpCode', '000000')
        ->call('destroy')
        ->assertHasErrors('totpCode');

    expect(Tenant::query()->withoutGlobalScope(TenantScope::class)->find($this->tenant->id))
        ->not->toBeNull();
});

it('refuses to delete without a reason', function (): void {
    $good = currentTotp($this->totp, 'JBSWY3DPEHPK3PXP');

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Destroy::class, ['tenant' => $this->tenant])
        ->set('slugConfirm', 'to-be-nuked')
        ->set('reason', '')
        ->set('totpCode', $good)
        ->call('destroy')
        ->assertHasErrors('reason');
});

it('deletes the tenant + all its data when every guard passes', function (): void {
    $good = currentTotp($this->totp, 'JBSWY3DPEHPK3PXP');

    // Baseline row counts.
    $bookingCountBefore = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->tenant->id)->count();
    expect($bookingCountBefore)->toBeGreaterThan(0);

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Destroy::class, ['tenant' => $this->tenant])
        ->set('slugConfirm', 'to-be-nuked')
        ->set('reason', 'Fraudulent signup')
        ->set('totpCode', $good)
        ->call('destroy')
        ->assertHasNoErrors();

    // Tenant row gone.
    expect(Tenant::query()->withoutGlobalScope(TenantScope::class)->find($this->tenant->id))
        ->toBeNull();

    // Every booking under it gone.
    expect(Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->tenant->id)->count())
        ->toBe(0);

    // Audit trail written.
    $act = \DB::table('activity_log')
        ->where('event', 'tenant_deleted')
        ->latest('id')->first();
    expect($act)->not->toBeNull();
    $props = json_decode($act->properties, true);
    expect($props['tenant_slug'])->toBe('to-be-nuked');
    expect($props['reason'])->toBe('Fraudulent signup');
});

it('bounces admins without 2FA enrolled to the enrollment page', function (): void {
    $noTwoFactor = Admin::factory()->super()->create([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($noTwoFactor, 'admin');
    Livewire::test(Destroy::class, ['tenant' => $this->tenant])
        ->assertRedirect(route('admin.two-factor.enroll'));
});
