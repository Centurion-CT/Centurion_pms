<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Customers;

use App\Enums\CustomerLifecycle;
use App\Models\Customer;
use App\Services\Customers\CustomerLinker;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Add or edit a CRM customer record (operator-driven, not bound to a
 * specific booking). Useful for prospects who phoned in but haven't
 * booked yet, or pre-existing customer relationships imported from
 * elsewhere.
 *
 * Email uniqueness is enforced per tenant; a clash redirects to the
 * existing record instead of creating a duplicate.
 */
#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    public ?int $customerId = null;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $city = '';
    public string $state = '';
    public string $country = '';
    public string $notes = '';

    /** @var array<int, string> */
    public array $tags = [];
    public string $newTag = '';

    public string $lifecycle = 'prospect';
    public bool $is_vip = false;
    public bool $marketing_opt_in = false;

    public function mount(?Customer $customer = null): void
    {
        if ($customer && $customer->exists) {
            $this->customerId = $customer->id;
            $this->name = $customer->name;
            $this->email = (string) $customer->email;
            $this->phone = (string) $customer->phone;
            $this->address = (string) $customer->address;
            $this->city = (string) $customer->city;
            $this->state = (string) $customer->state;
            $this->country = (string) $customer->country;
            $this->notes = (string) $customer->notes;
            $this->tags = is_array($customer->tags) ? $customer->tags : [];
            $this->lifecycle = $customer->lifecycle->value;
            $this->is_vip = (bool) $customer->is_vip;
            $this->marketing_opt_in = (bool) $customer->marketing_opt_in;
        }
    }

    public function addTag(): void
    {
        $tag = trim($this->newTag);
        if ($tag === '') {
            return;
        }
        if (! in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
        $this->newTag = '';
    }

    public function removeTag(string $tag): void
    {
        $this->tags = array_values(array_filter($this->tags, fn (string $t): bool => $t !== $tag));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $tenantId = (int) \App\Models\Tenant::current()?->id;

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => [
                'nullable', 'email', 'max:200',
                Rule::unique('customers', 'email')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'))
                    ->ignore($this->customerId),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:240'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:8000'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:48'],
            'lifecycle' => ['required', Rule::in(array_map(fn ($c) => $c->value, CustomerLifecycle::cases()))],
            'is_vip' => ['boolean'],
            'marketing_opt_in' => ['boolean'],
        ];
    }

    public function save(CustomerLinker $linker): void
    {
        $data = $this->validate();

        $customer = $this->customerId
            ? Customer::query()->forCurrentProperty()->findOrFail($this->customerId)
            : new Customer;

        $customer->fill([
            'name' => $data['name'],
            'email' => $data['email'] !== '' ? mb_strtolower(trim($data['email'])) : null,
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            'address' => $data['address'] !== '' ? $data['address'] : null,
            'city' => $data['city'] !== '' ? $data['city'] : null,
            'state' => $data['state'] !== '' ? $data['state'] : null,
            'country' => $data['country'] !== '' ? $data['country'] : null,
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
            'tags' => $data['tags'] !== [] ? array_values($data['tags']) : null,
            'lifecycle' => $data['lifecycle'],
            'is_vip' => (bool) $data['is_vip'],
            'marketing_opt_in' => (bool) $data['marketing_opt_in'],
        ]);
        $customer->save();

        $linker->recomputeAggregates($customer);

        session()->flash('status', $this->customerId ? 'Customer updated.' : 'Customer added.');

        $this->redirectRoute('tenant.customers.show', ['customer' => $customer->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.customers.form', [
            'lifecycleOptions' => CustomerLifecycle::cases(),
        ]);
    }
}
