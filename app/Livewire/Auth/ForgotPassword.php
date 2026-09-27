<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
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

        $broker = Password::broker('operators');

        $response = $broker->sendResetLink(['email' => $this->email]);

        if ($response === Password::RESET_LINK_SENT) {
            $this->status = __($response);

            return;
        }

        throw ValidationException::withMessages([
            'email' => __($response),
        ]);
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}
