<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Membership;

use App\Models\MembershipBusiness;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Businesses index. Inline create + edit (single form panel that
 * flips between "New business" + "Edit N") keeps the operator on
 * one page — matches how they think about sub-services (add gym,
 * rename it, tick inactive if the pool's shut for renos).
 */
#[Layout('layouts.tenant-auth')]
class Businesses extends Component
{
    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public ?int $propertyId = null;

    public bool $active = true;

    public function newBusiness(): void
    {
        $this->reset(['editingId', 'name', 'description', 'propertyId']);
        $this->active = true;
        $this->formOpen = true;
    }

    public function editBusiness(int $id): void
    {
        $business = MembershipBusiness::query()->find($id);
        if ($business === null) {
            return;
        }
        $this->editingId = $business->id;
        $this->name = $business->name;
        $this->description = (string) $business->description;
        $this->propertyId = $business->property_id;
        $this->active = $business->active;
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->reset(['editingId', 'name', 'description', 'propertyId']);
        $this->active = true;
    }

    public function save(): void
    {
        try {
            $name = trim($this->name);
            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }
            $description = trim($this->description);

            if ($this->editingId !== null) {
                $business = MembershipBusiness::query()->findOrFail($this->editingId);
                $business->forceFill([
                    'name'        => $name,
                    'description' => $description !== '' ? $description : null,
                    'property_id' => $this->propertyId,
                    'active'      => $this->active,
                ])->save();
            } else {
                MembershipBusiness::query()->create([
                    'name'        => $name,
                    'description' => $description !== '' ? $description : null,
                    'property_id' => $this->propertyId,
                    'active'      => $this->active,
                ]);
            }
            $this->closeForm();
            $this->dispatch('notify',
                title: 'Saved',
                message: 'Business '.($this->editingId !== null ? 'updated' : 'created').'.',
                kind: 'ok',
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
        }
    }

    public function toggleActive(int $id): void
    {
        $business = MembershipBusiness::query()->find($id);
        if ($business === null) {
            return;
        }
        $business->active = ! $business->active;
        $business->save();
    }

    public function render(): View
    {
        $now = Carbon::now();
        $businesses = MembershipBusiness::query()
            ->with('property')
            ->withCount([
                'plans as plan_count',
                'subscriptions as active_subscriptions_count' => function ($q) use ($now): void {
                    $q->where('status', 'active')->where('expires_at', '>', $now);
                },
            ])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $properties = Property::query()->orderBy('name')->get();

        return view('livewire.dashboard.membership.businesses', [
            'businesses' => $businesses,
            'properties' => $properties,
        ]);
    }
}
