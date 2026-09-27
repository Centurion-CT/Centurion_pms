<?php

declare(strict_types=1);

namespace App\Services\Corporates;

use App\Enums\CorporateMemberRole;
use App\Enums\CorporateMemberStatus;
use App\Mail\Corporates\CorporateBookingConfirmedMail;
use App\Mail\Corporates\LowBalanceAlertMail;
use App\Mail\Corporates\MembershipPausedMail;
use App\Mail\Corporates\TopupSucceededMail;
use App\Models\Booking;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\CorporateWallet;
use App\Models\CorporateWalletTopup;
use App\Models\Membership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Centralises which recipient set gets each corporate-side mail.
 *
 * Admin emails go to every Active member with role=Admin on the
 * corporate (so a corp with multiple billing-owners all see top-ups +
 * low-balance alerts). Booking confirmations go to the booking member
 * (or the booker email when the corporate matched by domain without a
 * specific member) — with admins CC'd.
 *
 * Falls back to the corporate's `billing_email` (or `primary_email`)
 * when no admin members exist yet — covers the brand-new corporate
 * who's just been onboarded but hasn't been given a portal admin yet.
 */
class CorporateNotifier
{
    public function topupSucceeded(CorporateWalletTopup $topup): void
    {
        $corporate = $topup->corporate;
        if ($corporate === null) {
            return;
        }
        $recipients = $this->adminRecipients($corporate);
        if ($recipients->isEmpty()) {
            return;
        }
        Mail::to($recipients->all())->queue(new TopupSucceededMail($topup));
    }

    public function lowBalance(Corporate $corporate, CorporateWallet $wallet): void
    {
        $recipients = $this->adminRecipients($corporate);
        if ($recipients->isEmpty()) {
            return;
        }
        Mail::to($recipients->all())->queue(new LowBalanceAlertMail($corporate, $wallet));
    }

    public function membershipPaused(Membership $membership, Corporate $corporate, int $shortfall): void
    {
        $recipients = $this->adminRecipients($corporate);
        if ($recipients->isEmpty()) {
            return;
        }
        Mail::to($recipients->all())->queue(new MembershipPausedMail($membership, $corporate, $shortfall));
    }

    public function bookingConfirmed(Booking $booking): void
    {
        if ($booking->corporate_id === null) {
            return;
        }

        $corporate = $booking->corporate;
        if ($corporate === null) {
            return;
        }

        // Primary recipient is the booking member; falls back to the
        // booker's email when the corporate matched by domain alone.
        $primary = $booking->corporateMember?->email
            ?? ($booking->guest_email !== '' ? $booking->guest_email : null);
        if ($primary === null) {
            return;
        }

        $mail = Mail::to($primary);

        // CC every corporate admin. Skip if the primary is itself an
        // admin (don't double-send).
        $admins = $this->adminRecipients($corporate)
            ->reject(fn (string $email) => strcasecmp($email, $primary) === 0)
            ->values();

        if ($admins->isNotEmpty()) {
            $mail->cc($admins->all());
        }

        $mail->queue(new CorporateBookingConfirmedMail($booking));
    }

    /**
     * @return Collection<int, string>
     */
    private function adminRecipients(Corporate $corporate): Collection
    {
        $emails = CorporateMember::query()
            ->where('corporate_id', $corporate->id)
            ->where('role', CorporateMemberRole::Admin->value)
            ->where('status', CorporateMemberStatus::Active->value)
            ->pluck('email')
            ->filter()
            ->values();

        if ($emails->isNotEmpty()) {
            return $emails;
        }

        $fallback = $corporate->billing_email ?: $corporate->primary_email;

        return collect(array_filter([$fallback]));
    }
}
