<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create / edit an outlet. Manager-only. Also handles assigning
 * PosStaff operators to this outlet, that assignment is what gates
 * POS terminal access.
 */
#[Layout('layouts.tenant-auth')]
class OutletForm extends Component
{
    public ?int $outletId = null;

    public string $name = '';

    public string $type = 'bar';

    public bool $active = true;

    public string $tax_rate = '0';

    public string $notes = '';

    /** @var list<int> */
    public array $assignedOperatorIds = [];

    public function mount(?Outlet $outlet = null): void
    {
        if ($outlet && $outlet->exists) {
            $this->outletId = $outlet->id;
            $this->name = $outlet->name;
            $this->type = $outlet->type->value;
            $this->active = $outlet->active;
            $this->tax_rate = (string) $outlet->tax_rate;
            $this->notes = (string) $outlet->notes;
            $this->assignedOperatorIds = array_values(array_map(
                intval(...),
                $outlet->operators()->pluck('operators.id')->all(),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_map(fn ($c) => $c->value, OutletType::cases()))],
            'active' => ['boolean'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'assignedOperatorIds' => ['array'],
            'assignedOperatorIds.*' => ['integer', 'exists:operators,id'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $tenant = Tenant::current();
        $propertyId = $tenant?->property?->id;
        if ($propertyId === null) {
            session()->flash('error', 'Set up your property before creating outlets.');

            return;
        }

        $isNew = $this->outletId === null;
        $canManagePrices = $this->currentRole()->canManagePrices();

        // role.prices middleware already gates the create route; this is
        // defence-in-depth against a deep-linked wire:call.
        if ($isNew && ! $canManagePrices) {
            throw new AuthorizationException('Only managers can create outlets.');
        }

        $outlet = $isNew ? new Outlet : Outlet::query()->findOrFail($this->outletId);

        $fillData = [
            'tenant_id' => $tenant->id,
            'property_id' => $propertyId,
            'name' => $data['name'],
            'type' => $data['type'],
            'active' => (bool) $data['active'],
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
        ];
        // Clerks can rename or archive an outlet, but the tax rate stays
        // untouched, omitting the key preserves the DB value.
        if ($canManagePrices) {
            $fillData['tax_rate'] = $data['tax_rate'];
        }
        $outlet->fill($fillData);
        $outlet->save();

        $outlet->operators()->sync($data['assignedOperatorIds']);

        session()->flash('status', $this->outletId ? 'Outlet updated.' : 'Outlet created.');
        $this->redirectRoute('tenant.inventory.outlets.show', ['outlet' => $outlet->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.inventory.outlet-form', [
            'outletTypes' => OutletType::cases(),
            // PosStaff operators are the eligible pool for assignment.
            // Managers see every outlet by default and don't need to be
            // explicitly assigned here.
            'posStaffOperators' => Operator::query()
                ->where('role', OperatorRole::PosStaff->value)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'canManagePrices' => $this->currentRole()->canManagePrices(),
        ]);
    }

    private function currentRole(): OperatorRole
    {
        $op = auth('web')->user();

        return ($op instanceof Operator ? $op->role : null) ?? OperatorRole::Manager;
    }
}
