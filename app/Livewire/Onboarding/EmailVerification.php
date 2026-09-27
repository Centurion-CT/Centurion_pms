<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\VerificationChannel;
use App\Models\Operator;
use App\Services\Otp\OtpCooldownException;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Step 2/3 of the onboarding wizard (PRD §6.1.1). Emails a 6-digit OTP, lets
 * the operator submit it, marks the email verified on success.
 */
#[Layout('layouts.tenant-auth')]
class EmailVerification extends Component
{
    #[Validate('required|string|size:6')]
    public string $code = '';

    public int $resendCooldown = 0;

    public function mount(OtpService $otp): void
    {
        $operator = $this->operator();

        // First-time render -> issue the OTP. If the cooldown is still hot
        // (operator refreshed within 60s), keep the existing live code.
        try {
            $otp->issueOrRefresh($operator, VerificationChannel::Email);
        } catch (OtpCooldownException) {
            // The existing code is still valid.
        }

        $this->resendCooldown = $otp->resendCooldownSeconds($operator, VerificationChannel::Email);
    }

    public function submit(OtpService $otp): void
    {
        $this->validate();

        $result = $otp->verify($this->operator(), VerificationChannel::Email, $this->code);

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
        try {
            $otp->issueOrRefresh($this->operator(), VerificationChannel::Email);
            $this->resendCooldown = $otp->resendCooldownSeconds($this->operator(), VerificationChannel::Email);
            session()->flash('status', __('Code sent.'));
        } catch (OtpCooldownException $e) {
            throw ValidationException::withMessages([
                'code' => __('Please wait :s seconds before requesting a new code.', ['s' => $e->secondsRemaining]),
            ]);
        }
    }

    private function onSuccess(): void
    {
        $this->operator()->markEmailAsVerified();

        $this->redirectRoute('tenant.onboarding.phone');
    }

    private function operator(): Operator
    {
        /** @var Operator $op */
        $op = auth()->guard('web')->user();

        return $op;
    }

    public function render(): View
    {
        return view('livewire.onboarding.email-verification', [
            'operator' => $this->operator(),
        ]);
    }
}
