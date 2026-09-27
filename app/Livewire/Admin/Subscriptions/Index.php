<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Subscriptions;

use App\Actions\Admin\ActivateSubscription;
use App\Actions\Admin\ExtendSubscription;
use App\Actions\Admin\RecordSubscriptionPayment;
use App\Actions\Admin\RenewSubscription;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Platform-wide subscription list (PRD §18 / §4).
 *
 * Lifts both the {@see TenantScope} on Subscription and the implicit
 * scope on Tenant so an admin sees every row, regardless of which
 * tenant created it. Companion to the Revenue page, Revenue is the
 * aggregate; this is the row-level operational view.
 *
 * Inline + 30d / + 90d extend buttons reuse {@see ExtendSubscription}
 * so the audit + tenant-status flip remain centralised.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'plan')]
    public string $planFilter = '';

    #[Url(as: 'expiring')]
    public string $expiringWindow = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedExpiringWindow(): void
    {
        $this->resetPage();
    }

    public function extend(int $subscriptionId, int $days, ExtendSubscription $action): void
    {
        if ($days < 1 || $days > 365) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Extension must be between 1 and 365 days.', kind: 'err');

            return;
        }

        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->find($subscriptionId);

        if ($subscription === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Subscription not found.', kind: 'err');

            return;
        }

        $tenant = Tenant::query()->find($subscription->tenant_id);
        if ($tenant === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Tenant not found.', kind: 'err');

            return;
        }

        /** @var Admin $admin */
        $admin = auth('admin')->user();

        try {
            $action->handle($tenant, $admin, $days);
            $this->dispatch('notify', title: 'Done', message: "Extended {$tenant->slug} by {$days} days.", kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'planFilter', 'expiringWindow']);
        $this->resetPage();
    }

    // ── Activate / Renew form state ───────────────────────────
    public bool $formOpen = false;
    public string $formMode = 'activate'; // 'activate' | 'renew'
    public ?int $formTenantId = null;
    public ?string $formTenantName = null;
    public string $formPlan = 'annual_300k';
    public int $formContract = 0;
    public int $formReceived = 0;
    public int $formDuration = 12;
    public string $formNotes = '';

    public function openActivate(int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) return;
        $this->reset(['formPlan','formContract','formReceived','formDuration','formNotes']);
        $this->formMode = 'activate';
        $this->formTenantId = $tenant->id;
        $this->formTenantName = $tenant->name;
        $this->formPlan = 'annual_300k';
        $this->formDuration = 12;
        $this->formOpen = true;
    }

    public function openRenew(int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) return;
        $latest = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->first();
        $this->reset(['formPlan','formContract','formReceived','formDuration','formNotes']);
        $this->formMode = 'renew';
        $this->formTenantId = $tenant->id;
        $this->formTenantName = $tenant->name;
        // Pre-fill from the latest sub so the admin isn't retyping.
        // Renewing off a trial is really a trial->paid conversion, so
        // default the plan to Annual instead of carrying the trial forward.
        $latestPlan = $latest?->plan?->value;
        $this->formPlan = ($latestPlan === null || $latestPlan === 'trial')
            ? SubscriptionPlan::Annual->value
            : $latestPlan;
        $this->formContract = (int) ($latest?->contract_amount ?? 0);
        $this->formDuration = (int) ($latest?->duration_months ?? 12);
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->reset(['formTenantId','formTenantName','formContract','formReceived','formNotes']);
    }

    // ── Record-payment form state ─────────────────────────────
    public bool $payOpen = false;
    public ?int $paySubscriptionId = null;
    public ?string $payTenantName = null;
    public int $payOutstanding = 0;
    public int $payAmount = 0;
    public string $payNotes = '';

    public function openPay(int $subscriptionId): void
    {
        $sub = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->find($subscriptionId);
        if ($sub === null || $sub->outstanding() === 0) {
            return;
        }
        $this->reset(['payAmount', 'payNotes']);
        $this->paySubscriptionId = $sub->id;
        $this->payTenantName = $sub->tenant?->name;
        $this->payOutstanding = $sub->outstanding();
        $this->payAmount = $sub->outstanding();
        $this->payOpen = true;
    }

    public function closePay(): void
    {
        $this->payOpen = false;
        $this->reset(['paySubscriptionId', 'payTenantName', 'payOutstanding', 'payAmount', 'payNotes']);
    }

    public function savePay(RecordSubscriptionPayment $record): void
    {
        $data = $this->validate([
            'paySubscriptionId' => ['required', 'integer', 'exists:subscriptions,id'],
            'payAmount'         => ['required', 'integer', 'min:1', 'max:'.$this->payOutstanding],
            'payNotes'          => ['nullable', 'string', 'max:1000'],
        ], [
            'payAmount.max' => 'Payment cannot exceed the ₦'.number_format($this->payOutstanding).' outstanding.',
        ]);

        $sub = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->findOrFail($data['paySubscriptionId']);

        /** @var Admin $admin */
        $admin = auth('admin')->user();

        try {
            $record->handle(
                subscription: $sub,
                admin: $admin,
                amount: (int) $data['payAmount'],
                notes: trim($data['payNotes']) !== '' ? trim($data['payNotes']) : null,
            );
            $this->closePay();
            $this->dispatch('notify', title: 'Payment recorded', message: 'Receipt queued to '.$sub->tenant->name.'.', kind: 'ok');
        } catch (\Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t record', message: $e->getMessage(), kind: 'err');
        }
    }

    public function saveForm(ActivateSubscription $activate, RenewSubscription $renew): void
    {
        $data = $this->validate([
            'formTenantId'  => ['required', 'integer', 'exists:tenants,id'],
            'formPlan'      => ['required', 'in:'.implode(',', array_map(fn ($p) => $p->value, SubscriptionPlan::cases()))],
            'formContract'  => ['required', 'integer', 'min:0'],
            'formReceived'  => ['required', 'integer', 'min:0'],
            'formDuration'  => ['required', 'integer', 'min:1', 'max:36'],
            'formNotes'     => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = Tenant::query()->findOrFail($data['formTenantId']);
        /** @var Admin $admin */
        $admin = auth('admin')->user();

        try {
            if ($this->formMode === 'renew') {
                $renew->handle(
                    tenant: $tenant, admin: $admin,
                    contractAmount: (int) $data['formContract'],
                    amountReceived: (int) $data['formReceived'],
                    durationMonths: (int) $data['formDuration'],
                    notes: trim($data['formNotes']) !== '' ? trim($data['formNotes']) : null,
                    plan: SubscriptionPlan::from($data['formPlan']),
                );
                $verb = 'Renewed';
            } else {
                $activate->handle(
                    tenant: $tenant, admin: $admin,
                    plan: SubscriptionPlan::from($data['formPlan']),
                    contractAmount: (int) $data['formContract'],
                    amountReceived: (int) $data['formReceived'],
                    durationMonths: (int) $data['formDuration'],
                    notes: trim($data['formNotes']) !== '' ? trim($data['formNotes']) : null,
                );
                $verb = 'Activated';
            }
            $this->closeForm();
            $this->dispatch('notify', title: 'Done', message: "{$verb} subscription for {$tenant->name}. Mails queued.", kind: 'ok');
        } catch (\Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(): View
    {
        return view('livewire.admin.subscriptions.index', [
            'rows'         => $this->paginate(),
            'stats'        => $this->stats(),
            'statusOptions' => SubscriptionStatus::cases(),
            'planOptions'   => SubscriptionPlan::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    private function paginate(): LengthAwarePaginator
    {
        $query = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['tenant'])
            ->orderByDesc('expires_at');

        $needle = trim($this->search);
        if ($needle !== '') {
            $query->whereHas('tenant', function ($q) use ($needle): void {
                $q->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('slug', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%');
            });
        }

        if ($this->statusFilter !== '' && SubscriptionStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->planFilter !== '' && SubscriptionPlan::tryFrom($this->planFilter) !== null) {
            $query->where('plan', $this->planFilter);
        }

        if ($this->expiringWindow !== '' && in_array($this->expiringWindow, ['7', '30', '90'], true)) {
            $days = (int) $this->expiringWindow;
            $query
                ->where('status', SubscriptionStatus::Active->value)
                ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays($days)->endOfDay()]);
        }

        return $query->paginate(20);
    }

    /**
     * Top-of-page counters.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $base = Subscription::query()->withoutGlobalScope(TenantScope::class);

        $live = (clone $base)
            ->whereIn('status', [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])
            ->count();

        $expiringSoon = (clone $base)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->count();

        $expired = (clone $base)
            ->where('status', SubscriptionStatus::Expired->value)
            ->count();

        $trial = (clone $base)
            ->where('status', SubscriptionStatus::Trial->value)
            ->count();

        // Roll the annual contract value of all live Annual subs into a
        // platform ARR figure. Trial sits at ₦0 by design.
        $arr = (int) (clone $base)
            ->whereIn('status', [SubscriptionStatus::Active->value])
            ->where('plan', SubscriptionPlan::Annual->value)
            ->sum('amount');

        return [
            'live'          => $live,
            'expiring_soon' => $expiringSoon,
            'expired'       => $expired,
            'trial'         => $trial,
            'arr_ngn'       => $arr,
        ];
    }
}
