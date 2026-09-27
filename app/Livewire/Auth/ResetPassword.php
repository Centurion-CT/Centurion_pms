<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\Operator;
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

#[Layout('layouts.tenant-auth')]
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

        $response = Password::broker('operators')->reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (Operator $operator, string $password): void {
                $operator->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($operator));
            },
        );

        if ($response !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($response),
            ]);
        }

        session()->flash('status', __($response));

        $this->redirectRoute('tenant.login');
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password');
    }
}
