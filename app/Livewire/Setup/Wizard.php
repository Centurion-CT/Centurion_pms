<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Setup\SeedInstallation;
use App\Enums\PropertyType;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * One-time install wizard.
 *
 * Fires exactly once per install, when the DB has no Tenant row yet.
 * Bootstrap's NoCurrentTenant exception handler 302s any tenant-scoped
 * request to /setup, and this component is what /setup mounts.
 *
 * Submitting the form calls {@see SeedInstallation}, which atomically
 * creates the singleton Tenant + first Property + first Manager
 * Operator inside a DB transaction. On success the operator is logged
 * in and redirected to the dashboard.
 *
 * The whole thing is deliberately single-page (no multi-step wizard
 * UI) — a solo install has a handful of required fields and adding
 * step navigation would just get in the way.
 */
#[Layout('layouts.tenant-public')]
class Wizard extends Component
{
    // Hotel-level fields
    public string $hotelName = '';

    // First property
    public string $propertyName = '';

    public string $propertyType = 'hotel';

    public string $propertyAddress = '';

    public string $propertyCity = '';

    public string $propertyState = '';

    // First Manager operator
    public string $managerName = '';

    public string $managerEmail = '';

    public string $managerPassword = '';

    public string $managerPasswordConfirmation = '';

    // Set by handle() when submission fails so the view can show a
    // top-level error banner. Field-level errors go through the
    // normal Livewire validation channel.
    public ?string $submitError = null;

    public function mount(): void
    {
        // Already installed → nothing to do here.
        if (Tenant::query()->exists()) {
            abort(404);
        }
    }

    protected function rules(): array
    {
        return [
            'hotelName'         => ['required', 'string', 'min:2', 'max:120'],
            'propertyName'      => ['required', 'string', 'min:2', 'max:120'],
            'propertyType'      => ['required', Rule::in(array_map(fn ($c) => $c->value, PropertyType::cases()))],
            'propertyAddress'   => ['required', 'string', 'min:4', 'max:240'],
            'propertyCity'      => ['nullable', 'string', 'max:80'],
            'propertyState'     => ['nullable', 'string', 'max:80'],
            'managerName'       => ['required', 'string', 'min:2', 'max:120'],
            'managerEmail'      => ['required', 'email:rfc', 'max:180'],
            // Laravel's `confirmed` rule keys off a snake_case
            // `{field}_confirmation` sibling; the wizard uses camelCase
            // property names so we match against the sibling by name
            // instead.
            'managerPassword'   => ['required', 'string', 'min:10', 'max:200', 'same:managerPasswordConfirmation'],
        ];
    }

    protected function messages(): array
    {
        return [
            'managerPassword.min'  => 'Use at least 10 characters — this is the master account for the property.',
            'managerPassword.same' => 'The two password fields don\'t match.',
        ];
    }

    public function submit(SeedInstallation $action): mixed
    {
        $this->submitError = null;
        $this->validate();

        try {
            $operator = $action->handle([
                'hotel_name'        => $this->hotelName,
                'property_name'     => $this->propertyName,
                'property_type'     => $this->propertyType,
                'property_address'  => $this->propertyAddress,
                'property_city'     => $this->propertyCity,
                'property_state'    => $this->propertyState,
                'manager_name'      => $this->managerName,
                'manager_email'     => $this->managerEmail,
                'manager_password'  => $this->managerPassword,
            ]);
        } catch (Throwable $e) {
            $this->submitError = $e->getMessage();
            return null;
        }

        Auth::guard('web')->login($operator);

        return $this->redirect(route('tenant.dashboard'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.setup.wizard', [
            'propertyTypeOptions' => collect(PropertyType::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => ucfirst(str_replace('_', ' ', $c->value)),
            ])->all(),
        ]);
    }
}
