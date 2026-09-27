<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\WorkspacePlans;

use App\Enums\BillingInterval;
use App\Models\Workspace;
use App\Models\WorkspacePlan;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $planId = null;

    public string $name = '';

    public string $billing_interval = 'month';

    public int $price_naira = 0;

    public ?int $workspace_id = null;

    public string $description = '';

    public bool $is_active = true;

    public ?int $member_cap = null;

    public function mount(?WorkspacePlan $workspacePlan = null): void
    {
        if ($workspacePlan !== null && $workspacePlan->exists) {
            $this->planId = $workspacePlan->id;
            $this->name = $workspacePlan->name;
            $this->billing_interval = $workspacePlan->billing_interval->value;
            $this->price_naira = $workspacePlan->price_naira;
            $this->workspace_id = $workspacePlan->workspace_id;
            $this->description = (string) $workspacePlan->description;
            $this->is_active = $workspacePlan->is_active;
            $this->member_cap = $workspacePlan->member_cap;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:120'],
            'billing_interval' => ['required', Rule::in(array_map(fn ($c) => $c->value, BillingInterval::cases()))],
            'price_naira'      => ['required', 'integer', 'min:0'],
            'workspace_id'     => ['nullable', 'integer', 'exists:workspaces,id'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'is_active'        => ['boolean'],
            'member_cap'       => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $plan = $this->planId !== null
            ? WorkspacePlan::query()->findOrFail($this->planId)
            : new WorkspacePlan;

        $plan->fill($data)->save();

        session()->flash('status', $this->planId ? 'Plan updated.' : 'Plan added.');
        $this->redirectRoute('tenant.workspace-plans.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.workspace-plans.form', [
            'intervals'  => BillingInterval::cases(),
            'workspaces' => Workspace::query()->where('is_active', true)->orderBy('name')->get(),
            'isEdit'     => $this->planId !== null,
        ]);
    }
}
