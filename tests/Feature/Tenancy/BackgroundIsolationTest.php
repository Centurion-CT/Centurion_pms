<?php

declare(strict_types=1);

use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateStatus;
use App\Enums\MembershipStatus;
use App\Enums\OperatorRole;
use App\Mail\Corporates\LowBalanceAlertMail;
use App\Mail\Operators\OperatorWelcomeMail;
use App\Models\Corporate;
use App\Models\CorporateDomain;
use App\Models\CorporateMember;
use App\Models\CorporateWallet;
use App\Models\CorporateWalletTopup;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Corporates\CorporateResolver;
use App\Services\Corporates\TopupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Two businesses side by side, away from page requests
 * (docs/MULTITENANCY.md, step 2): corporate clients, queued jobs,
 * the cache, scheduled commands and links in emails.
 */

beforeEach(function (): void {
    $this->a = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Suites']);
    $this->b = Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Homes']);
    $this->propA = Property::factory()->for($this->a)->create();
    $this->propB = Property::factory()->for($this->b)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/** A live corporate with one active member and a verified email domain, for $tenant. */
function corporateFor(Tenant $tenant, string $slug = 'dangote'): Corporate
{
    $tenant->makeCurrent();
    $corp = Corporate::query()->create([
        'slug' => $slug, 'name' => ucfirst($slug).' '.$tenant->name, 'status' => CorporateStatus::Active->value,
        'primary_email' => "ops@{$slug}.ng", 'country' => 'NG', 'created_by_tenant_id' => $tenant->id,
    ]);
    $corp->members()->create([
        'name' => 'Tobi', 'email' => "tobi@{$slug}.ng", 'phone' => '08031234567',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $corp->domains()->create(['domain' => "{$slug}.ng", 'verified_at' => now()]);
    Tenant::forgetCurrent();

    return $corp;
}

it("keeps each business's corporate clients to itself", function (): void {
    $corpA = corporateFor($this->a);
    corporateFor($this->b);

    $this->b->makeCurrent();
    expect(Corporate::query()->pluck('name')->all())->toBe(['Dangote Bravo Homes'])
        ->and(Corporate::query()->find($corpA->id))->toBeNull()
        ->and(CorporateMember::query()->count())->toBe(1);

    $resolver = app(CorporateResolver::class);
    expect($resolver->resolve('tobi@dangote.ng')['corporate']->tenant_id)->toBe($this->b->id)
        ->and($resolver->resolve('someone@dangote.ng')['corporate']->tenant_id)->toBe($this->b->id)
        ->and($resolver->resolveByPhone('0803 123 4567')['corporate']->tenant_id)->toBe($this->b->id);
});

it("does not match a guest to another business's corporate", function (): void {
    corporateFor($this->a);

    $this->b->makeCurrent();
    $resolver = app(CorporateResolver::class);
    expect($resolver->resolve('tobi@dangote.ng'))->toBeNull()
        ->and($resolver->resolve('someone@dangote.ng'))->toBeNull()
        ->and($resolver->resolveByPhone('08031234567'))->toBeNull();
});

it("does not open another business's corporate", function (): void {
    corporateFor($this->a);
    $manager = Operator::factory()->for($this->b)->create(['role' => OperatorRole::Manager]);

    $this->actingAs($manager, 'web')
        ->get('http://bravo.keverna.test/dashboard/corporates/dangote')
        ->assertNotFound();
});

it('lets two businesses use the same corporate address and email domain', function (): void {
    $corpA = corporateFor($this->a);
    $corpB = corporateFor($this->b);

    expect($corpA->slug)->toBe($corpB->slug)
        ->and(CorporateDomain::query()->withoutGlobalScopes()->where('domain', 'dangote.ng')->count())->toBe(2);
});

it("does not credit another business's corporate top-up", function (): void {
    $corpA = corporateFor($this->a);
    $topup = CorporateWalletTopup::query()->create([
        'corporate_id' => $corpA->id, 'reference' => 'KCT-ALPHA1', 'amount_naira' => 50_000, 'status' => CorporateWalletTopup::STATUS_PENDING,
    ]);
    Http::fake();

    $this->b->makeCurrent();
    expect(app(TopupService::class)->finaliseFromReference('KCT-ALPHA1'))->toBeFalse();

    Http::assertNothingSent();
    expect($topup->refresh()->status)->toBe(CorporateWalletTopup::STATUS_PENDING);
});

it("links a corporate email to the corporate's own business, even from a queue worker", function (): void {
    $corpA = corporateFor($this->a);
    $wallet = CorporateWallet::query()->create(['corporate_id' => $corpA->id]);

    $html = (new LowBalanceAlertMail($corpA, $wallet))->render();

    expect($html)->toContain('https://alpha.keverna.test/corporates/wallet')
        ->and($html)->toContain('Alpha Suites')
        ->and(Tenant::current()?->id)->toBe($this->a->id);
});

it('renews corporate memberships at every business', function (): void {
    foreach ([$this->a, $this->b] as $tenant) {
        $corp = corporateFor($tenant);
        $tenant->makeCurrent();
        $customer = Customer::query()->create(['tenant_id' => $tenant->id, 'name' => 'Desk user', 'phone' => '0809'.$tenant->id]);
        $planId = DB::table('workspace_plans')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Hot desk', 'billing_interval' => 'month', 'price_naira' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Membership::query()->create([
            'customer_id' => $customer->id, 'workspace_plan_id' => $planId, 'corporate_id' => $corp->id,
            'status' => MembershipStatus::Active->value, 'started_at' => '2026-09-01',
            'next_renewal_at' => '2026-10-01', 'amount_naira' => 0, 'billing_interval' => 'month',
        ]);
        Tenant::forgetCurrent();
    }

    Artisan::call('corporates:renew-memberships');

    $dates = Membership::query()->withoutGlobalScope(TenantScope::class)->pluck('next_renewal_at')
        ->map(fn (Carbon $d) => $d->toDateString())->all();
    expect($dates)->toBe(['2026-11-01', '2026-11-01'])
        ->and(Tenant::current())->toBeNull();
});

it('runs each queued job as the business that queued it', function (): void {
    config(['queue.default' => 'database']);
    $seen = [];
    app()->instance('isolation.seen', function (?int $id) use (&$seen): void {
        $seen[] = $id;
    });

    $this->a->makeCurrent();
    dispatch(fn () => app('isolation.seen')(Tenant::current()?->id));
    $this->b->makeCurrent();
    dispatch(fn () => app('isolation.seen')(Tenant::current()?->id));
    Tenant::forgetCurrent();

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

    expect($seen)->toBe([$this->a->id, $this->b->id]);
});

it('runs a mail that is not tied to a business with no business bound', function (): void {
    config(['queue.default' => 'database']);
    $manager = Operator::factory()->for($this->a)->create(['role' => OperatorRole::Manager]);

    $this->b->makeCurrent();
    dispatch(fn () => null);
    Tenant::forgetCurrent();
    Mail::to('x@example.ng')->queue(new OperatorWelcomeMail($manager, $this->a));

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
    expect(Tenant::current()?->id)->toBe($this->b->id);

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
    expect(Tenant::current())->toBeNull();
});

it('keeps cached values apart per business', function (): void {
    // Tests use the array store, which starts empty on every switch, so
    // check the prefix the Redis store puts on every key.
    $platform = config('cache.prefix');

    $this->a->makeCurrent();
    expect(config('cache.prefix'))->toBe("tenant_id_{$this->a->id}");

    $this->b->makeCurrent();
    expect(config('cache.prefix'))->toBe("tenant_id_{$this->b->id}");

    Tenant::forgetCurrent();
    expect(config('cache.prefix'))->toBe($platform);
});
