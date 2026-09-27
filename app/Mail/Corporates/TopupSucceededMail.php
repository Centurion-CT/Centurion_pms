<?php

declare(strict_types=1);

namespace App\Mail\Corporates;

use App\Models\CorporateWalletTopup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent to a corporate admin after Paystack confirms their wallet
 * top-up. Independent of any tenant context (corporates are central),
 * so flagged NotTenantAware.
 */
class TopupSucceededMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public CorporateWalletTopup $topup,
    ) {}

    public function envelope(): Envelope
    {
        $amount = '₦'.number_format($this->topup->amount_naira);

        return new Envelope(
            subject: $amount.' added to your Keverna wallet',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.corporates.topup-succeeded',
            with: [
                'topup'     => $this->topup,
                'corporate' => $this->topup->corporate,
                'wallet'    => $this->topup->corporate?->wallet,
            ],
        );
    }
}
