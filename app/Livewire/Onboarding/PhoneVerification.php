<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\VerificationChannel;
use App\Events\TenantOnboarded;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Otp\OtpCooldownException;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use App\Services\Sendpipe\SendpipeException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Step 3/3 of the onboarding wizard (PRD §6.1.1). WhatsApp delivers the OTP;
 * on success we mark the phone verified and fire TenantOnboarded so
 * downstream listeners (subdomain provisioning, welcome mail) can run.
 *
 * Failure surface for mount():
 *  - operator has no phone on file (phone was optional at register) →
 *    surface a phone-entry form instead of the code input; do not
 *    attempt to dispatch until they supply one
 *  - Sendpipe rejects (bad number, template not approved, quota) OR
 *    the network drops → show a retryable banner so the operator can
 *    fix the number or hit resend, rather than a 500
 */
#[Layout('layouts.tenant-auth')]
class PhoneVerification extends Component
{
    #[Validate('required|string|size:6')]
    public string $code = '';

    public int $resendCooldown = 0;

    public string $phone = '';

    public ?string $sendError = null;

    public function mount(OtpService $otp): void
    {
        $operator = $this->operator();
        $this->phone = (string) ($operator->phone ?? '');
        $this->attemptSend($otp, $operator);
        $this->resendCooldown = $otp->resendCooldownSeconds($operator, VerificationChannel::Phone);
    }

    public function savePhone(OtpService $otp): void
    {
        $data = $this->validate([
            'phone' => 'required|string|max:20|regex:/^\+?[0-9\s\-]{7,20}$/',
        ]);

        $operator = $this->operator();
        $operator->forceFill(['phone' => trim($data['phone'])])->save();

        // Fresh attempt now that we have a valid number. If Sendpipe
        // still complains, the banner surfaces the reason.
        $this->attemptSend($otp, $operator->fresh());
        $this->resendCooldown = $otp->resendCooldownSeconds($operator, VerificationChannel::Phone);
    }

    /**
     * Wraps issueOrRefresh with the full failure taxonomy for this
     * screen. On any failure, sets $sendError so the view can render a
     * retry banner instead of the whole request 500ing.
     */
    private function attemptSend(OtpService $otp, Operator $operator): void
    {
        $this->sendError = null;

        if ((string) ($operator->phone ?? '') === '') {
            // Nothing to send to yet. View renders the phone-entry form.
            return;
        }

        try {
            $otp->issueOrRefresh($operator, VerificationChannel::Phone);
        } catch (OtpCooldownException) {
            // Existing code still valid, treat as success.
        } catch (SendpipeException|ConnectionException|RuntimeException $e) {
            Log::warning('Phone OTP dispatch failed at onboarding', [
                'operator_id' => $operator->id,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
            ]);
            $this->sendError = 'We couldn\'t send the WhatsApp code just now, check the number and hit resend, or wait a moment and retry.';
        } catch (Throwable $e) {
            // Unexpected, log with full context but still show the
            // banner so onboarding isn't dead-in-the-water.
            report($e);
            $this->sendError = 'Something went wrong sending your code. Try resend in a moment.';
        }
    }

    public function submit(OtpService $otp): void
    {
        $this->validate();

        $result = $otp->verify($this->operator(), VerificationChannel::Phone, $this->code);

        match ($result) {
            OtpResult::Success => $this->onSuccess(),
            OtpResult::IncorrectCode => throw ValidationException::withMessages(['code' => __('Incorrect code.')]),
            OtpResult::Expired => throw ValidationException::withMessages(['code' => __('Code expired, request a new one.')]),
            OtpResult::Locked => throw ValidationException::withMessages(['code' => __('Too many attempts. Try again in 30 minutes.')]),
            OtpResult::NotFound, OtpResult::AlreadyConsumed => throw ValidationException::withMessages(['code' => __('No active code. Request a new one.')]),
        };
    }

    public function resend(OtpService $otp): void
    {
        $operator = $this->operator();

        if ((string) ($operator->phone ?? '') === '') {
            $this->sendError = 'Add a phone number first, then resend.';

            return;
        }

        try {
            $otp->issueOrRefresh($operator, VerificationChannel::Phone);
            $this->sendError = null;
            $this->resendCooldown = $otp->resendCooldownSeconds($operator, VerificationChannel::Phone);
            session()->flash('status', __('Code sent.'));
        } catch (OtpCooldownException $e) {
            throw ValidationException::withMessages([
                'code' => __('Please wait :s seconds before requesting a new code.', ['s' => $e->secondsRemaining]),
            ]);
        } catch (SendpipeException|ConnectionException|RuntimeException $e) {
            Log::warning('Phone OTP resend failed', [
                'operator_id' => $operator->id,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
            ]);
            $this->sendError = 'We couldn\'t send the WhatsApp code, check the number and try again.';
        } catch (Throwable $e) {
            report($e);
            $this->sendError = 'Something went wrong sending your code. Try again in a moment.';
        }
    }

    /**
     * Skip escape hatch (PRD §6.1.1 step 3). When Sendpipe is down or the
     * operator's WhatsApp number can't receive the code, forcing them
     * through this step blocks the whole dashboard. Recording an explicit
     * skip lets the middleware allow them in while keeping phone_verified_at
     * honest, they can verify later from their profile.
     */
    public function skip(): void
    {
        $operator = $this->operator();

        if ($operator->hasResolvedPhoneStep()) {
            $this->redirectRoute('tenant.dashboard');

            return;
        }

        $operator->markPhoneVerificationSkipped();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        TenantOnboarded::dispatch($tenant, $operator);

        $this->redirectRoute('tenant.dashboard');
    }

    private function onSuccess(): void
    {
        $operator = $this->operator();
        $operator->markPhoneAsVerified();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        TenantOnboarded::dispatch($tenant, $operator);

        $this->redirectRoute('tenant.dashboard');
    }

    private function operator(): Operator
    {
        /** @var Operator $op */
        $op = auth()->guard('web')->user();

        return $op;
    }

    public function render(): View
    {
        return view('livewire.onboarding.phone-verification', [
            'operator' => $this->operator(),
        ]);
    }
}
