<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Step 1/3 of the onboarding wizard (PRD §6.1.1). Collects property name +
 * type + full address + description. The tenant's name (captured at
 * registration) seeds this form for friction-free completion, but the
 * operator can edit it.
 */
#[Layout('layouts.tenant-auth')]
class PropertyBasics extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    public string $type = '';

    #[Validate('required|string|max:255')]
    public string $address = '';

    #[Validate('nullable|string|max:80')]
    public string $city = '';

    #[Validate('nullable|string|max:80')]
    public string $state = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    /**
     * ISO 4217 currency this branch renders amounts in. NGN default
     * (Keverna's home market); operators outside Nigeria pick their
     * local currency here at setup, or change it later from Settings.
     */
    public string $currencyCode = 'NGN';

    /**
     * How the operator rents inventory — picked first because it
     * changes the shape of the whole dashboard:
     *   'multi_room'  = one address, many rooms per category (hotel,
     *                   apartment complex, guest house). Existing UX.
     *   'single_unit' = the property IS the listing (standalone
     *                   condo / apartment / bungalow). Adds one on
     *                   creation; the operator adds more via the
     *                   Portfolio page after onboarding.
     */
    public string $unitMode = 'multi_room';

    /**
     * @return array<string,array<int,string|\Illuminate\Validation\Rules\In>>
     */
    protected function rules(): array
    {
        // PropertyType is the source of truth, gating module visibility
        // depends on the type being one of the enum cases. Keeping the
        // allowed list in PHP (instead of a string literal in the
        // Validate attribute) means new property types automatically
        // become valid onboarding choices.
        return [
            'unitMode' => ['required', Rule::in(['multi_room', 'single_unit'])],
            'type' => ['required', Rule::in(array_map(fn ($c) => $c->value, PropertyType::cases()))],
            'currencyCode' => ['required', Rule::in(array_map(fn ($c) => $c->value, \App\Enums\Currency::cases()))],
        ];
    }

    public function mount(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $this->name = $tenant->name;

        // If the property already exists (e.g. operator refreshed), prefill from it.
        $property = $tenant->property()->first();
        if ($property !== null) {
            $this->name = $property->name;
            $this->type = $property->type->value;
            $this->address = $property->address;
            $this->city = (string) $property->city;
            $this->state = (string) $property->state;
            $this->description = (string) $property->description;
            $this->currencyCode = $property->currency->value;
            $this->unitMode = in_array($property->unit_mode, ['multi_room', 'single_unit'], true)
                ? $property->unit_mode
                : 'multi_room';
        }
    }

    public function save(): void
    {
        $data = $this->validate();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        // 1 property per tenant in v1, so updateOrCreate keyed on tenant_id.
        Property::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'name' => $data['name'],
                'type' => PropertyType::from($data['type']),
                'address' => $data['address'],
                'city' => $data['city'] !== '' ? $data['city'] : null,
                'state' => $data['state'] !== '' ? $data['state'] : null,
                'description' => $data['description'] !== '' ? $data['description'] : null,
                'currency_code' => $data['currencyCode'],
                'unit_mode' => $data['unitMode'],
            ],
        );

        $this->redirectRoute('tenant.onboarding.email');
    }

    public function render(): View
    {
        return view('livewire.onboarding.property-basics', [
            'types' => PropertyType::cases(),
            'currenciesByRegion' => \App\Enums\Currency::byRegion(),
        ]);
    }
}
