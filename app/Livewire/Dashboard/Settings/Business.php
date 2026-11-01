<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Settings;

use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The business's own identity (docs/MULTITENANCY.md, step 6): its name,
 * the title its booking site uses, and the phone and email guests see
 * on the booking site and in emails. "Centurion" is the software; this
 * is what guests and staff know the business as. The logo is uploaded
 * under Photos. Manager-only.
 */
#[Layout('layouts.tenant-auth')]
class Business extends Component
{
    public string $name = '';

    public string $siteTitle = '';

    public string $phone = '';

    public string $email = '';

    public function mount(): void
    {
        $tenant = $this->tenant();
        $this->name = (string) $tenant->name;
        $this->siteTitle = (string) $tenant->site_title;
        $this->phone = (string) $tenant->phone;
        $this->email = (string) $tenant->email;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'siteTitle' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]{7,}$/'],
            'email' => ['required', 'email:rfc', 'max:190'],
        ], [], ['siteTitle' => 'booking site title', 'phone' => 'phone number']);

        $this->tenant()->forceFill([
            'name' => trim($data['name']),
            'site_title' => trim((string) $data['siteTitle']) ?: null,
            'phone' => trim((string) $data['phone']) ?: null,
            'email' => strtolower(trim($data['email'])),
        ])->save();

        $this->dispatch('notify', title: 'Saved', message: 'Your business details are updated.', kind: 'ok');
    }

    public function render(): View
    {
        return view('livewire.dashboard.settings.business', [
            'logo' => $this->tenant()->logoUrl(),
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return Tenant::current();
    }
}
