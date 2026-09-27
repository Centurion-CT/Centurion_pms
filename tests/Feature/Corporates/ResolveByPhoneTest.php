<?php

declare(strict_types=1);

use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateStatus;
use App\Enums\OperatorRole;
use App\Livewire\Concerns\HasCustomerLookup;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Corporates\CorporateResolver;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Corporate phone-based resolution. Front desk taking a walk-in with
 * only a phone number should still auto-link the guest to the corp if
 * the phone matches a profiled member — the CorporateBookingService
 * calls this resolver from attachToBooking as a fallback when the
 * guest_email lookup misses.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'phone-resolve']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    $this->member = CorporateMember::query()->create([
        'corporate_id' => $this->corp->id,
        'name' => 'Ada Traveller',
        'email' => 'ada@acme.test',
        'phone' => '+2348011111111',
        'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    // A corporate wallet is required for attachToBooking to place its
    // hold. Seed with plenty of headroom so the test isn't sensitive
    // to overdraft rules.
    \App\Models\CorporateWallet::query()->create([
        'corporate_id' => $this->corp->id,
        'balance_naira' => 10_000_000,
        'currency' => 'NGN',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

// ── Direct resolver ──────────────────────────────────────────────

it('resolves by an exactly-stored E.164 phone', function (): void {
    $r = app(CorporateResolver::class)->resolveByPhone('+2348011111111');
    expect($r)->not->toBeNull();
    expect($r['corporate']->id)->toBe($this->corp->id);
    expect($r['member']->id)->toBe($this->member->id);
});

it('resolves when the operator types the local mobile format (08011111111)', function (): void {
    $r = app(CorporateResolver::class)->resolveByPhone('08011111111');
    expect($r)->not->toBeNull();
    expect($r['member']->id)->toBe($this->member->id);
});

it('resolves when the phone has spaces + dashes', function (): void {
    $r = app(CorporateResolver::class)->resolveByPhone('+234 801 111 1111');
    expect($r)->not->toBeNull();
});

it('does not resolve for a phone that belongs to nobody', function (): void {
    $r = app(CorporateResolver::class)->resolveByPhone('+2349099999999');
    expect($r)->toBeNull();
});

it('does not resolve when the member is offboarded', function (): void {
    $this->member->forceFill(['status' => CorporateMemberStatus::Offboarded->value])->save();
    $r = app(CorporateResolver::class)->resolveByPhone('+2348011111111');
    expect($r)->toBeNull();
});

it('does not resolve when the parent corporate is not live', function (): void {
    $this->corp->forceFill(['status' => CorporateStatus::Suspended->value])->save();
    $r = app(CorporateResolver::class)->resolveByPhone('+2348011111111');
    expect($r)->toBeNull();
});

// ── HasCustomerLookup: corporateMemberMatches surfaces the match ──

it('customer typeahead surfaces the corp member when searching by phone', function (): void {
    // Create a tiny throwaway Livewire component that just uses the
    // trait. Everything else is stubbed to no-op.
    $operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    $component = new class extends Component {
        use HasCustomerLookup;
        public string $guest_name = '';
        public string $guest_email = '';
        public string $guest_phone = '';
        public function render(): string { return '<div></div>'; }
    };

    Livewire::actingAs($operator, 'web')
        ->test($component::class)
        ->set('customerSearch', '08011111111')
        ->tap(function ($t): void {
            $matches = $t->instance()->corporateMemberMatches();
            expect($matches->count())->toBe(1);
            expect($matches->first()->name)->toBe('Ada Traveller');
        });
});

it('CorporateBookingService.attachToBooking auto-links via phone when email is blank', function (): void {
    // Regression: previously, a walk-in with just a phone (no email
    // typed at reservation time) missed the corp link because
    // CorporateResolver only knew about email. Now attachToBooking
    // falls back to phone lookup so the booking still lands with
    // corporate_id + corporate_member_id set — which the reservation
    // page reads to light up the corp-guaranteed check-in path.
    $property = \App\Models\Property::factory()->for($this->tenant)->create([
        'accepts_corporates' => true,
    ]);
    $category = \App\Models\RoomCategory::factory()->for($this->tenant)->for($property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000,
    ]);
    $room = \App\Models\Room::factory()->for($this->tenant)->for($property)
        ->for($category, 'category')
        ->create();
    $booking = \App\Models\Booking::factory()->for($this->tenant)->for($room)->create([
        'guest_name' => 'Ada Traveller',
        'guest_email' => '',   // no email — the front desk didn't ask
        'guest_phone' => '08011111111',   // local mobile format
        'total_amount' => 100_000,
        'balance_due' => 100_000,
        'deposit_amount' => 0,
        'nights' => 2,
        'corporate_id' => null,
        'corporate_member_id' => null,
    ]);

    app(\App\Services\Corporates\CorporateBookingService::class)->attachToBooking($booking);

    $booking->refresh();
    expect((int) $booking->corporate_id)->toBe((int) $this->corp->id);
    expect((int) $booking->corporate_member_id)->toBe((int) $this->member->id);
});

it('pickCorporateMember fills the guest fields from the member', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $component = new class extends Component {
        use HasCustomerLookup;
        public string $guest_name = '';
        public string $guest_email = '';
        public string $guest_phone = '';
        public function render(): string { return '<div></div>'; }
    };

    Livewire::actingAs($operator, 'web')
        ->test($component::class)
        ->call('pickCorporateMember', $this->member->id)
        ->assertSet('guest_name', 'Ada Traveller')
        ->assertSet('guest_email', 'ada@acme.test')
        ->assertSet('guest_phone', '+2348011111111');
});
