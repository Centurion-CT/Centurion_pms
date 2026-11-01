<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Http\Middleware\EnsureConsoleAdmin;
use App\Mail\Platform\ConsoleCodeMail;
use App\Models\ConsoleUser;
use App\Platform\ConsoleCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Console sign-in (docs/MULTITENANCY.md, step 7): email and password,
 * then a 6-digit code emailed to that address. A wrong email and a wrong
 * password get the same answer.
 */
#[Layout('console.layout')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public string $code = '';

    #[Locked]
    public ?int $userId = null;

    public string $error = '';

    public function mount(): void
    {
        if (Setup::isOpen()) {
            $this->redirectRoute('console.setup');
        }
    }

    public function check(ConsoleCode $codes): void
    {
        $this->validate(['email' => ['required', 'email:rfc'], 'password' => ['required', 'string']]);
        $this->error = '';

        $key = 'console-login:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->error = 'Too many tries. Wait a few minutes and try again.';

            return;
        }
        RateLimiter::hit($key, 600);

        $user = ConsoleUser::query()->where('email', ConsoleUser::normaliseEmail($this->email))->first();
        if ($user === null || ! $user->isActive() || ! Hash::check($this->password, (string) $user->password)) {
            $this->error = 'Email or password is wrong.';

            return;
        }

        $this->password = '';
        $this->userId = $user->id;
        if ($codes->waitBeforeResend($user->email) === 0) {
            Mail::to($user->email)->send(new ConsoleCodeMail($codes->issue($user->email)));
        }
    }

    public function verify(ConsoleCode $codes): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);
        $user = $this->userId === null ? null : ConsoleUser::query()->find($this->userId);
        if ($user === null || ! $user->isActive()) {
            $this->again();

            return;
        }

        $result = $codes->verify($user->email, $this->code);
        if ($result !== 'ok') {
            $this->error = match ($result) {
                'expired' => 'That code has expired. Start again.',
                'locked' => 'Too many wrong codes. Start again.',
                default => "That code doesn't match.",
            };

            return;
        }

        $user->forceFill(['last_signed_in_at' => Carbon::now()])->save();
        session()->regenerate();
        session()->put(EnsureConsoleAdmin::SESSION_KEY, $user->id);
        $this->redirectRoute('console.businesses');
    }

    public function again(): void
    {
        $this->reset('password', 'code', 'userId', 'error');
    }

    public function render(): View
    {
        return view('livewire.console.login')->title('Sign in · '.config('brand.name').' console');
    }
}
