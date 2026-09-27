<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Corporates;

use App\Enums\CorporateStatus;
use App\Models\Corporate;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Register a Corporate (org) from the tenant dashboard, or edit one
 * the current tenant already owns. Central-platform onboarding still
 * exists (Keverna admin) but a property Manager can now spin up their
 * own corp record without waiting on ops — the resulting row is
 * tagged with created_by_tenant_id so it only shows on this tenant's
 * management surface.
 */
#[Layout('layouts.tenant-auth')]
class Form extends Component
{
    #[Locked]
    public ?int $corporateId = null;

    public string $name = '';

    public string $primary_email = '';

    public string $primary_phone = '';

    public string $billing_email = '';

    public string $address = '';

    public string $city = '';

    public string $country = 'NG';

    public string $registration_number = '';

    public string $tax_id = '';

    public string $notes = '';

    public function mount(?Corporate $corporate = null): void
    {
        if ($corporate === null || $corporate->exists === false) {
            return;
        }
        $this->corporateId = $corporate->id;
        $this->name = (string) $corporate->name;
        $this->primary_email = (string) $corporate->primary_email;
        $this->primary_phone = (string) $corporate->primary_phone;
        $this->billing_email = (string) $corporate->billing_email;
        $this->address = (string) $corporate->address;
        $this->city = (string) $corporate->city;
        $this->country = (string) ($corporate->country ?: 'NG');
        $this->registration_number = (string) $corporate->registration_number;
        $this->tax_id = (string) $corporate->tax_id;
        $this->notes = (string) $corporate->notes;
    }

    public function save()
    {
        try {
            $name = trim($this->name);
            $primaryEmail = trim($this->primary_email);
            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($primaryEmail === '' || ! filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid contact email is required.');
            }

            $tenant = Tenant::current();
            if ($tenant === null) {
                throw new RuntimeException('Tenant context missing.');
            }

            $data = [
                'name' => $name,
                'primary_email' => $primaryEmail,
                'primary_phone' => trim($this->primary_phone) !== '' ? trim($this->primary_phone) : null,
                'billing_email' => trim($this->billing_email) !== '' ? trim($this->billing_email) : null,
                'address' => trim($this->address) !== '' ? trim($this->address) : null,
                'city' => trim($this->city) !== '' ? trim($this->city) : null,
                'country' => strtoupper(trim($this->country) ?: 'NG'),
                'registration_number' => trim($this->registration_number) !== '' ? trim($this->registration_number) : null,
                'tax_id' => trim($this->tax_id) !== '' ? trim($this->tax_id) : null,
                'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            ];

            if ($this->corporateId !== null) {
                $corp = Corporate::query()->findOrFail($this->corporateId);
                // Only this tenant's owned corps are editable here;
                // corps onboarded centrally stay read-only.
                if ((int) $corp->created_by_tenant_id !== (int) $tenant->id) {
                    throw new RuntimeException('This corporate was onboarded centrally — edits go through Keverna admin.');
                }
                $corp->forceFill($data)->save();
            } else {
                $data['slug'] = $this->freshSlug($name);
                $data['status'] = CorporateStatus::Active->value;
                $data['kyc_status'] = 'pending';
                $data['created_by_tenant_id'] = $tenant->id;
                $corp = Corporate::query()->create($data);
            }

            session()->flash('notify', [
                'title' => 'Saved',
                'message' => $this->corporateId ? 'Corporate updated.' : 'Corporate registered. You can profile staff on the next screen.',
                'kind' => 'ok',
            ]);
            return Redirect::route('tenant.corporates.show', ['corporate' => $corp]);
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t save', message: $e->getMessage(), kind: 'err');
            return null;
        }
    }

    private function freshSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'corp';
        }
        $slug = $base.'-'.strtolower(Str::random(6));
        while (Corporate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.strtolower(Str::random(6));
        }
        return $slug;
    }

    public function render(): View
    {
        return view('livewire.dashboard.corporates.form', [
            'isEdit' => $this->corporateId !== null,
        ]);
    }
}
