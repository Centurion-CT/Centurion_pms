<?php

declare(strict_types=1);

namespace App\Livewire\Booking;

use App\Enums\UnitStatus;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Public-facing residential units browse page.
 *
 * Lists available rental units and lets prospective tenants register
 * interest. Conversion happens offline, operator follows up, signs
 * the lease in the dashboard. Enquiries become Customer rows with the
 * "lease-enquiry" tag for easy filtering.
 */
class UnitsBrowse extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public ?int $unit_id = null;

    public string $move_in_target = '';

    public string $message = '';

    public bool $submitted = false;

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:120'],
            'email'          => ['required_without:phone', 'nullable', 'email', 'max:160'],
            'phone'          => ['required_without:email', 'nullable', 'string', 'max:32'],
            'unit_id'        => ['nullable', 'integer', 'exists:units,id'],
            'move_in_target' => ['nullable', 'date'],
            'message'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function submit(): void
    {
        $this->validate();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }

        // Per-branch CRM: enquiry only matches an existing Customer at
        // the current property. See DeskBookings\Form for reasoning.
        $existing = null;
        if ($this->email !== '') {
            $existing = Customer::query()->forCurrentProperty()
                ->where('email', $this->email)->first();
        }
        if ($existing === null && $this->phone !== '') {
            $existing = Customer::query()->forCurrentProperty()
                ->where('phone', $this->phone)->first();
        }

        $unitName = $this->unit_id !== null
            ? Unit::query()->where('id', $this->unit_id)->value('name')
            : null;

        $enquiryNote = trim(
            '[Lease enquiry] '
            .($unitName ? 'Unit: '.$unitName.'. ' : '')
            .($this->move_in_target !== '' ? 'Move-in target: '.$this->move_in_target.'. ' : '')
            .$this->message
        );

        $tags = ['lease-enquiry'];
        if ($existing === null) {
            Customer::query()->create([
                'name'  => $this->name,
                'email' => $this->email ?: null,
                'phone' => $this->phone ?: null,
                'tags'  => $tags,
                'notes' => $enquiryNote,
            ]);
        } else {
            $existingTags = is_array($existing->tags) ? $existing->tags : [];
            $existing->update([
                'name'  => $this->name !== '' ? $this->name : $existing->name,
                'email' => $existing->email ?: ($this->email ?: null),
                'phone' => $existing->phone ?: ($this->phone ?: null),
                'tags'  => array_values(array_unique(array_merge($existingTags, $tags))),
                'notes' => trim(($existing->notes ?? '')."\n".$enquiryNote),
            ]);
        }

        $this->submitted = true;
        $this->reset(['message']);
    }

    public function render(): View
    {
        return view('livewire.booking.units-browse', [
            'units' => Unit::query()
                ->whereIn('status', [UnitStatus::Available->value])
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.tenant-public');
    }
}
