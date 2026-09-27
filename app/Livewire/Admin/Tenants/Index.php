<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Tenants;

use App\Enums\TenantStatus;
use App\Models\Admin;
use App\Models\Tenant;
use App\Services\Admin\DangerousTenantDelete;
use App\Services\Auth\TotpService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cross-tenant list of every tenant on the platform (PRD §18). Admins are
 * NOT bound to any tenant context, so the default Tenant query has no
 * scope to lift, every row is visible by design.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    /**
     * Row selection state for the bulk-destroy action. Keyed as
     * `['<tenant_id>' => true]` so wire:model on the row checkbox
     * works naturally with Livewire's boolean coercion.
     *
     * @var array<int|string, bool>
     */
    public array $selected = [];

    /** Modal state for the "delete selected" confirmation panel. */
    public bool $bulkConfirmOpen = false;
    public string $bulkPhrase = '';
    public string $bulkReason = '';
    public string $bulkTotpCode = '';

    /** Hard cap - even a super-admin doesn't bulk-nuke more than 10
     *  tenants at once. The muscle memory that would let you type
     *  DELETE 30 TENANTS is the muscle memory we're trying to slow
     *  down here. */
    public const BULK_MAX = 10;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function selectedIds(): array
    {
        return array_map('intval', array_keys(array_filter($this->selected)));
    }

    public function openBulkConfirm(): void
    {
        $ids = $this->selectedIds();
        if ($ids === []) return;
        if (count($ids) > self::BULK_MAX) {
            throw ValidationException::withMessages([
                'bulkPhrase' => 'Batch is capped at '.self::BULK_MAX.' tenants at a time.',
            ]);
        }
        $this->bulkPhrase = '';
        $this->bulkReason = '';
        $this->bulkTotpCode = '';
        $this->bulkConfirmOpen = true;
    }

    public function closeBulkConfirm(): void
    {
        $this->bulkConfirmOpen = false;
    }

    public function bulkDestroy(DangerousTenantDelete $service, TotpService $totp): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || $admin->role !== 'super') {
            abort(403);
        }
        if (! $admin->hasTwoFactor()) {
            $this->redirect(route('admin.two-factor.enroll'));
            return;
        }

        $ids = $this->selectedIds();
        $count = count($ids);
        $expectedPhrase = 'DELETE '.$count.' TENANT'.($count === 1 ? '' : 'S');

        $errors = [];
        if ($count === 0) {
            $errors['bulkPhrase'] = 'Nothing selected.';
        } elseif ($count > self::BULK_MAX) {
            $errors['bulkPhrase'] = 'Batch is capped at '.self::BULK_MAX.'.';
        }
        if (trim($this->bulkPhrase) !== $expectedPhrase) {
            $errors['bulkPhrase'] = 'Type exactly: '.$expectedPhrase;
        }
        if (trim($this->bulkReason) === '') {
            $errors['bulkReason'] = 'Reason is required for the audit trail.';
        }
        if (! $totp->verify((string) $admin->two_factor_secret, $this->bulkTotpCode)) {
            $errors['bulkTotpCode'] = 'That 2FA code is wrong.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Per-tenant deletion so a mid-batch failure on a corrupt
        // tenant doesn't block the rest. Each call runs in its own
        // transaction (see DangerousTenantDelete).
        $wiped = 0;
        $failed = [];
        foreach (Tenant::query()->whereIn('id', $ids)->get() as $tenant) {
            try {
                $wiped += $service->handle($tenant, $admin, trim($this->bulkReason));
            } catch (\Throwable $e) {
                $failed[] = $tenant->slug.': '.$e->getMessage();
            }
        }

        activity('admin')
            ->causedBy($admin)
            ->event('tenant_bulk_deleted')
            ->withProperties([
                'requested' => $count,
                'succeeded' => $count - count($failed),
                'failed'    => $failed,
                'rows_wiped'=> $wiped,
                'reason'    => trim($this->bulkReason),
            ])
            ->log(sprintf(
                'Super-admin %s bulk-deleted %d tenant%s (%d rows wiped). Reason: %s',
                $admin->email, $count - count($failed),
                $count === 1 ? '' : 's', $wiped, trim($this->bulkReason),
            ));

        $this->selected = [];
        $this->bulkConfirmOpen = false;
        $msg = ($count - count($failed)).' tenant'.($count === 1 ? '' : 's').' deleted.';
        if ($failed !== []) $msg .= ' '.count($failed).' failed - see audit log.';
        session()->flash('flash', $msg);
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.tenants.index', [
            'tenants' => $this->paginate(),
            'statusOptions' => TenantStatus::cases(),
            'bulkMax' => self::BULK_MAX,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Tenant>
     */
    private function paginate(): LengthAwarePaginator
    {
        $query = Tenant::query()->orderBy('id', 'desc');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('slug', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%');
            });
        }

        if ($this->statusFilter !== '' && TenantStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }

        return $query->paginate(25);
    }
}
