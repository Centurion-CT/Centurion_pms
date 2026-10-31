<?php

declare(strict_types=1);

namespace App\Mail\Corporates;

use App\Mail\Corporates\Concerns\BindsCorporateTenant;
use App\Models\Corporate;
use App\Models\CorporateWallet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Fires once when a corporate's wallet drops below the operator-set
 * low-balance threshold. Rate-limited at the caller (we only fire on
 * the *crossing*, not on every transaction below the line) so admins
 * don't get spammed by every booking after the threshold is breached.
 */
class LowBalanceAlertMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsCorporateTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Corporate $corporate,
        public CorporateWallet $wallet,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wallet running low: '.$this->corporate->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.corporates.low-balance',
            with: [
                'corporate' => $this->corporate,
                'wallet'    => $this->wallet,
                'walletUrl' => $this->walletUrl(),
            ],
        );
    }

    protected function corporateIdForTenant(): ?int
    {
        return $this->corporate->id;
    }
}
