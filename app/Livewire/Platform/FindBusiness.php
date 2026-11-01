<?php

declare(strict_types=1);

namespace App\Livewire\Platform;

use App\Enums\TenantStatus;
use App\Mail\Platform\LoginLinksMail;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
use App\Platform\BusinessAddress;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * centurionpms.com/login. Staff log in on their own business's address,
 * so this page only sends them there: by the address they type, or by
 * emailing every business their address works at.
 *
 * The email path never says whether an address is registered.
 * Design: design/platform/superdesign/07-login-find.png.
 */
#[Layout('platform.layouts.app')]
class FindBusiness extends Component
{
    public string $slug = '';

    public string $email = '';

    public bool $sent = false;

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
        $this->reset('email', 'sent');
    }

    public function render(): View
    {
        return view('livewire.platform.find-business')->title('Log in · '.config('brand.name'));
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
