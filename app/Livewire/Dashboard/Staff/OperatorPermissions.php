<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Staff;

use App\Enums\OperatorRole;
use App\Enums\Permission;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dedicated page for managing an operator's permission grants.
 * Reached from the Staff roster's "Permissions" link. Route:
 *   GET /dashboard/staff/{operator}/permissions
 *
 * Guards:
 *   - manager-only (checked here on top of the `role:staff`
 *     middleware, because staff module access alone doesn't imply
 *     the right to grant permissions);
 *   - operator must belong to the current tenant (implicit-binding
 *     runs against the global scope by default, but this component
 *     bypasses that scope so a hand-crafted URL with a foreign
 *     operator id fails cleanly with a redirect + flash instead of
 *     silently 404ing).
 *
 * On save: whitelisted against Permission::values(), written to
 * operators.permissions (nullable JSON, null when empty), redirects
 * back to the staff index with a status flash.
 */
#[Layout('layouts.tenant-auth')]
class OperatorPermissions extends Component
{
    public int $operatorId;

    public string $operatorName = '';

    public string $operatorEmail = '';

    public string $operatorRoleLabel = '';

    public string $statusLabel = '';

    public string $statusClass = '';

    /**
     * Selected permission keys (subset of Permission::values()).
     * Bound to the checkboxes; `->save()` cleans + persists.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /**
     * Property ids the operator is granted access to. Empty array
     * carries a special meaning — "no restriction, all branches"
     * (backwards-compat default so operators pre-multi-branch don't
     * suddenly lose access). Once ANY branch is ticked, the operator
     * becomes explicitly restricted to that set.
     *
     * Manager-role operators are always all-access at the model level
     * regardless of what's saved here; the UI hides the section for
     * them accordingly.
     *
     * @var array<int, int>
     */
    public array $selectedBranchIds = [];

    public function mount(int $operator): void
    {
        $current = auth('web')->user();
        if ($current === null) {
            $this->redirect(route('tenant.staff.index'), navigate: true);

            return;
        }
        if (($current->role ?? OperatorRole::Manager) !== OperatorRole::Manager) {
            session()->flash('error', 'Only managers can manage permissions.');
            $this->redirect(route('tenant.staff.index'), navigate: true);

            return;
        }

        $tenant = Tenant::current();
        /** @var Operator|null $op */
        $op = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->where('id', $operator)
            ->first();

        if ($op === null) {
            session()->flash('error', 'Operator not found.');
            $this->redirect(route('tenant.staff.index'), navigate: true);

            return;
        }

        $this->operatorId = $op->id;
        $this->operatorName = $op->name;
        $this->operatorEmail = $op->email;
        $this->operatorRoleLabel = ($op->role ?? OperatorRole::Manager)->label();
        [$this->statusLabel, $this->statusClass] = $this->statusOf($op);
        $this->selected = array_values(array_intersect(
            (array) ($op->permissions ?? []),
            Permission::values(),
        ));
        $this->selectedBranchIds = $op->branches()->pluck('properties.id')->map(fn ($v) => (int) $v)->all();
    }

    public function save(): void
    {
        $current = auth('web')->user();
        if ($current === null
            || ($current->role ?? OperatorRole::Manager) !== OperatorRole::Manager) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: 'Only managers can manage permissions.', kind: 'err');

            return;
        }

        $tenant = Tenant::current();
        /** @var Operator|null $op */
        $op = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant?->id)
            ->where('id', $this->operatorId)
            ->first();

        if ($op === null) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: 'Operator not found.', kind: 'err');

            return;
        }

        $clean = array_values(array_intersect($this->selected, Permission::values()));
        $op->forceFill(['permissions' => $clean !== [] ? $clean : null])->save();

        // Persist branch access — only accept property_ids that
        // belong to this tenant (an admin can't grant access to
        // another org's property by tampering with checkbox values).
        $validBranchIds = \App\Models\Property::query()
            ->where('tenant_id', $tenant?->id)
            ->whereIn('id', array_map('intval', $this->selectedBranchIds))
            ->pluck('id')->all();
        $op->branches()->sync($validBranchIds);

        // If the operator was working in a branch they can no longer
        // access, snap their `current_property_id` back to the first
        // accessible one so the next dashboard load doesn't strand
        // them on an empty page.
        if ($op->current_property_id !== null && $validBranchIds !== []
            && ! in_array((int) $op->current_property_id, $validBranchIds, true)) {
            $op->forceFill(['current_property_id' => $validBranchIds[0]])->save();
        }

        session()->flash('status', 'Permissions saved for '.$op->name.'.');
        $this->redirect(route('tenant.staff.index'), navigate: true);
    }

    public function render(): View
    {
        $tenant = Tenant::current();
        $branches = $tenant !== null
            ? $tenant->properties()->orderBy('id')->get()
            : collect();

        // Managers bypass the branch-access check entirely (see
        // Operator::hasAccessToBranch), so hide the section for
        // them — it would just be a confusing no-op UI.
        $isManagerBeingEdited = false;
        $tenantOp = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('id', $this->operatorId)
            ->first();
        if ($tenantOp !== null) {
            $isManagerBeingEdited = ($tenantOp->role ?? null) === OperatorRole::Manager;
        }

        return view('livewire.dashboard.staff.operator-permissions', [
            'permissions' => Permission::cases(),
            'branches' => $branches,
            'isManagerBeingEdited' => $isManagerBeingEdited,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function statusOf(Operator $op): array
    {
        return match (true) {
            $op->isRevoked()           => ['Revoked',   'lp-tag--cancelled'],
            $op->isSuspended()         => ['Suspended', 'lp-tag--on-hold'],
            $op->activated_at === null => ['Pending',   'lp-tag--pending'],
            default                    => ['Active',    'lp-tag--confirmed'],
        };
    }
}
