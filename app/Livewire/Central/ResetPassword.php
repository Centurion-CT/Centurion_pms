<?php

declare(strict_types=1);

namespace App\Livewire\Central;

use App\Models\IdentityAccount;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Central-domain reset-password screen (docs/unified-login.md).
 * Reached from the email link the ForgotPassword flow sends. Token
 * arrives in the URL path, email in the query string (Laravel's
 * default password-reset link shape). Both are #[Locked] so the
 * operator can't tamper with them via Livewire dispatch.
 */
#[Layout('layouts.auth')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    #[Validate('required|email|max:180')]
    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ];
    }

    public function resetPassword(): void
    {
        $this->validate();

        $response = Password::broker('identities')->reset(
            [
                'email' => Str::lower(trim($this->email)),
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (IdentityAccount $identity, string $password): void {
                $identity->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($identity));
            },
        );

        if ($response !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($response),
            ]);
        }

        session()->flash('status', 'Password updated. Sign in with the new one.');

        $this->redirectRoute('login');
    }

    public function render(): View
    {
        return view('livewire.central.reset-password');
    }
}
