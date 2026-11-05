<?php

declare(strict_types=1);

namespace App\Livewire\Platform;

use App\Enums\TenantStatus;
use App\Mail\Platform\LoginLinksMail;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
use App\Platform\BusinessAddress;
use App\Services\PostRegistrationLoginLink;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * centurionpms.com/login. Staff sign in with their email and password and
 * land on their business, choosing one first if they work at several. Each
 * business keeps its own staff accounts, so this checks the password
 * against each account with that email, then hands over through a one-time
 * link (PostRegistrationLoginLink): sessions don't cross addresses.
 *
 * They can also go by their business's address, or have every business
 * their email works at sent to them. None of these say whether an email
 * is registered until the password is right.
 */
#[Layout('platform.layouts.app')]
class FindBusiness extends Component
{
    private const MAX_TRIES = 5;

    private const CHOICES = 'central-login.choices';

    private const CHOICE_MINUTES = 5;

    public string $slug = '';

    public string $email = '';

    public string $password = '';

    /** password | address | links */
    public string $mode = 'password';

    public bool $sent = false;

    /**
     * Businesses to choose from once the password is right, for display.
     * What can actually be opened is kept in the session: see open().
     *
     * @var list<array{id: int, name: string, host: string, role: string}>
     */
    #[Locked]
    public array $choices = [];

    public function signIn(PostRegistrationLoginLink $links): void
    {
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string'],
        ], [], ['email' => 'work email']);

        $email = strtolower(trim($this->email));
        $key = 'central-login:'.sha1($email).':'.request()->ip();
        $password = $this->password;
        $this->password = '';

        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            $this->addError('signIn', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        $accounts = Operator::query()->withoutGlobalScopes()
            ->whereRaw('lower(email) = ?', [$email])
            ->with('tenant')
            ->get();

        // A stranger's email takes as long to refuse as a real one.
        if ($accounts->isEmpty()) {
            Hash::make($password);
        }

        $matched = $accounts->filter(fn (Operator $o) => $o->tenant !== null && Hash::check($password, (string) $o->password));
        if ($matched->isEmpty()) {
            RateLimiter::hit($key);
            $this->addError('signIn', "That email and password don't match. Try again, or get your login links below.");

            return;
        }

        $usable = $matched->filter(fn (Operator $o) => $o->canSignIn() && $o->tenant->status->isOperational())->values();
        if ($usable->isEmpty()) {
            RateLimiter::hit($key);
            $this->addError('signIn', $this->whyNot($matched->first()));

            return;
        }

        // New session id, same CSRF token: the chooser stays on this page,
        // so regenerate()'s new token would expire it.
        RateLimiter::clear($key);
        session()->migrate(true);

        if ($usable->count() === 1) {
            $this->redirect($links->generate($usable->first()->tenant, $usable->first()));

            return;
        }

        session()->put(self::CHOICES, ['ids' => $usable->pluck('id')->all(), 'until' => now()->addMinutes(self::CHOICE_MINUTES)->timestamp]);
        $this->choices = $usable->map(fn (Operator $o) => [
            'id' => $o->id,
            'name' => (string) $o->tenant->name,
            'host' => $o->tenant->primaryHost(),
            'role' => $o->role->label(),
        ])->all();
    }

    /** Only accounts the password opened, for a few minutes. */
    public function open(int $operatorId, PostRegistrationLoginLink $links): void
    {
        $allowed = session()->pull(self::CHOICES);
        $operator = is_array($allowed) && now()->timestamp <= $allowed['until'] && in_array($operatorId, $allowed['ids'], true)
            ? Operator::query()->withoutGlobalScopes()->with('tenant')->find($operatorId)
            : null;

        if ($operator === null || ! $operator->canSignIn() || $operator->tenant === null || ! $operator->tenant->status->isOperational()) {
            $this->reset('choices', 'password');
            $this->addError('signIn', 'That took too long. Please log in again.');

            return;
        }

        $this->redirect($links->generate($operator->tenant, $operator));
    }

    public function show(string $mode): void
    {
        $this->mode = in_array($mode, ['password', 'address', 'links'], true) ? $mode : 'password';
        $this->password = '';
        $this->resetErrorBag();
    }

    public function go(): void
    {
        $slug = strtolower(trim($this->slug));
        $tenant = $slug === '' || HostTenantFinder::isPlatformHost(BusinessAddress::host($slug))
            ? null
            : Tenant::query()->where('slug', $slug)->where('status', '!=', TenantStatus::Archived->value)->first();

        if ($tenant === null) {
            $this->addError('slug', 'No business uses '.BusinessAddress::host($slug ?: 'that-address').'. Check the spelling, or get your links by email below.');

            return;
        }

        $this->redirect($this->loginUrl($tenant));
    }

    public function sendLinks(): void
    {
        $this->validate(['email' => ['required', 'email:rfc', 'max:190']], [], ['email' => 'work email']);

        $key = 'login-links:'.sha1(strtolower($this->email)).':'.request()->ip();
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);
            $this->mailLinks(strtolower(trim($this->email)));
        }

        // Same answer whether or not anything was sent.
        $this->sent = true;
    }

    public function again(): void
    {
        $this->reset('email', 'sent', 'mode');
    }

    public function render(): View
    {
        return view('livewire.platform.find-business')->title('Log in · '.config('brand.name'));
    }

    /** Only reached with the right password, so it can say what's wrong. */
    private function whyNot(Operator $operator): string
    {
        return match (true) {
            ! $operator->tenant->status->isOperational() => $operator->tenant->name." isn't open on ".config('brand.name').' right now.',
            $operator->isRevoked() => 'Your access has been revoked. Ask a manager if this is unexpected.',
            $operator->isSuspended() => 'Your access is temporarily suspended. Ask a manager to restore it.',
            default => "Your account isn't activated yet. Check your invite email, or ask your manager to resend it.",
        };
    }

    private function mailLinks(string $email): void
    {
        $operators = Operator::query()->withoutGlobalScopes()
            ->where('email', $email)
            ->whereNotNull('activated_at')
            ->with('tenant')
            ->get()
            ->filter(fn (Operator $o) => $o->tenant !== null && $o->tenant->status->isOperational());

        if ($operators->isEmpty()) {
            return;
        }

        $businesses = $operators->map(fn (Operator $o) => [
            'name' => $o->tenant->name,
            'host' => $o->tenant->primaryHost(),
            'role' => $o->role->label(),
            'url' => $this->loginUrl($o->tenant, $email),
        ])->values()->all();

        Mail::to($email)->send(new LoginLinksMail((string) $operators->first()->name, $businesses));
    }

    private function loginUrl(Tenant $tenant, ?string $email = null): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return $scheme.'://'.$tenant->primaryHost().($port ? ':'.$port : '').'/login'
            .($email !== null ? '?email='.urlencode($email) : '');
    }
}
