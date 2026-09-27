<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Membership;

use App\Models\MembershipBusiness;
use App\Models\MembershipPlan;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Plans index. Scoped by an optional business filter (URL-bound so
 * the operator can bookmark "just the Gym plans"). Same inline-form
 * pattern as Businesses.
 */
#[Layout('layouts.tenant-auth')]
class Plans extends Component
{
    #[Url(as: 'biz', except: '')]
    public string $businessFilter = '';

    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public ?int $businessId = null;

    public string $name = '';

    public string $description = '';

    public ?int $price = null;

    public ?int $validityDays = null;

    public bool $active = true;

    public function newPlan(): void
    {
        $this->reset(['editingId', 'name', 'description', 'price', 'validityDays']);
        $this->businessId = $this->businessFilter !== ''
            ? (int) $this->businessFilter
            : (MembershipBusiness::query()->where('active', true)->orderBy('name')->value('id'));
        $this->active = true;
        $this->formOpen = true;
    }

    public function editPlan(int $id): void
    {
        $plan = MembershipPlan::query()->find($id);
        if ($plan === null) {
            return;
        }
        $this->editingId = $plan->id;
        $this->businessId = $plan->business_id;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->price = $plan->price;
        $this->validityDays = $plan->validity_days;
        $this->active = $plan->active;
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->reset(['editingId', 'name', 'description', 'price', 'validityDays']);
        $this->businessId = null;
        $this->active = true;
    }

    public function save(): void
    {
        try {
            if ($this->businessId === null) {
                throw new RuntimeException('Pick a business first.');
            }
            $name = trim($this->name);
            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($this->price === null || $this->price <= 0) {
                throw new RuntimeException('Price must be greater than ₦0.');
            }
            if ($this->validityDays === null || $this->validityDays <= 0) {
                throw new RuntimeException('Validity (days) must be greater than 0.');
            }
            $description = trim($this->description);

            if ($this->editingId !== null) {
                $plan = MembershipPlan::query()->findOrFail($this->editingId);
                $plan->forceFill([
                    'business_id'   => $this->businessId,
                    'name'          => $name,
                    'description'   => $description !== '' ? $description : null,
                    'price'         => (int) $this->price,
                    'validity_days' => (int) $this->validityDays,
                    'active'        => $this->active,
                ])->save();
            } else {
                MembershipPlan::query()->create([
                    'business_id'   => $this->businessId,
                    'name'          => $name,
                    'description'   => $description !== '' ? $description : null,
                    'price'         => (int) $this->price,
                    'validity_days' => (int) $this->validityDays,
                    'active'        => $this->active,
                ]);
            }
            $this->closeForm();
            $this->dispatch('notify',
                title: 'Saved',
                message: 'Plan '.($this->editingId !== null ? 'updated' : 'created').'.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    public function toggleActive(int $id): void
    {
        $plan = MembershipPlan::query()->find($id);
        if ($plan === null) {
            return;
        }
        $plan->active = ! $plan->active;
        $plan->save();
    }

    public function render(): View
    {
        $query = MembershipPlan::query()->with('business');
        if ($this->businessFilter !== '') {
            $query->where('business_id', (int) $this->businessFilter);
        }
        $plans = $query
            ->withCount(['subscriptions as active_subscriptions_count' => function ($q): void {
                $q->where('status', 'active');
            }])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $businesses = MembershipBusiness::query()
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard.membership.plans', [
            'plans'      => $plans,
            'businesses' => $businesses,
        ]);
    }
}
