<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TenantOnboarded;
use App\Mail\Operators\OperatorWelcomeMail;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the first-touch welcome mail to the operator who just finished the
 * onboarding wizard (PRD §7.1). Hooks {@see TenantOnboarded} rather than
 * the raw Operator::created event so we only mail once they've actually
 * verified email + phone.
 *
 * Synchronous listener; the mailable it queues IS what defers to the
 * queue worker — see {@see SendBookingCancelledMail} for the same reason.
 */
class SendOperatorWelcomeMail
{
    public function handle(TenantOnboarded $event): void
    {
        if ($event->operator->email === '' || $event->operator->email === null) {
            return;
        }

        Mail::to($event->operator->email)->queue(
            new OperatorWelcomeMail($event->operator, $event->tenant),
        );
    }
}
