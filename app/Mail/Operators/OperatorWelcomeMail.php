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
 * First-touch welcome email after an operator finishes registration
 * (PRD §7.1). Includes their tenant subdomain URL and the next-steps
 * checklist they'll see on their dashboard.
 */
class OperatorWelcomeMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Operator $operator,
        public Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to '.config('brand.name').': let\'s get your booking engine live');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.operators.welcome', with: [
            'operator' => $this->operator,
            'tenant' => $this->tenant,
            'dashboardUrl' => 'https://'.$this->tenant->primaryHost().'/dashboard',
        ]);
    }
}
