<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Enums\ConsoleRole;
use App\Http\Middleware\EnsureConsoleAdmin;
use App\Mail\Platform\ConsoleInviteMail;
use App\Models\ConsoleUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Who may use the console (docs/MULTITENANCY.md, step 7), owners only.
 * Owners invite people by email as owner or admin, change roles, send
 * password resets and remove users. There is always at least one owner,
 * and nobody removes or demotes themselves.
 */
#[Layout('console.layout')]
class Users extends Component
{
    public string $name = '';

    public string $email = '';

    public string $role = 'admin';

    /**
     * Every request, actions included: Livewire updates don't pass
     * through the route's middleware.
     */
    public function boot(): void
    {
        abort_unless(EnsureConsoleAdmin::user()?->isOwner() ?? false, 403);
    }

    public function invite(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('console_users', 'email')],
            'role' => ['required', Rule::enum(ConsoleRole::class)],
        ], ['email.unique' => 'That email already has console access.']);

        $user = ConsoleUser::query()->create([
            'name' => trim($data['name']),
            'email' => ConsoleUser::normaliseEmail($data['email']),
            'role' => $data['role'],
            'invited_by_id' => $this->me()->id,
        ]);
        $this->sendLink($user, reset: false);

        $this->reset('name', 'email', 'role');
        $this->dispatch('notify', message: "Invite sent to {$user->email}.");
    }

    public function changeRole(int $id, string $role): void
    {
        $user = $this->other($id);
        $new = ConsoleRole::from($role);
        if ($user->isOwner() && $new !== ConsoleRole::Owner && $this->ownerCount() <= 1) {
            $this->dispatch('notify', message: 'The console needs at least one owner.');

            return;
        }
        $user->forceFill(['role' => $new])->save();
        $this->dispatch('notify', message: "{$user->name} is now an {$new->label()}.");
    }

    public function resetPassword(int $id): void
    {
        $user = $this->other($id);
        $this->sendLink($user, reset: $user->isActive());
        $this->dispatch('notify', message: "A link to set a password went to {$user->email}.");
    }

    public function remove(int $id): void
    {
        $user = $this->other($id);
        if ($user->isOwner() && $this->ownerCount() <= 1) {
            $this->dispatch('notify', message: 'The console needs at least one owner.');

            return;
        }
        $user->delete();
        $this->dispatch('notify', message: "{$user->name} no longer has console access.");
    }

    public function render(): View
    {
        return view('livewire.console.users', [
            'users' => ConsoleUser::query()->with('invitedBy')->orderByRaw("role = 'owner' desc")->orderBy('name')->get(),
            'me' => $this->me(),
        ])->title('Console users · '.config('brand.name'));
    }

    private function sendLink(ConsoleUser $user, bool $reset): void
    {
        $token = $user->issueInvite();
        Mail::to($user->email)->send(new ConsoleInviteMail(
            name: $user->name,
            url: route('console.invite', ['token' => $token]),
            reset: $reset,
            invitedBy: $this->me()->name,
        ));
    }

    /** Another console user; never yourself. */
    private function other(int $id): ConsoleUser
    {
        abort_if($id === $this->me()->id, 403);

        return ConsoleUser::query()->findOrFail($id);
    }

    private function me(): ConsoleUser
    {
        /** @var ConsoleUser */
        return EnsureConsoleAdmin::user();
    }

    private function ownerCount(): int
    {
        return ConsoleUser::query()->where('role', ConsoleRole::Owner->value)->count();
    }
}
