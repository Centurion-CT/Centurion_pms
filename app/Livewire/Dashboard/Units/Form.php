<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Units;

use App\Enums\UnitStatus;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $unitId = null;

    public string $name = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public int $bedrooms = 1;

    public int $bathrooms = 1;

    public ?int $size_sqm = null;

    public int $annual_rent_naira = 0;

    public ?int $monthly_rent_naira = null;

    public int $deposit_naira = 0;

    public int $service_charge_annual_naira = 0;

    public string $description = '';

    public string $status = 'available';

    public function mount(?Unit $unit = null): void
    {
        if ($unit !== null && $unit->exists) {
            $this->unitId = $unit->id;
            $this->name = $unit->name;
            $this->address = $unit->address;
            $this->city = (string) $unit->city;
            $this->state = (string) $unit->state;
            $this->bedrooms = $unit->bedrooms;
            $this->bathrooms = $unit->bathrooms;
            $this->size_sqm = $unit->size_sqm;
            $this->annual_rent_naira = $unit->annual_rent_naira;
            $this->monthly_rent_naira = $unit->monthly_rent_naira;
            $this->deposit_naira = $unit->deposit_naira;
            $this->service_charge_annual_naira = $unit->service_charge_annual_naira;
            $this->description = (string) $unit->description;
            $this->status = $unit->status->value;
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'                        => ['required', 'string', 'max:160'],
            'address'                     => ['required', 'string', 'max:240'],
            'city'                        => ['nullable', 'string', 'max:64'],
            'state'                       => ['nullable', 'string', 'max:64'],
            'bedrooms'                    => ['required', 'integer', 'min:0', 'max:20'],
            'bathrooms'                   => ['required', 'integer', 'min:0', 'max:20'],
            'size_sqm'                    => ['nullable', 'integer', 'min:0'],
            'annual_rent_naira'           => ['required', 'integer', 'min:0'],
            'monthly_rent_naira'          => ['nullable', 'integer', 'min:0'],
            'deposit_naira'               => ['nullable', 'integer', 'min:0'],
            'service_charge_annual_naira' => ['nullable', 'integer', 'min:0'],
            'description'                 => ['nullable', 'string', 'max:8000'],
            'status'                      => ['required', Rule::in(array_map(fn ($c) => $c->value, UnitStatus::cases()))],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $property = $tenant?->property;
        if ($property === null) {
            throw new NotFoundHttpException('Finish onboarding first.');
        }

        $unit = $this->unitId !== null
            ? Unit::query()->findOrFail($this->unitId)
            : new Unit;

        if (! $unit->exists) {
            $unit->property_id = $property->id;
            $unit->slug = $this->uniqueSlug($data['name'], $tenant->id);
        }

        $unit->fill($data)->save();

        session()->flash('status', $this->unitId ? 'Unit updated.' : 'Unit added.');
        $this->redirectRoute('tenant.units.index', navigate: true);
    }

    private function uniqueSlug(string $name, int $tenantId): string
    {
        $base = Str::slug($name) ?: 'unit';
        $slug = $base;
        $i = 1;
        while (Unit::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    public function render(): View
    {
        return view('livewire.dashboard.units.form', [
            'statuses' => UnitStatus::cases(),
            'isEdit'   => $this->unitId !== null,
        ]);
    }
}
