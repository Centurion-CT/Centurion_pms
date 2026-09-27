<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Enums\VerificationChannel;
use App\Mail\Onboarding\EmailOtpMail;
use App\Models\Operator;
use App\Models\VerificationCode;
use App\Services\WhatsApp\WhatsAppOtpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Random\Randomizer;

/**
 * 6-digit OTP service for the onboarding wizard (PRD §6.1.1).
 *
 * Policy:
 *   - 6 numeric digits, generated with cryptographic randomness
 *   - 10-minute expiry
 *   - Resend cooldown: 60 seconds since last_sent_at
 *   - Max 5 attempts -> 30-minute lockout
 *   - Codes stored hashed; only the recipient ever sees plaintext
 *
 * Email codes are delivered via the queued EmailOtpMail mailable.
 * Phone codes are delivered via the WhatsApp Business API.
 */
class OtpService
{
    private const CODE_TTL_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 30;

    public function __construct(
        private readonly WhatsAppOtpService $whatsapp,
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * Issue (or refresh) the OTP for this operator + channel and dispatch it.
     * Returns the cooldown-remaining seconds (always 0 for $allowImmediate=true
     * since we just sent one).
     *
     * @throws OtpCooldownException
     */
    public function issueOrRefresh(
        Operator $operator,
        VerificationChannel $channel,
        bool $allowImmediate = false,
    ): VerificationCode {
        $existing = VerificationCode::query()
            ->where('operator_id', $operator->id)
            ->where('channel', $channel->value)
            ->first();

        if (! $allowImmediate && $existing !== null && $existing->last_sent_at !== null) {
            $cooldownEnd = $existing->last_sent_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
            if (Carbon::now()->lessThan($cooldownEnd)) {
                throw new OtpCooldownException(
                    (int) ceil(Carbon::now()->diffInSeconds($cooldownEnd, absolute: true)),
                );
            }
        }

        $code = $this->generateCode();
        $now = Carbon::now();

        $record = VerificationCode::query()->updateOrCreate(
            ['operator_id' => $operator->id, 'channel' => $channel->value],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(self::CODE_TTL_MINUTES),
                'last_sent_at' => $now,
                'locked_until' => null,
                'consumed_at' => null,
            ],
        );

        $this->dispatch($operator, $channel, $code);

        return $record;
    }

    public function verify(
        Operator $operator,
        VerificationChannel $channel,
        string $submitted,
    ): OtpResult {
        $record = VerificationCode::query()
            ->where('operator_id', $operator->id)
            ->where('channel', $channel->value)
            ->first();

        if ($record === null) {
            return OtpResult::NotFound;
        }
        if ($record->isConsumed()) {
            return OtpResult::AlreadyConsumed;
        }
        if ($record->isLocked()) {
            return OtpResult::Locked;
        }
        if ($record->isExpired()) {
            return OtpResult::Expired;
        }

        if (! $record->matches($submitted)) {
            $record->attempts++;
            if ($record->attempts >= self::MAX_ATTEMPTS) {
                $record->locked_until = Carbon::now()->addMinutes(self::LOCKOUT_MINUTES);
            }
            $record->save();

            return $record->isLocked() ? OtpResult::Locked : OtpResult::IncorrectCode;
        }

        $record->forceFill(['consumed_at' => Carbon::now()])->save();

        return OtpResult::Success;
    }

    /**
     * Returns seconds remaining before another resend is allowed, or 0 if
     * resend is available right now.
     */
    public function resendCooldownSeconds(Operator $operator, VerificationChannel $channel): int
    {
        $record = VerificationCode::query()
            ->where('operator_id', $operator->id)
            ->where('channel', $channel->value)
            ->first();

        if ($record === null || $record->last_sent_at === null) {
            return 0;
        }

        $cooldownEnd = $record->last_sent_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
        $remaining = Carbon::now()->diffInSeconds($cooldownEnd, false);

        return max(0, (int) $remaining);
    }

    private function generateCode(): string
    {
        // 6 digits, leading zeros preserved.
        return str_pad((string) $this->randomizer->getInt(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private function dispatch(Operator $operator, VerificationChannel $channel, string $code): void
    {
        match ($channel) {
            VerificationChannel::Email => Mail::to($operator->email)->queue(
                new EmailOtpMail($operator, $code),
            ),
            VerificationChannel::Phone => $this->whatsapp->sendOtp($operator, $code),
        };
    }
}
