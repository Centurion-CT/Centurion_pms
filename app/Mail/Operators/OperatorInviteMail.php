<?php

declare(strict_types=1);

namespace App\Mail\Operators;

use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent when a manager invites a new staff member from Staff / Team.
 *
 * The email carries a single call-to-action: a signed 24-hour magic
 * link that takes the invitee to /invite/activate to pick a password.
 * Until they do, the operator account is not activated — there is no
 * temp-password fallback because there is no login without activation.
 *
 * `magicLink` is a fully-qualified signed URL that the caller
 * generates via URL::temporarySignedRoute() with a 24h expiry. We
 * pass it in as a string so the queue worker doesn't have to
 * re-derive tenant context to build it.
 */
class OperatorInviteMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Operator $operator,
        public Tenant $tenant,
        public string $inviterName,
        public string $roleLabel,
        public string $magicLink,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->inviterName.' invited you to '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.operators.invite', with: [
            'operator' => $this->operator,
            'tenant' => $this->tenant,
            'inviterName' => $this->inviterName,
            'roleLabel' => $this->roleLabel,
            'magicLink' => $this->magicLink,
        ]);
    }
}
