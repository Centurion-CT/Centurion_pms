<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Enums\ConsoleRole;
use App\Http\Middleware\EnsureConsoleAdmin;
use App\Mail\Platform\ConsoleCodeMail;
use App\Models\ConsoleUser;
use App\Platform\ConsoleCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Creates the first console owner (docs/MULTITENANCY.md, step 7). Open
 * only while no console user exists and CONSOLE_SETUP_KEY is set; the
 * owner enters that key, their name, email and password, then the code
 * emailed to them. The password is hashed into the session between the
 * two steps and never sent back to the browser.
 */
#[Layout('console.layout')]
class Setup extends Component
{
    private const HASH_KEY = 'console.setup.password_hash';

    public string $step = 'details';

    public string $setupKey = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $code = '';

    public string $error = '';

    public function mount(): void
    {
        abort_unless(self::isOpen(), 404);
    }

    public static function isOpen(): bool
    {
        return (string) config('keverna.console_setup_key') !== '' && ! ConsoleUser::query()->exists();
    }

    public function submit(ConsoleCode $codes): void
    {
        abort_unless(self::isOpen(), 404);
        $this->error = '';

        $limit = 'console-setup:'.request()->ip();
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            $this->error = 'Too many tries. Wait a few minutes and try again.';

            return;
        }
        RateLimiter::hit($limit, 600);

        $this->validate([
            'setupKey' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ], [], ['setupKey' => 'setup key']);

        if (! hash_equals((string) config('keverna.console_setup_key'), $this->setupKey)) {
            $this->addError('setupKey', "That setup key isn't right. Ask whoever runs the servers for it.");

            return;
        }

        session()->put(self::HASH_KEY, Hash::make($this->password));
        $this->reset('password', 'password_confirmation');

        $email = ConsoleUser::normaliseEmail($this->email);
        Mail::to($email)->send(new ConsoleCodeMail($codes->issue($email)));
        $this->step = 'code';
    }

    public function confirm(ConsoleCode $codes): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);
        $email = ConsoleUser::normaliseEmail($this->email);
        $hash = session(self::HASH_KEY);

        $result = $hash === null ? 'expired' : $codes->verify($email, $this->code);
        if ($result !== 'ok') {
            $this->error = match ($result) {
                'expired' => 'That code has expired. Start again.',
                'locked' => 'Too many wrong codes. Start again.',
                default => "That code doesn't match.",
            };

            return;
        }

        $owner = DB::transaction(function () use ($email, $hash): ?ConsoleUser {
            // Someone else may have finished setup in the meantime.
            if (ConsoleUser::query()->lockForUpdate()->exists()) {
                return null;
            }

            // The cast leaves an already-hashed password as it is.
            return ConsoleUser::query()->create([
                'name' => trim($this->name),
                'email' => $email,
                'password' => $hash,
                'role' => ConsoleRole::Owner,
                'last_signed_in_at' => Carbon::now(),
            ]);
        });
        abort_if($owner === null, 404);

        session()->forget(self::HASH_KEY);
        session()->regenerate();
        session()->put(EnsureConsoleAdmin::SESSION_KEY, $owner->id);
        $this->redirectRoute('console.businesses');
    }

    public function render(): View
    {
        return view('livewire.console.setup')->title('Set up the console · '.config('brand.name'));
    }
}
