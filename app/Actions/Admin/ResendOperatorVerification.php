<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\VerificationChannel;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Otp\OtpService;
use RuntimeException;

/**
 * Admin override for the onboarding OTP resend (PRD §6.1.1). Bypasses the
 * 60-second per-operator cooldown that the wizard enforces, so support can
 * unstick a founder whose earlier code got lost / went to spam / expired
 * mid-onboarding.
 *
 * Scope guard: refuses to send if the operator is already verified on the
 * requested channel — nothing to resend, and dispatching a live OTP against
 * a verified address would be noise the operator can't act on.
 */
class ResendOperatorVerification
{
    public function __construct(private readonly OtpService $otp) {}

    public function handle(Tenant $tenant, Admin $admin, VerificationChannel $channel): void
    {
        $tenant->execute(function () use ($tenant, $admin, $channel): void {
            $operator = Operator::query()->orderBy('id')->first();

            if ($operator === null) {
                throw new RuntimeException("Tenant {$tenant->slug} has no operator to resend a code to.");
            }

            $alreadyVerified = match ($channel) {
                VerificationChannel::Email => $operator->email_verified_at !== null,
                VerificationChannel::Phone => $operator->phone_verified_at !== null,
            };

            if ($alreadyVerified) {
                throw new RuntimeException(
                    "{$operator->email} has already verified their {$channel->value}; nothing to resend."
                );
            }

            if ($channel === VerificationChannel::Phone && ($operator->phone === null || $operator->phone === '')) {
                throw new RuntimeException("{$operator->email} has no phone on file — can't send a WhatsApp OTP.");
            }

            $this->otp->issueOrRefresh($operator, $channel, allowImmediate: true);

            activity('admin')
                ->causedBy($admin)
                ->performedOn($tenant)
                ->event('onboarding_otp_resent')
                ->withProperties(['channel' => $channel->value, 'operator_id' => $operator->id])
                ->log("Admin {$admin->name} resent {$channel->value} verification code to {$operator->email}.");
        });
    }
}
