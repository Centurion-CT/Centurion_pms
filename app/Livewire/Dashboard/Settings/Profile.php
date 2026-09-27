<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Settings;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Operator self-service profile: name, email, password.
 *
 * Validates against the operator's own row (ignore self in unique
 * check). Password change requires the current password as a
 * defense-in-depth step, even if the operator's session is
 * hijacked, the attacker can't swap the password without knowing
 * the existing one.
 */
#[Layout('layouts.tenant-auth')]
class Profile extends Component
{
    public string $name = '';
    public string $email = '';
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $op = auth('web')->user();
        if ($op !== null) {
            $this->name = (string) $op->name;
            $this->email = (string) $op->email;
        }
    }

    public function updateProfile(): void
    {
        $op = auth('web')->user();
        if ($op === null) {
            return;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:180',
                Rule::unique('operators', 'email')->ignore($op->id),
            ],
        ]);

        $op->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
        ])->save();

        $this->dispatch('notify', title: 'Profile updated', message: 'Your name + email are saved.', kind: 'ok');
    }

    /**
     * Persist the operator's sidebar layout pick. Same shape as the
     * REST endpoint (OperatorPreferencesController) — kept here too
     * so the settings picker can save via the Livewire channel and
     * sidestep any fetch/CSRF/routing edges that were blocking the
     * setting from sticking across navigations.
     */
    public function saveSidebarVariant(string $variant): void
    {
        if (! in_array($variant, ['tiles', 'list'], true)) {
            return;
        }
        $op = auth('web')->user();
        if ($op === null) {
            return;
        }
        $prefs = $op->preferences ?? [];
        $prefs['sidebar_variant'] = $variant;

        // Bypass the Eloquent write guard (AppServiceProvider) using a
        // direct query update. The guard is designed to block
        // billing-material changes on tenants with an expired
        // subscription — a UI layout preference is neither, and
        // blocking it caused the "sets, then reverts on nav" bug the
        // user hit while impersonating a tenant with active billing
        // read-only.
        \App\Models\Operator::query()
            ->where('id', $op->id)
            ->update(['preferences' => $prefs]);
    }

    public function updatePassword(): void
    {
        $op = auth('web')->user();
        if ($op === null) {
            return;
        }

        $this->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $op->forceFill(['password' => Hash::make($this->password)])->save();

        $this->current_password = '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->dispatch('notify', title: 'Password changed', message: 'Sign in with the new password next time.', kind: 'ok');
    }

    public function render(): View
    {
        return view('livewire.dashboard.settings.profile');
    }
}
