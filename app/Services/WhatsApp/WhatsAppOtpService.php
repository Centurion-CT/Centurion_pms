<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Operator;
use App\Services\Sendpipe\SendpipeClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends 6-digit OTPs via WhatsApp (PRD §6.1.1) using Sendpipe as the relay.
 *
 * Meta requires a pre-approved template for any WhatsApp message sent
 * outside an active 24h session window — OTPs always do, so we use a
 * template named in `services.sendpipe.whatsapp_otp_template`
 * (default `kev_otp`, language `en`). The template body must contain a
 * single positional placeholder for the code.
 *
 * If the Sendpipe API key is unset (dev/CI) the call is a no-op + log line
 * so the wizard works end-to-end without a real Sendpipe account.
 */
class WhatsAppOtpService
{
    public function __construct(private readonly SendpipeClient $sendpipe) {}

    public function sendOtp(Operator $operator, string $code): void
    {
        if ((string) config('services.sendpipe.api_key') === '') {
            Log::warning('WhatsApp OTP skipped (Sendpipe key unset)', [
                'operator_id' => $operator->id,
            ]);

            return;
        }

        if ((string) $operator->phone === '') {
            throw new RuntimeException('Cannot send WhatsApp OTP — operator has no phone number.');
        }

        $this->sendpipe->sendWhatsAppTemplate(
            to: [(string) $operator->phone],
            templateName: (string) config('services.sendpipe.whatsapp_otp_template', 'kev_otp'),
            templateLanguage: (string) config('services.sendpipe.whatsapp_otp_language', 'en'),
            templateParams: [$code],
        );
    }
}
