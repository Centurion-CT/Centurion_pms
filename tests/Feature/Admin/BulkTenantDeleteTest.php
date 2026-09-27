<?php

declare(strict_types=1);

use App\Livewire\Admin\Tenants\Index;
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
 * Bulk tenant deletion. Guards are ANDed with the single-tenant
 * destroy: typed phrase must include the exact count, reason is
 * required, TOTP verifies against the admin's secret, and the
 * batch is capped at BULK_MAX.
 */
beforeEach(function (): void {
    $this->totp = new TotpService();

    $this->admin = Admin::factory()->super()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => Carbon::now()->subDay(),
    ]);
    session()->put('admin.2fa.passed', true);

    // Seed three tenants each with a booking so the cascade has
    // something concrete to remove.
    $this->tenants = collect(['alpha', 'beta', 'gamma'])->map(function (string $slug): Tenant {
        $tenant = Tenant::factory()->create(['slug' => $slug]);
        $tenant->makeCurrent();
        Subscription::factory()->for($tenant)->trial()->create();
        $property = Property::factory()->for($tenant)->create();
        $cat = RoomCategory::factory()->for($tenant)->for($property)->create([
            'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
        ]);
        $room = Room::factory()->for($tenant)->for($property)->for($cat, 'category')->create();
        Booking::factory()->for($tenant)->for($room)->create();
        Tenant::forgetCurrent();
        return $tenant;
    });
});

afterEach(fn () => Tenant::forgetCurrent());

function bulkCurrentTotp(TotpService $svc, string $secret): string
{
    $r = new ReflectionMethod(TotpService::class, 'computeCode');
    $r->setAccessible(true);
    return $r->invoke($svc, $secret, intdiv(time(), 30));
}

it('refuses when the confirmation phrase does not match the count', function (): void {
    $good = bulkCurrentTotp($this->totp, 'JBSWY3DPEHPK3PXP');
    $ids = $this->tenants->pluck('id')->all();

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Index::class)
        ->set('selected', array_fill_keys($ids, true))
        ->set('bulkPhrase', 'DELETE 2 TENANTS') // wrong count
        ->set('bulkReason', 'Cleanup')
        ->set('bulkTotpCode', $good)
        ->call('bulkDestroy')
        ->assertHasErrors('bulkPhrase');

    expect(Tenant::query()->withoutGlobalScope(TenantScope::class)->whereIn('id', $ids)->count())
        ->toBe(3);
});

it('refuses without a reason', function (): void {
    $good = bulkCurrentTotp($this->totp, 'JBSWY3DPEHPK3PXP');
    $ids = $this->tenants->pluck('id')->all();

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Index::class)
        ->set('selected', array_fill_keys($ids, true))
        ->set('bulkPhrase', 'DELETE 3 TENANTS')
        ->set('bulkReason', '')
        ->set('bulkTotpCode', $good)
        ->call('bulkDestroy')
        ->assertHasErrors('bulkReason');
});

it('refuses with a wrong TOTP code', function (): void {
    $ids = $this->tenants->pluck('id')->all();

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Index::class)
        ->set('selected', array_fill_keys($ids, true))
        ->set('bulkPhrase', 'DELETE 3 TENANTS')
        ->set('bulkReason', 'Cleanup')
        ->set('bulkTotpCode', '000000')
        ->call('bulkDestroy')
        ->assertHasErrors('bulkTotpCode');
});

it('deletes every selected tenant + logs one bulk audit entry', function (): void {
    $good = bulkCurrentTotp($this->totp, 'JBSWY3DPEHPK3PXP');
    $ids = $this->tenants->pluck('id')->all();

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Index::class)
        ->set('selected', array_fill_keys($ids, true))
        ->set('bulkPhrase', 'DELETE 3 TENANTS')
        ->set('bulkReason', 'Cleanup batch')
        ->set('bulkTotpCode', $good)
        ->call('bulkDestroy')
        ->assertHasNoErrors();

    // All tenants + their bookings gone.
    expect(Tenant::query()->withoutGlobalScope(TenantScope::class)->whereIn('id', $ids)->count())
        ->toBe(0);
    expect(Booking::query()->withoutGlobalScope(TenantScope::class)->whereIn('tenant_id', $ids)->count())
        ->toBe(0);

    // One consolidated bulk-log entry (+ one per-tenant entry from
    // the underlying service).
    $bulk = \DB::table('activity_log')->where('event', 'tenant_bulk_deleted')->latest('id')->first();
    expect($bulk)->not->toBeNull();
    $props = json_decode($bulk->properties, true);
    expect($props['succeeded'])->toBe(3);
    expect($props['failed'])->toBe([]);
    expect($props['reason'])->toBe('Cleanup batch');
});
