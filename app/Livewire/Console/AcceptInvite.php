<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Models\ConsoleUser;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Choose a console password from an invite or reset link. The link is
 * single-use and lasts ConsoleUser::INVITE_DAYS days.
 */
#[Layout('console.layout')]
class AcceptInvite extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function save(): void
    {
        $user = ConsoleUser::forInvite($this->token);
        abort_if($user === null, 404);

        $this->validate(['password' => ['required', 'string', 'min:12', 'confirmed']]);

        $user->forceFill([
            'password' => $this->password,
            'invite_token_hash' => null,
            'invite_expires_at' => null,
        ])->save();

        session()->flash('console.status', 'Password set. Sign in with it now.');
        $this->redirectRoute('console.login');
    }

    public function render(): View
    {
        return view('livewire.console.accept-invite', [
            'user' => ConsoleUser::forInvite($this->token),
        ])->title('Choose a password · '.config('brand.name').' console');
    }
}
