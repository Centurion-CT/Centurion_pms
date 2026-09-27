<?php

declare(strict_types=1);

namespace App\Livewire\Booking;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Workspace;
use App\Models\WorkspacePlan;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Public-facing workspaces browse page for Co-working operators.
 *
 * Lists active workspaces + plans and captures sign-up interest into
 * the operator's Customers list. Payment integration is intentionally
 * deferred, for now an enquiry creates a Customer row + a flash
 * confirmation; the operator follows up offline (Paystack subscription
 * setup happens via their dashboard).
 */
class WorkspacesBrowse extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public ?int $plan_id = null;

    public string $message = '';

    public bool $submitted = false;

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required_without:phone', 'nullable', 'email', 'max:160'],
            'phone'   => ['required_without:email', 'nullable', 'string', 'max:32'],
            'plan_id' => ['nullable', 'integer', 'exists:workspace_plans,id'],
            'message' => ['nullable', 'string', 'max:2000'],
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

        // Find-or-create the customer at the current branch — a
        // returning guest at a sister property still spawns a fresh
        // per-branch record (see DeskBookings\Form).
        $existing = null;
        if ($this->email !== '') {
            $existing = Customer::query()->forCurrentProperty()
                ->where('email', $this->email)->first();
        }
        if ($existing === null && $this->phone !== '') {
            $existing = Customer::query()->forCurrentProperty()
                ->where('phone', $this->phone)->first();
        }

        $tags = ['workspace-enquiry'];
        if ($existing === null) {
            Customer::query()->create([
                'name'  => $this->name,
                'email' => $this->email ?: null,
                'phone' => $this->phone ?: null,
                'tags'  => $tags,
                'notes' => $this->message !== ''
                    ? '[Workspace enquiry] '.$this->message
                    : null,
            ]);
        } else {
            $existingTags = is_array($existing->tags) ? $existing->tags : [];
            $existing->update([
                'name'  => $this->name !== '' ? $this->name : $existing->name,
                'email' => $existing->email ?: ($this->email ?: null),
                'phone' => $existing->phone ?: ($this->phone ?: null),
                'tags'  => array_values(array_unique(array_merge($existingTags, $tags))),
                'notes' => trim(($existing->notes ?? '')."\n[Workspace enquiry] ".$this->message),
            ]);
        }

        $this->submitted = true;
        $this->reset(['message']);
    }

    public function render(): View
    {
        return view('livewire.booking.workspaces-browse', [
            'workspaces' => Workspace::query()->active()->orderBy('name')->get(),
            'plans'      => WorkspacePlan::query()->active()->orderBy('price_naira')->get(),
        ])->layout('layouts.tenant-public');
    }
}
