<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\BookingStatus;
use App\Mail\Bookings\ReviewPromptMail;
use App\Models\Booking;
use App\Multitenancy\TenantScope;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Daily cron: mails CheckedOut guests 24 hours after their stay ended,
 * asking for a review (post-§19).
 *
 * Picks bookings where `checkout_date == yesterday`, status is CheckedOut,
 * `review_prompt_sent_at` is null, AND no review has been submitted yet.
 * Single-shot: stamping `review_prompt_sent_at` skips the row on every
 * subsequent run.
 *
 * Tenant scope is lifted intentionally — central cron scans across all
 * tenants in one pass.
 */
class SendReviewPrompts extends Command
{
    protected $signature = 'bookings:send-review-prompts';

    protected $description = 'Email CheckedOut guests 24h after their stay asking for a review';

    public function handle(GuestPortalTokenService $tokens): int
    {
        $yesterday = Carbon::yesterday()->toDateString();
        $sent = 0;

        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_status', BookingStatus::CheckedOut->value)
            ->whereDate('checkout_date', $yesterday)
            ->whereNull('review_prompt_sent_at')
            ->whereDoesntHave('review')
            ->cursor()
            ->each(function (Booking $b) use (&$sent, $tokens): void {
                // Mint a fresh portal token so the email link works even if
                // the original token from confirmation has expired.
                // GuestPortalTokenService::urlFor returns the show URL;
                // append /review for the deep link to the review form.
                $issued = $tokens->issue($b);
                $reviewUrl = 'https://'.$b->tenant->primaryHost()
                    .'/guest/booking/'.$b->booking_ref
                    .'/review?token='.$issued['plaintext'];

                Mail::to($b->guest_email)->queue(new ReviewPromptMail($b, $reviewUrl));
                $b->forceFill(['review_prompt_sent_at' => Carbon::now()])->save();
                $sent++;
            });

        $this->components->info("Review prompts sent: {$sent}");

        return self::SUCCESS;
    }
}
