<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Tenants;

use App\Actions\Admin\PurgeTenantBookings;
use App\Actions\Admin\PurgeTenantPosCatalog;
use App\Actions\Admin\PurgeTenantPosSales;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\PosSale;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Super-admin tool to remove a tenant's test records — one at a
 * time, so a bulk mistake is impossible. Two tabs: Bookings and POS
 * sales. In both, the admin picks a specific row, confirms by
 * typing the tenant slug, then the DELETE fires against just that
 * record and its cascading + polymorphic children.
 *
 * The multi-select bulk flow was considered but rejected: a support
 * ticket is usually about a small handful of self-service test rows,
 * and one-by-one leaves less room for accidental damage.
 */
#[Layout('admin.layout')]
class Cleanup extends Component
{
    use WithPagination;

    #[Locked]
    public int $tenantId;

    #[Url(as: 'tab', except: 'bookings')]
    public string $tab = 'bookings';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    // Bookings tab modal state.
    public ?int $pendingId = null;

    // POS tab modal state.
    public ?int $pendingPosId = null;

    // Shared slug retype — one input at a time (only one modal open).
    public string $confirmSlug = '';

    public function mount(Tenant $tenant): void
    {
        $this->guardSuper();
        $this->tenantId = $tenant->id;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['bookings', 'pos', 'catalog'], true)) {
            return;
        }
        $this->tab = $tab;
        $this->search = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    /**
     * Nuclear-option catalog purge - wipes ALL outlets, products,
     * stores + everything they reference (sales, movements, payments,
     * wallet txns). Guarded by the slug-retype so a slip on the button
     * can't wipe a live tenant. See {@see PurgeTenantPosCatalog}.
     */
    public function purgeCatalog(PurgeTenantPosCatalog $action): void
    {
        $this->guardSuper();
        $tenant = $this->tenant();
        if (! $this->slugMatches($tenant)) {
            return;
        }
        try {
            $summary = $action->handle($tenant, $this->admin());
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t purge', message: $e->getMessage(), kind: 'err');
            return;
        }
        $this->confirmSlug = '';
        $this->dispatch('notify',
            title: 'Catalog wiped',
            message: sprintf(
                'Purged %d outlet(s), %d product(s), %d store(s), %d sale(s) (₦%s).',
                $summary['outlets'], $summary['products'],
                $summary['stores'], $summary['sales'],
                number_format($summary['sales_amount']),
            ),
            kind: 'ok',
        );
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    // ── Bookings ────────────────────────────────────────────────

    public function openConfirm(int $bookingId): void
    {
        $this->guardSuper();
        $tenant = $this->tenant();
        $booking = $tenant->execute(fn () => Booking::query()
            ->where('id', $bookingId)
            ->where('tenant_id', $tenant->id)
            ->first());
        if ($booking === null) {
            $this->dispatch('notify', title: 'Not found', message: 'That reservation isn\'t on this tenant.', kind: 'err');
            return;
        }
        $this->pendingId = $bookingId;
        $this->confirmSlug = '';
    }

    public function cancelConfirm(): void
    {
        $this->pendingId = null;
        $this->confirmSlug = '';
    }

    public function purge(PurgeTenantBookings $action): void
    {
        $this->guardSuper();
        if ($this->pendingId === null) {
            return;
        }
        $tenant = $this->tenant();
        if (! $this->slugMatches($tenant)) {
            return;
        }
        try {
            $summary = $action->handle($tenant, $this->admin(), [$this->pendingId]);
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t remove', message: $e->getMessage(), kind: 'err');
            return;
        }
        $this->pendingId = null;
        $this->confirmSlug = '';
        $this->resetPage();
        $this->dispatch('notify',
            title: 'Removed',
            message: sprintf(
                'Deleted reservation and %d payment(s) (₦%s), %d charge(s), %d wallet transaction(s).',
                $summary['payments_count'],
                number_format($summary['payments_amount']),
                $summary['charges_count'],
                $summary['wallet_transactions'],
            ),
            kind: 'ok',
        );
    }

    // ── POS sales ───────────────────────────────────────────────

    public function openPosConfirm(int $saleId): void
    {
        $this->guardSuper();
        $tenant = $this->tenant();
        $sale = $tenant->execute(fn () => PosSale::query()
            ->where('id', $saleId)
            ->where('tenant_id', $tenant->id)
            ->first());
        if ($sale === null) {
            $this->dispatch('notify', title: 'Not found', message: 'That POS sale isn\'t on this tenant.', kind: 'err');
            return;
        }
        $this->pendingPosId = $saleId;
        $this->confirmSlug = '';
    }

    public function cancelPosConfirm(): void
    {
        $this->pendingPosId = null;
        $this->confirmSlug = '';
    }

    public function purgePos(PurgeTenantPosSales $action): void
    {
        $this->guardSuper();
        if ($this->pendingPosId === null) {
            return;
        }
        $tenant = $this->tenant();
        if (! $this->slugMatches($tenant)) {
            return;
        }
        try {
            $summary = $action->handle($tenant, $this->admin(), [$this->pendingPosId]);
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t remove', message: $e->getMessage(), kind: 'err');
            return;
        }
        $this->pendingPosId = null;
        $this->confirmSlug = '';
        $this->resetPage();
        $this->dispatch('notify',
            title: 'Removed',
            message: sprintf(
                'Deleted POS sale (₦%s) + %d payment(s), %d wallet txn(s), %d stock movement(s).',
                number_format($summary['sales_amount']),
                $summary['payments'],
                $summary['wallet_transactions'],
                $summary['stock_movements'],
            ),
            kind: 'ok',
        );
    }

    // ── Render ──────────────────────────────────────────────────

    public function render(): View
    {
        $tenant = $this->tenant();

        $viewData = ['tenant' => $tenant];

        if ($this->tab === 'catalog') {
            $viewData['catalog'] = $tenant->execute(fn () => [
                'outlets'  => \App\Models\Outlet::query()
                    ->orderBy('name')->take(50)->get(['id', 'name', 'type']),
                'products' => \App\Models\Product::query()
                    ->orderBy('name')->take(50)->get(['id', 'name', 'sku', 'outlet_id', 'store_id']),
                'stores'   => \App\Models\Store::query()
                    ->orderBy('name')->take(20)->get(['id', 'name']),
                'counts'   => [
                    'outlets'  => \App\Models\Outlet::query()->count(),
                    'products' => \App\Models\Product::query()->count(),
                    'stores'   => \App\Models\Store::query()->count(),
                    'sales'    => PosSale::query()->count(),
                ],
            ]);
        } elseif ($this->tab === 'pos') {
            $viewData['posSales'] = $this->paginatePos($tenant);
            $viewData['pendingPos'] = $this->pendingPosId !== null
                ? $tenant->execute(fn () => PosSale::query()
                    ->with(['outlet:id,name', 'operator:id,name'])
                    ->find($this->pendingPosId))
                : null;
            $viewData['pendingPosImpact'] = $viewData['pendingPos'] !== null
                ? $this->impactForPos($tenant, $viewData['pendingPos']->id)
                : null;
        } else {
            $viewData['bookings'] = $this->paginate($tenant);
            $viewData['pendingBooking'] = $this->pendingId !== null
                ? $tenant->execute(fn () => Booking::query()
                    ->with('room:id,room_number')
                    ->find($this->pendingId))
                : null;
            $viewData['pendingImpact'] = $viewData['pendingBooking'] !== null
                ? $this->impactFor($tenant, $viewData['pendingBooking']->id)
                : null;
        }

        return view('livewire.admin.tenants.cleanup', $viewData);
    }

    // ── Impact previews ─────────────────────────────────────────

    /**
     * @return array{payments_count:int, payments_amount:int, charges_count:int, wallet_amount:int}
     */
    private function impactFor(Tenant $tenant, int $bookingId): array
    {
        return $tenant->execute(function () use ($bookingId): array {
            $p = \App\Models\Payment::query()
                ->where('booking_id', $bookingId)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')->first();
            $c = \App\Models\BookingCharge::query()
                ->where('booking_id', $bookingId)
                ->selectRaw('COUNT(*) as c')->first();
            $w = (int) \App\Models\CustomerWalletTransaction::query()
                ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                ->where('booking_id', $bookingId)
                ->selectRaw('COALESCE(SUM(ABS(amount_naira)), 0) as s')
                ->value('s');
            return [
                'payments_count' => (int) ($p->c ?? 0),
                'payments_amount' => (int) ($p->s ?? 0),
                'charges_count' => (int) ($c->c ?? 0),
                'wallet_amount' => $w,
            ];
        });
    }

    /**
     * @return array{payments_count:int, payments_amount:int, wallet_amount:int, stock_count:int}
     */
    private function impactForPos(Tenant $tenant, int $saleId): array
    {
        return $tenant->execute(function () use ($saleId): array {
            $p = \App\Models\Payment::query()
                ->where('pos_sale_id', $saleId)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')->first();
            $w = (int) \App\Models\CustomerWalletTransaction::query()
                ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                ->where('pos_sale_id', $saleId)
                ->selectRaw('COALESCE(SUM(ABS(amount_naira)), 0) as s')
                ->value('s');
            $stock = \App\Models\StockMovement::query()
                ->where('source_type', PosSale::class)
                ->where('source_id', $saleId)
                ->count();
            return [
                'payments_count' => (int) ($p->c ?? 0),
                'payments_amount' => (int) ($p->s ?? 0),
                'wallet_amount' => $w,
                'stock_count' => (int) $stock,
            ];
        });
    }

    // ── Paginators ──────────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    private function paginate(Tenant $tenant): LengthAwarePaginator
    {
        return $tenant->execute(function (): LengthAwarePaginator {
            $q = Booking::query()->orderBy('id', 'desc');
            $needle = trim($this->search);
            if ($needle !== '') {
                $q->where(function ($x) use ($needle): void {
                    $x->where('booking_ref', 'like', '%'.$needle.'%')
                        ->orWhere('guest_name', 'like', '%'.$needle.'%')
                        ->orWhere('guest_email', 'like', '%'.$needle.'%')
                        ->orWhere('guest_phone', 'like', '%'.$needle.'%');
                });
            }
            if ($this->statusFilter !== '') {
                $q->where('booking_status', $this->statusFilter);
            }
            return $q->with('room:id,room_number')->paginate(20);
        });
    }

    /**
     * @return LengthAwarePaginator<int, PosSale>
     */
    private function paginatePos(Tenant $tenant): LengthAwarePaginator
    {
        return $tenant->execute(function (): LengthAwarePaginator {
            $q = PosSale::query()->orderBy('id', 'desc');
            $needle = trim($this->search);
            if ($needle !== '') {
                $q->where(function ($x) use ($needle): void {
                    $x->where('reference', 'like', '%'.$needle.'%')
                        ->orWhere('notes', 'like', '%'.$needle.'%')
                        ->orWhereHas('outlet', fn ($o) => $o->where('name', 'like', '%'.$needle.'%'))
                        ->orWhereHas('operator', fn ($o) => $o->where('name', 'like', '%'.$needle.'%'));
                });
            }
            if ($this->statusFilter !== '') {
                $q->where('status', $this->statusFilter);
            }
            return $q->with(['outlet:id,name', 'operator:id,name'])->paginate(20);
        });
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function slugMatches(Tenant $tenant): bool
    {
        if (trim($this->confirmSlug) !== $tenant->slug) {
            $this->dispatch('notify',
                title: 'Slug didn\'t match',
                message: 'Type the tenant slug exactly to confirm.',
                kind: 'err',
            );
            return false;
        }
        return true;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId);
    }

    private function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = auth('admin')->user();
        return $admin;
    }

    private function guardSuper(): void
    {
        if (auth('admin')->user()?->role !== 'super') {
            abort(403);
        }
    }
}
