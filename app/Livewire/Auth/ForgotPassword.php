<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
class ForgotPassword extends Component
{
    #[Validate('required|email|max:180')]
    public string $email = '';

    public string $status = '';

    public function sendResetLink(): void
    {
        $this->validate();

        // The same answer whatever the broker says, including for a
        // throttled email: only someone who works here can be throttled.
        Password::broker('operators')->sendResetLink(['email' => $this->email]);

        $this->status = 'sent';
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}
