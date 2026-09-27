<?php

declare(strict_types=1);

namespace App\Mail\Approvals;

use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\Operator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Sent to every Manager on the tenant when a front-desk operator
 * requests to check a guest in without collecting payment upfront.
 * Points the manager back to the Approvals module so they can review
 * the full reservation + approve or reject.
 */
class CheckinApprovalRequestedMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Booking $booking,
        public GuestActionApproval $approval,
        public Operator $requester,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Approval needed: check-in without payment · %s',
                $this->booking->guest_name,
            ),
        );
    }

    public function content(): Content
    {
        // Build the approval URL against the tenant's primary host
        // explicitly. route('tenant.approvals.index') on its own runs
        // through the app's default host (central marketplace) when
        // the mailable renders on a queue worker with no tenant
        // bound — the recipient then lands on "property not found".
        $tenant = $this->booking->tenant;
        $host   = $tenant?->primaryHost();
        $approvalUrl = $host
            ? 'https://'.$host.'/dashboard/approvals'
            : route('tenant.approvals.index');

        return new Content(
            markdown: 'mail.approvals.checkin-requested',
            with: [
                'guestName'     => (string) $this->booking->guest_name,
                'bookingRef'    => (string) $this->booking->booking_ref,
                'totalNaira'    => (int) $this->booking->total_amount,
                'roomLabel'     => $this->booking->room?->room_number
                    ? 'Room '.$this->booking->room->room_number
                    : 'Unassigned',
                'checkin'       => $this->booking->checkin_date?->toFormattedDateString(),
                'checkout'      => $this->booking->checkout_date?->toFormattedDateString(),
                'nights'        => (int) $this->booking->nights,
                // Deliberately named `requesterName` (not `requester`)
                // so a future refactor that passes the Operator model
                // can't silently render as a JSON blob in the mail
                // body. Fall back to a generic label if the operator
                // row has an empty name.
                'requesterName' => (string) ($this->requester?->name ?: 'A front-desk operator'),
                'approvalUrl'   => $approvalUrl,
            ],
        );
    }
}
