<?php

declare(strict_types=1);

namespace App\Mail\Reports;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Emails a Summary PDF report to a subscriber. Attaches the PDF bytes
 * directly so the sender can generate them once per tenant per period
 * and reuse across all subscribers.
 */
class SummaryReportMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $periodLabel,
        public string $periodRange,
        public string $pdfBytes,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->tenant->name.': '.$this->periodLabel.' summary · '.$this->periodRange,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.reports.summary-report',
            with: [
                'tenant' => $this->tenant,
                'periodLabel' => $this->periodLabel,
                'periodRange' => $this->periodRange,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
