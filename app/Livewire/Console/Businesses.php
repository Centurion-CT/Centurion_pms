<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Actions\Domains\CheckOwnDomain;
use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureConsoleAdmin;
use App\Models\Tenant;
use App\Platform\ConsoleAdmins;
use App\Services\Paystack\BusinessPaystack;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every business on the platform, for the platform owner
 * (docs/MULTITENANCY.md, step 7): what each one has set up, what needs
 * a look, and pause / resume. Read-only otherwise; there is no
 * "log in as this business".
 *
 * Pausing sets the business to Locked: its booking pages show "not
 * taking online bookings" and its staff are signed out and refused
 * (EnsureBusinessIsOpen). Resuming sets it back to Active.
 */
#[Layout('console.layout')]
class Businesses extends Component
{
    public const VIEWS = ['all' => 'All', 'look' => 'Needs a look', 'paused' => 'Paused'];

    #[Url]
    public string $show = 'all';

    #[Url]
    public string $q = '';

    /**
     * Every request, actions included: Livewire updates don't pass
     * through the route's EnsureConsoleAdmin.
     */
    public function boot(): void
    {
        abort_unless(ConsoleAdmins::allows(session(EnsureConsoleAdmin::SESSION_KEY)), 403);
    }

    public function pause(int $id): void
    {
        $tenant = Tenant::query()->findOrFail($id);
        if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Trial, TenantStatus::Grace], true)) {
            return;
        }
        $tenant->forceFill(['status' => TenantStatus::Locked])->save();
        $this->log($tenant, 'paused');
        $this->dispatch('notify', message: "{$tenant->name} is paused.");
    }

    public function resume(int $id): void
    {
        $tenant = Tenant::query()->findOrFail($id);
        if ($tenant->status !== TenantStatus::Locked) {
            return;
        }
        $tenant->forceFill(['status' => TenantStatus::Active])->save();
        $this->log($tenant, 'resumed');
        $this->dispatch('notify', message: "{$tenant->name} is running again.");
    }

    public function signOut(): void
    {
        session()->forget(EnsureConsoleAdmin::SESSION_KEY);
        session()->regenerate();
        $this->redirectRoute('console.login');
    }

    public function render(): View
    {
        $all = $this->rows();
        $rows = $all
            ->when($this->show === 'look', fn (Collection $c) => $c->filter(fn ($r) => $r->issues !== []))
            ->when($this->show === 'paused', fn (Collection $c) => $c->filter(fn ($r) => $r->tenant->status === TenantStatus::Locked))
            ->when(trim($this->q) !== '', function (Collection $c) {
                $q = mb_strtolower(trim($this->q));

                return $c->filter(fn ($r) => str_contains(mb_strtolower($r->tenant->name.' '.$r->tenant->slug.' '.$r->tenant->custom_domain.' '.$r->tenant->email), $q));
            })
            ->values();

        return view('livewire.console.businesses', [
            'rows' => $rows,
            'counts' => [
                'all' => $all->count(),
                'look' => $all->filter(fn ($r) => $r->issues !== [])->count(),
                'paused' => $all->filter(fn ($r) => $r->tenant->status === TenantStatus::Locked)->count(),
            ],
            'admin' => (string) session(EnsureConsoleAdmin::SESSION_KEY),
        ])->title('Businesses · '.config('brand.name').' console');
    }

    /**
     * One row per business, archived ones left out, with its counts and
     * what needs a look.
     *
     * @return Collection<int, object>
     */
    private function rows(): Collection
    {
        $count = fn (string $table, ?callable $where = null) => DB::table($table)
            ->whereColumn("{$table}.tenant_id", 'tenants.id')
            ->when($where, $where)
            ->selectRaw('count(*)');

        return Tenant::query()
            ->where('status', '!=', TenantStatus::Archived->value)
            ->select('tenants.*')
            ->selectSub($count('properties'), 'buildings')
            ->selectSub($count('properties', fn ($q) => $q->where('is_live', true)), 'live_buildings')
            ->selectSub($count('room_categories'), 'types')
            ->selectSub($count('operators'), 'staff')
            ->selectSub($count('bookings', fn ($q) => $q->where('bookings.created_at', '>=', Carbon::now()->subDays(30))), 'bookings_30d')
            ->selectSub(DB::table('bookings')->whereColumn('bookings.tenant_id', 'tenants.id')->selectRaw('max(created_at)'), 'last_booking_at')
            ->orderByDesc('tenants.created_at')
            ->get()
            ->map(fn (Tenant $t) => (object) [
                'tenant' => $t,
                'buildings' => (int) $t->buildings,
                'liveBuildings' => (int) $t->live_buildings,
                'types' => (int) $t->types,
                'staff' => (int) $t->staff,
                'bookings30d' => (int) $t->bookings_30d,
                'lastBookingAt' => $t->last_booking_at ? Carbon::parse($t->last_booking_at) : null,
                'issues' => $this->issues($t),
            ]);
    }

    /** @return list<string> */
    private function issues(Tenant $t): array
    {
        $issues = [];
        if ($t->status === TenantStatus::Locked) {
            $issues[] = 'Paused';
        }
        if ((int) $t->types === 0) {
            $issues[] = 'No apartment types yet';
        } elseif ((int) $t->live_buildings === 0) {
            $issues[] = 'No building is live, so no online bookings';
        }
        if (BusinessPaystack::isFallback($t)) {
            $issues[] = "Payments not set up: paid into the platform's Paystack account";
        }
        if ($t->custom_domain !== null && $t->domain_verified_at !== null && ! $t->ownDomainIsLive()
            && $t->custom_domain_failed_checks >= CheckOwnDomain::MISSES_BEFORE_PAUSE) {
            $issues[] = "{$t->custom_domain} isn't reaching us";
        }

        return $issues;
    }

    private function log(Tenant $tenant, string $event): void
    {
        activity('console')
            ->performedOn($tenant)
            ->event($event)
            ->withProperties(['admin' => (string) session(EnsureConsoleAdmin::SESSION_KEY)])
            ->tap(fn ($activity) => $activity->tenant_id = $tenant->id)
            ->log(ucfirst($event).' from the '.config('brand.name').' console.');
    }
}
