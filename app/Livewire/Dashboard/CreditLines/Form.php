<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\CreditLines;

use App\Enums\CreditLineStatus;
use App\Enums\RoomStatus;
use App\Livewire\Concerns\HasCustomerLookup;
use App\Models\CreditLine;
use App\Models\Customer;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a credit line. The customer picker uses the shared
 * customer-lookup trait; rooms are multi-selected from the property.
 */
#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    use HasCustomerLookup;

    #[Locked]
    public ?int $creditLineId = null;

    public string $name = '';

    /** @var array<int, int> */
    public array $roomIds = [];

    public string $status = 'active';

    public ?int $spending_limit = null;

    public string $notes = '';

    public function mount(?CreditLine $creditLine = null): void
    {
        if ($creditLine && $creditLine->exists) {
            $this->creditLineId = $creditLine->id;
            $this->name = $creditLine->name;
            $this->status = $creditLine->status->value;
            $this->spending_limit = $creditLine->spending_limit;
            $this->notes = (string) ($creditLine->notes ?? '');
            $this->roomIds = $creditLine->rooms()->pluck('rooms.id')->all();

            // Seed the shared customer-lookup state.
            $this->selectedCustomerId = $creditLine->customer_id;
            $customer = Customer::query()->find($creditLine->customer_id);
            $this->customerSearch = $customer?->name ?? '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'selectedCustomerId' => ['required', 'integer', 'exists:customers,id'],
            'roomIds' => ['array'],
            'roomIds.*' => ['integer', 'exists:rooms,id'],
            'status' => ['required', Rule::in(array_map(fn ($c) => $c->value, CreditLineStatus::cases()))],
            'spending_limit' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'selectedCustomerId' => 'customer',
            'roomIds' => 'rooms',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $tenant = Tenant::current();

        $line = $this->creditLineId
            ? CreditLine::query()->findOrFail($this->creditLineId)
            : new CreditLine;
        $line->fill([
            'tenant_id' => $tenant?->id,
            'customer_id' => $data['selectedCustomerId'],
            'name' => $data['name'],
            'status' => $data['status'],
            'spending_limit' => $data['spending_limit'] !== null && $data['spending_limit'] > 0
                ? (int) $data['spending_limit']
                : null,
            'notes' => trim($data['notes']) !== '' ? trim($data['notes']) : null,
        ]);
        $line->save();
        $this->creditLineId = $line->id;

        // Sync rooms, replace the attached set entirely so
        // detach/re-attach edits commit in one shot.
        $line->rooms()->sync($data['roomIds'] ?? []);

        session()->flash('status', $this->wasRecentlyCreated($line) ? 'Credit line created.' : 'Credit line updated.');
        $this->redirectRoute('tenant.credit-lines.show', ['creditLine' => $line->id], navigate: true);
    }

    public function render(): View
    {
        $tenant = Tenant::current();
        $property = $tenant?->property;

        $rooms = $property === null
            ? collect()
            : $property->rooms()
                ->with('category:id,name')
                ->where('status', RoomStatus::Active->value)
                ->orderBy('room_number')
                ->get();

        return view('livewire.dashboard.credit-lines.form', [
            'rooms' => $rooms,
            'statusOptions' => CreditLineStatus::cases(),
        ]);
    }

    private function wasRecentlyCreated(CreditLine $line): bool
    {
        return $line->created_at?->diffInSeconds() < 3;
    }
}
