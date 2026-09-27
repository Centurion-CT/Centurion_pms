<?php

declare(strict_types=1);

namespace App\Livewire\Marketing;

use App\Mail\Marketing\ApiAccessConfirmationMail;
use App\Models\ApiAccessRequest;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public API early-access application. Distinct from BookDemo:
 *
 *   BookDemo is for hospitality operators about to run their
 *   property on Keverna. ApiAccess is for developers who want to
 *   build ON TOP of Keverna (channel managers, custom apps, etc).
 *
 * Storage is the source of truth; a confirmation email to the
 * developer + a WhatsApp ping to sales are best-effort. Rate-limit
 * is per-IP so a rogue script can't flood the queue.
 */
class ApiAccess extends Component
{
    public string $name = '';
    public string $email = '';
    public string $company = '';
    public string $github_url = '';
    public string $use_case = '';
    public string $integrations = '';

    public bool $submitted = false;

    /** Honeypot - bots love filling every field. */
    public string $company_website = '';

    public function submit(GuestNotifier $notifier): void
    {
        if ($this->company_website !== '') {
            $this->submitted = true;
            return;
        }

        $data = $this->validate([
            'name'         => ['required', 'string', 'max:120'],
            'email'        => ['required', 'email', 'max:180'],
            'company'      => ['nullable', 'string', 'max:160'],
            'github_url'   => ['nullable', 'url', 'max:240'],
            'use_case'     => ['nullable', 'string', 'max:400'],
            'integrations' => ['nullable', 'string', 'max:300'],
        ]);

        $key = 'api-access:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many submissions from your network. Give it a few minutes.');
            return;
        }
        RateLimiter::hit($key, 600);

        $request = ApiAccessRequest::query()->create([
            ...$data,
            'status'     => 'new',
            'ip'         => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 380, ''),
        ]);

        // Fire the confirmation mail. Queued via Mailable's ShouldQueue
        // so the response isn't blocked on SMTP; if the mail transport
        // is down, the failure lands in the queue's failed_jobs table
        // rather than a 500 for the developer.
        try {
            Mail::to($request->email)->queue(new ApiAccessConfirmationMail($request));
        } catch (\Throwable $e) {
            Log::warning('API access confirmation mail queue failed (non-blocking)', [
                'id' => $request->id, 'e' => $e->getMessage(),
            ]);
        }

        // Sales team WhatsApp ping.
        $salesPhone = (string) config('services.sales.whatsapp', '+2347043043061');
        if ($salesPhone !== '') {
            try {
                $notifier->sendRequestResolvedNotice(
                    $salesPhone,
                    sprintf(
                        "[API waitlist] %s (%s)%s. Use case: %s",
                        $request->name,
                        $request->email,
                        $request->company ? ' - '.$request->company : '',
                        Str::limit($request->use_case ?: 'not specified', 120),
                    ),
                );
            } catch (\Throwable $e) {
                Log::warning('API access WhatsApp ping failed (non-blocking)', ['id' => $request->id, 'e' => $e->getMessage()]);
            }
        }

        activity('api_access_request')
            ->on($request)
            ->log("New API early-access request from {$request->name}.");

        $this->submitted = true;
    }

    #[Layout('layouts.marketing')]
    public function render(): View
    {
        return view('livewire.marketing.api-access');
    }
}
