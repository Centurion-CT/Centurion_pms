<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\Permission;
use App\Livewire\Dashboard\AllBranches\Activity;
use App\Livewire\Dashboard\AllBranches\Index;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Owner\AllBranchesOverview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2030-09-15 13:00:00'));
    $this->tenant = Tenant::factory()->create(['slug' => 'all-branches']);
    $this->tenant->makeCurrent();
    $this->lekki = Property::factory()->for($this->tenant)->create(['name' => 'Lekki']);
    $this->ikoyi = Property::factory()->for($this->tenant)->create(['name' => 'Ikoyi']);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'activated_at' => now()]);

    $this->lekkiRooms = Room::factory()->for($this->tenant)->for($this->lekki)->count(2)->create();
    $this->ikoyiRoom = Room::factory()->for($this->tenant)->for($this->ikoyi)->create();

    // Lekki: one guest in-house, paid ₦200,000 of ₦300,000.
    $stay = Booking::factory()->for($this->tenant)->for($this->lekkiRooms[0])->create([
        'guest_name' => 'Tunde Bakare',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2030-09-14',
        'checkout_date' => '2030-09-17',
        'total_amount' => 300_000,
        'balance_due' => 100_000,
    ]);
    Payment::factory()->for($this->tenant)->for($stay)->succeeded()->create([
        'amount' => 200_000, 'method' => 'transfer', 'paid_at' => Carbon::parse('2030-09-14 15:00'),
    ]);

    // Ikoyi: one guest arriving today, paid ₦50,000 by card.
    $arrival = Booking::factory()->for($this->tenant)->for($this->ikoyiRoom)->confirmed()->create([
        'guest_name' => 'Amaka Eze',
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-16',
        'total_amount' => 50_000,
        'balance_due' => 0,
    ]);
    Payment::factory()->for($this->tenant)->for($arrival)->succeeded()->create([
        'amount' => 50_000, 'method' => 'card', 'paid_at' => Carbon::parse('2030-09-15 10:30'),
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
    Cache::flush();
});

it('adds up money and tonight per branch and in total', function (): void {
    $data = app(AllBranchesOverview::class)->build(Property::query()->orderBy('id')->get(), 'month');

    expect($data['money']['collected'])->toBe(250_000);
    expect(collect($data['branches'])->pluck('collected', 'name')->all())->toBe(['Lekki' => 200_000, 'Ikoyi' => 50_000]);

    // Lekki 1 of 2 rooms taken (in-house), Ikoyi 1 of 1 (arriving).
    expect(collect($data['branches'])->map(fn ($b) => [$b['house']['occupied'], $b['house']['total']])->all())
        ->toBe([[1, 2], [1, 1]]);
    expect($data['tonight'])->toMatchArray(['occupied' => 2, 'total' => 3, 'pct' => 67, 'in_house' => 1, 'arriving' => 1, 'free' => 1]);

    $lekki = collect($data['branches'])->firstWhere('name', 'Lekki');
    expect($lekki['in_house'])->toBe(1);
    $ikoyi = collect($data['branches'])->firstWhere('name', 'Ikoyi');
    expect($ikoyi['arriving'])->toBe(1);

    // Owed totals are the sum of the branches.
    expect($data['money']['owed'])->toBe((int) collect($data['branches'])->sum('owed'));
    expect(collect($data['methods'])->pluck('amount')->sum())->toBe(250_000);
});

it('narrows money to the period picked', function (): void {
    $today = app(AllBranchesOverview::class)->build(Property::query()->orderBy('id')->get(), 'today');

    expect($today['money']['collected'])->toBe(50_000);
    expect($today['window']['prev_from']->toDateString())->toBe('2030-09-14');
});

it('keeps a picked date range to three months at most', function (): void {
    $w = AllBranchesOverview::window('custom', '2030-01-01', '2030-09-15');

    expect((int) $w['from']->diffInDays($w['to']) + 1)->toBe(AllBranchesOverview::MAX_CUSTOM_DAYS);
    expect($w['to']->toDateString())->toBe('2030-09-15');
});

it('shows the page and a row per branch to a manager', function (): void {
    $this->actingAs($this->manager, 'web')
        ->get('http://all-branches.keverna.test/dashboard/all-branches')
        ->assertOk()
        ->assertSee('All branches')
        ->assertSee('data-testid="ab-branch-'.$this->lekki->slug.'"', false)
        ->assertSee('data-testid="ab-branch-'.$this->ikoyi->slug.'"', false)
        ->assertSee('₦250k');
});

it('lists live activity across branches, tagged with the branch', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Activity::class)
        ->assertSee('Payment by card')
        ->assertSee('Amaka Eze')
        ->assertSee('Ikoyi')
        ->assertSee('+₦50,000');
});

it('switches period from the page', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('setPeriod', 'today')
        ->assertSet('period', 'today')
        ->assertSee('Collected today')
        ->call('setPeriod', 'nonsense')
        ->assertSet('period', 'month');
});

it('keeps front desk staff out unless given the overview permission', function (): void {
    $desk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'activated_at' => now()]);

    $this->actingAs($desk, 'web')
        ->get('http://all-branches.keverna.test/dashboard/all-branches')
        ->assertRedirect();

    $desk->forceFill(['permissions' => [Permission::AllBranchesOverview->value]])->save();
    $desk->branches()->attach($this->ikoyi->id);

    // With the permission they see every branch, but can only open theirs.
    $this->actingAs($desk->fresh(), 'web')
        ->get('http://all-branches.keverna.test/dashboard/all-branches')
        ->assertOk()
        ->assertSee('data-testid="ab-branch-'.$this->lekki->slug.'"', false)
        ->assertSee('data-testid="ab-open-'.$this->ikoyi->slug.'"', false)
        ->assertDontSee('data-testid="ab-open-'.$this->lekki->slug.'"', false);
});

it('puts All branches in the sidebar once there are two branches', function (): void {
    $this->actingAs($this->manager, 'web')
        ->get('http://all-branches.keverna.test/dashboard')
        ->assertSee(route('tenant.all-branches'), false);

    $this->ikoyi->delete();
    Cache::flush();

    $this->actingAs($this->manager, 'web')
        ->get('http://all-branches.keverna.test/dashboard')
        ->assertDontSee(route('tenant.all-branches'), false);
});

it('sends the old Portfolio address to All branches', function (): void {
    $this->actingAs($this->manager, 'web')
        ->get('http://all-branches.keverna.test/dashboard/portfolio')
        ->assertRedirect('/dashboard/all-branches');
});
