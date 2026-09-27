<?php

declare(strict_types=1);

use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * BelongsToTenant needs an active tenant context to create Operator rows,
 * but the backfill itself walks operators with global scopes off. We
 * unset the current tenant on each write via makeCurrent so factories
 * work, then Tenant::forgetCurrent between tests.
 */
afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('creates an identity_account and link for each operator', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'ta']);
    $tenantB = Tenant::factory()->create(['slug' => 'tb']);

    $tenantA->makeCurrent();
    Operator::factory()->for($tenantA)->create([
        'email' => 'ann@ta.test',
        'password' => Hash::make('secret-a'),
        'name' => 'Ann',
    ]);

    $tenantB->makeCurrent();
    Operator::factory()->for($tenantB)->create([
        'email' => 'bo@tb.test',
        'password' => Hash::make('secret-b'),
        'name' => 'Bo',
    ]);
    Tenant::forgetCurrent();

    Artisan::call('identity:backfill-operators');

    expect(IdentityAccount::count())->toBe(2);
    expect(IdentityOperatorLink::count())->toBe(2);

    $ann = IdentityAccount::where('email', 'ann@ta.test')->firstOrFail();
    expect($ann->name)->toBe('Ann');
    expect(Hash::check('secret-a', (string) $ann->password))->toBeTrue();
    expect($ann->operatorLinks()->where('tenant_id', $tenantA->id)->exists())->toBeTrue();
});

it('is idempotent — re-running does not duplicate identities or links', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'idem']);
    $tenant->makeCurrent();
    Operator::factory()->for($tenant)->create(['email' => 'x@idem.test']);
    Tenant::forgetCurrent();

    Artisan::call('identity:backfill-operators');
    Artisan::call('identity:backfill-operators');

    expect(IdentityAccount::count())->toBe(1);
    expect(IdentityOperatorLink::count())->toBe(1);
});

it('respects --tenant filter', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'only-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'only-b']);

    $tenantA->makeCurrent();
    Operator::factory()->for($tenantA)->create(['email' => 'a@a.test']);
    $tenantB->makeCurrent();
    Operator::factory()->for($tenantB)->create(['email' => 'b@b.test']);
    Tenant::forgetCurrent();

    Artisan::call('identity:backfill-operators', ['--tenant' => $tenantA->id]);

    expect(IdentityAccount::count())->toBe(1);
    expect(IdentityAccount::first()->email)->toBe('a@a.test');
});

it('normalises email casing at backfill', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'case']);
    $tenant->makeCurrent();
    Operator::factory()->for($tenant)->create(['email' => 'MixED@Case.TEST']);
    Tenant::forgetCurrent();

    Artisan::call('identity:backfill-operators');

    expect(IdentityAccount::first()->email)->toBe('mixed@case.test');
});

it('does not write anything on --dry-run', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'dry']);
    $tenant->makeCurrent();
    Operator::factory()->for($tenant)->create(['email' => 'dry@x.test']);
    Tenant::forgetCurrent();

    Artisan::call('identity:backfill-operators', ['--dry-run' => true]);

    expect(IdentityAccount::count())->toBe(0);
    expect(IdentityOperatorLink::count())->toBe(0);
});

it('logs a cross-tenant email collision instead of auto-linking', function (): void {
    // Set up: identity already exists with a link on tenant A. This is
    // the situation right after a partial backfill. Then a second
    // Operator with the same email on tenant B arrives. Because the
    // Operator table today enforces globally-unique email, we can only
    // reach this state by seeding manually via DB::table (bypassing
    // the unique constraint).
    $tenantA = Tenant::factory()->create(['slug' => 'colA']);
    $tenantB = Tenant::factory()->create(['slug' => 'colB']);

    $tenantA->makeCurrent();
    $opA = Operator::factory()->for($tenantA)->create(['email' => 'shared@x.test']);
    Tenant::forgetCurrent();

    // First backfill run creates identity + link for op A.
    Artisan::call('identity:backfill-operators');

    // Now insert a second Operator with the same email on tenant B via
    // raw DB, sidestepping the unique index. This simulates a legacy
    // import where the constraint was added retroactively.
    DB::table('operators')->insert([
        // Deliberately different case to also prove normalisation kicks in.
        'email' => 'SHARED@x.test',
        'tenant_id' => $tenantB->id,
        'name' => 'Second seat',
        'password' => Hash::make('other'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('identity:backfill-operators');

    // Existing identity keeps its single link — the new operator does NOT
    // get auto-linked.
    expect(IdentityAccount::count())->toBe(1);
    expect(IdentityOperatorLink::count())->toBe(1);
    expect(DB::table('identity_backfill_conflicts')->count())->toBe(1);

    $conflict = DB::table('identity_backfill_conflicts')->first();
    expect($conflict->source_kind)->toBe('operator');
    expect($conflict->email)->toBe('shared@x.test');
});
