<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Packages;

use App\Models\Package;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    public ?int $packageId = null;

    public string $name = '';
    public string $description = '';

    public bool $is_complimentary = false;
    public ?int $price = null;

    public int $max_per_booking = 1;
    public bool $available_during_booking = true;
    public bool $available_after_booking = true;
    public bool $active = true;

    public function mount(?Package $package = null): void
    {
        if ($package && $package->exists) {
            $this->packageId = $package->id;
            $this->name = $package->name;
            $this->description = (string) $package->description;
            $this->is_complimentary = (bool) $package->is_complimentary;
            $this->price = (int) $package->price;
            $this->max_per_booking = (int) $package->max_per_booking;
            $this->available_during_booking = (bool) $package->available_during_booking;
            $this->available_after_booking = (bool) $package->available_after_booking;
            $this->active = (bool) $package->active;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name'                     => ['required', 'string', 'max:120'],
            'description'              => ['nullable', 'string', 'max:2000'],
            'is_complimentary'         => ['boolean'],
            'price'                    => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_per_booking'          => ['required', 'integer', 'min:1', 'max:50'],
            'available_during_booking' => ['boolean'],
            'available_after_booking'  => ['boolean'],
            'active'                   => ['boolean'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $package = $this->packageId
            ? Package::query()->findOrFail($this->packageId)
            : new Package;

        $package->fill([
            'name'                     => $data['name'],
            'description'              => $data['description'] !== '' ? $data['description'] : null,
            'is_complimentary'         => (bool) $data['is_complimentary'],
            'price'                    => $data['is_complimentary'] ? 0 : (int) ($data['price'] ?? 0),
            'max_per_booking'          => (int) $data['max_per_booking'],
            'available_during_booking' => (bool) $data['available_during_booking'],
            'available_after_booking'  => (bool) $data['available_after_booking'],
            'active'                   => (bool) $data['active'],
        ]);

        if (! $package->exists) {
            $package->created_by_operator_id = (int) auth('web')->id();
        }
        $package->save();

        session()->flash('status', $this->packageId ? 'Package updated.' : 'Package created.');

        $this->redirectRoute('tenant.packages.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.packages.form');
    }
}
