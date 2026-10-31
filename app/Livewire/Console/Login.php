<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Http\Middleware\EnsureConsoleAdmin;
use App\Mail\Platform\ConsoleCodeMail;
use App\Platform\ConsoleAdmins;
use App\Platform\ConsoleCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Console sign-in (docs/MULTITENANCY.md, step 7): an email, then the
 * 6-digit code sent to it. Codes only go to CONSOLE_ADMINS addresses,
 * but the page answers the same either way, so it doesn't reveal who
 * the admins are.
 */
#[Layout('console.layout')]
class Login extends Component
{
    public string $email = '';

    public string $code = '';

    public bool $sent = false;

    public string $error = '';

    public function send(ConsoleCode $codes): void
    {
        $this->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $this->error = '';

        $key = 'console-codes:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = 'Too many codes asked for. Wait a few minutes and try again.';

            return;
        }
        RateLimiter::hit($key, 600);

        $email = strtolower(trim($this->email));
        if (ConsoleAdmins::allows($email) && $codes->waitBeforeResend($email) === 0) {
            Mail::to($email)->send(new ConsoleCodeMail($codes->issue($email)));
        }

        $this->sent = true;
    }

    public function verify(ConsoleCode $codes): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);
        $email = strtolower(trim($this->email));

        $result = ConsoleAdmins::allows($email) ? $codes->verify($email, $this->code) : 'wrong';
        if ($result !== 'ok') {
            $this->error = match ($result) {
                'expired' => 'That code has expired. Ask for a new one.',
                'locked' => 'Too many wrong codes. Ask for a new one.',
                default => "That code doesn't match.",
            };

            return;
        }

        session()->regenerate();
        session()->put(EnsureConsoleAdmin::SESSION_KEY, $email);
        $this->redirectRoute('console.businesses');
    }

    public function again(): void
    {
        $this->reset('code', 'sent', 'error');
    }

    public function render(): View
    {
        return view('livewire.console.login')->title('Sign in · '.config('brand.name').' console');
    }
}
