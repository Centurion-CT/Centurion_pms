<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Branches;

use App\Enums\PropertyType;
use App\Enums\StockModel;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manage-branches page — list every property under the org + a
 * single-action inline "Add a branch" form. Full per-branch editing
 * lives on the existing Property settings screen; this page is the
 * jumping-off point when the operator wants to create a new location.
 *
 * Adding a branch is intentionally minimal: name + type + address
 * are the operational minimum. Everything else (branding, house
 * rules, checkout policy, payouts) is edited on the settings page
 * for that branch after creation — same UX as the first property
 * on onboarding.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public string $newName = '';
    public string $newType = 'hotel';
    public string $newAddress = '';
    public string $newCity = '';
    public string $newState = '';

    public function create(PropertyContext $context): void
    {
        $data = $this->validate([
            'newName' => ['required', 'string', 'max:120'],
            'newType' => ['required', Rule::in(array_map(fn ($c) => $c->value, PropertyType::cases()))],
            'newAddress' => ['required', 'string', 'max:240'],
            'newCity' => ['nullable', 'string', 'max:120'],
            'newState' => ['nullable', 'string', 'max:120'],
        ]);

        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }

        $branch = Property::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => $data['newName'],
            'type' => $data['newType'],
            'stock_model' => StockModel::StoreAndRequisition->value,
            'address' => $data['newAddress'],
            'city' => $data['newCity'] !== '' ? $data['newCity'] : null,
            'state' => $data['newState'] !== '' ? $data['newState'] : null,
            // New branches default to unpublished so the operator can
            // finish setting up rooms / photos / payouts before their
            // guests see it on the public switcher.
            'is_live' => false,
        ]);

        // Snap the operator's context to the new branch so their next
        // action (adding rooms, uploading photos) happens against the
        // branch they just created without an extra click.
        $context->switchTo($branch);

        $this->reset(['newName', 'newAddress', 'newCity', 'newState']);
        $this->dispatch('notify',
            title: 'Branch created',
            message: "You're now working in {$branch->name}. Head to Settings to add photos, house rules, and payouts.",
            kind: 'ok',
        );
    }

    public function render(PropertyContext $context): View
    {
        return view('livewire.dashboard.branches.index', [
            'branches' => $context->all(),
            'current' => $context->current(),
            'propertyTypes' => PropertyType::cases(),
        ]);
    }
}
