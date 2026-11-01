<?php

declare(strict_types=1);

namespace App\Mail\Corporates;

use App\Mail\Corporates\Concerns\BindsCorporateTenant;
use App\Models\Corporate;
use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent to corporate admins when a membership auto-renewal hits an
 * empty wallet and the membership is paused.
 */
class MembershipPausedMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use BindsCorporateTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Membership $membership,
        public Corporate $corporate,
        public int $shortfall,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Membership paused, top up to resume',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.corporates.membership-paused',
            with: [
                'membership' => $this->membership,
                'corporate'  => $this->corporate,
                'shortfall'  => $this->shortfall,
                'member'     => $this->membership->corporateMember,
                'walletUrl' => $this->walletUrl(),
            ],
        );
    }

    protected function corporateIdForTenant(): ?int
    {
        return $this->corporate->id;
    }
}
