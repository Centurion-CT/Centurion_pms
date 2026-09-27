<?php

declare(strict_types=1);

namespace App\Services\Corporates;

use App\Enums\WalletTransactionType;
use App\Models\Booking;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\CorporateWalletTransaction;
use App\Models\DeskBooking;
use App\Models\Lease;
use App\Models\Membership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Orchestrates the corporate side of every bookable's lifecycle.
 *
 * Supports four subject types: Booking (short-stay), DeskBooking
 * (hourly/day pass workspace), Membership (workspace subscription),
 * Lease (residential annual contract).
 *
 *   - attach*       at create time   → resolve corporate, apply rate,
 *                                       enforce caps, place wallet hold,
 *                                       stamp corporate_id columns
 *   - capture*      at confirm time  → convert hold to spend
 *   - release*      at cancel time   → release uncaptured hold
 *   - refund*       at cancel time   → refund captured spend
 *
 * The release/refund pair is mutually exclusive — call release first;
 * if it returns null (already captured), call refund.
 *
 * Wallet outflows for cap enforcement are summed by member, regardless
 * of which subject type they came from — a busy member spending across
 * stays + day passes still hits their daily cap.
 */
class CorporateBookingService
{
    public function __construct(
        private readonly CorporateResolver $resolver,
        private readonly CorporatePricingResolver $pricing,
        private readonly WalletService $wallets,
        private readonly CorporateNotifier $notifier,
    ) {}

    // ── Booking (short stay) ───────────────────────────────────────

    public function attachToBooking(Booking $booking, bool $deferWalletHold = false): ?CorporateWalletTransaction
    {
        // Email first — that's the most authoritative match (verified
        // domain + explicit member email). If nothing lands, fall back
        // to matching the guest's phone against corporate_members.phone
        // so a walk-in with only a phone in hand still auto-links.
        $resolved = $this->resolver->resolve($booking->guest_email)
            ?? $this->resolver->resolveByPhone($booking->guest_phone);
        if ($resolved === null) {
            return null;
        }
        [$corporate, $member] = [$resolved['corporate'], $resolved['member']];

        $property = $booking->room?->property;
        if ($property === null || ! (bool) $property->accepts_corporates) {
            return null;
        }

        $corporateDiscount = 0;
        $netDue = (int) $booking->total_amount;
        if ($booking->room !== null) {
            // Pass nights so the resolver can honour LoS-gated rates
            // (rate.min_nights) — a "10% off if the stay is 3+ nights"
            // tier only applies to eligible stays.
            $rate = $this->pricing->forRoom($corporate, $booking->room, (int) $booking->nights);
            if ($rate !== null) {
                $corporateDiscount = max(0, (int) $rate['off_per_unit'] * (int) $booking->nights);
                $netDue = max(0, $netDue - $corporateDiscount);
            }
        }

        // "Bill by invoice later" corps typically haven't pre-funded
        // the wallet — they settle after their staff have travelled.
        // Skip the wallet hold in that case so a zero-balance wallet
        // doesn't throw an insufficient-funds RuntimeException that
        // rolls the whole booking transaction back.
        $hold = $deferWalletHold
            ? null
            : $this->holdAndStamp(
                corporate: $corporate,
                member: $member,
                subject: $booking,
                ref: (string) $booking->booking_ref,
                netDue: $netDue,
            );

        $booking->forceFill([
            'corporate_id'              => $corporate->id,
            'corporate_member_id'       => $member?->id,
            'corporate_discount_naira'  => $corporateDiscount,
            'total_amount'              => $netDue,
            'balance_due'               => max(0, $netDue - (int) $booking->deposit_amount),
        ])->save();

        return $hold;
    }

    public function captureFor(Booking $booking): ?CorporateWalletTransaction
    {
        $tx = $this->capture($booking, (string) $booking->booking_ref);

        // Fire the corporate-side confirmation only when this capture
        // actually moved money — null means "no-op" (no hold, or
        // already captured), and we don't want to re-confirm an old
        // booking on a duplicate trigger.
        if ($tx !== null) {
            $this->notifier->bookingConfirmed($booking->refresh());
        }

        return $tx;
    }

    public function releaseFor(Booking $booking): ?CorporateWalletTransaction
    {
        return $this->release($booking, (string) $booking->booking_ref);
    }

    public function refundFor(Booking $booking, ?int $amount = null): ?CorporateWalletTransaction
    {
        return $this->refund($booking, (string) $booking->booking_ref, $amount);
    }

    // ── DeskBooking (hourly / day pass) ────────────────────────────

    /**
     * Attribute a desk booking to a corporate member. Called from the
     * operator manual-create form when the operator picks a corporate
     * + member; the form passes the resolved pair in directly so the
     * service doesn't re-resolve.
     */
    public function attachToDeskBooking(
        DeskBooking $booking,
        Corporate $corporate,
        ?CorporateMember $member = null,
    ): ?CorporateWalletTransaction {
        $property = $booking->workspace?->property;
        if ($property === null || ! (bool) $property->accepts_corporates) {
            return null;
        }

        $corporateDiscount = 0;
        $netDue = (int) $booking->amount_naira;
        if ($booking->workspace !== null) {
            $rate = $this->pricing->forWorkspace($corporate, $booking->workspace, $netDue);
            if ($rate !== null) {
                $corporateDiscount = (int) $rate['off_per_unit'];
                $netDue = (int) $rate['unit_price'];
            }
        }

        $hold = $this->holdAndStamp(
            corporate: $corporate,
            member: $member,
            subject: $booking,
            ref: (string) $booking->reference,
            netDue: $netDue,
        );

        $booking->forceFill([
            'corporate_id'              => $corporate->id,
            'corporate_member_id'       => $member?->id,
            'corporate_discount_naira'  => $corporateDiscount,
            'amount_naira'              => $netDue,
        ])->save();

        return $hold;
    }

    public function captureForDeskBooking(DeskBooking $booking): ?CorporateWalletTransaction
    {
        return $this->capture($booking, (string) $booking->reference);
    }

    public function releaseForDeskBooking(DeskBooking $booking): ?CorporateWalletTransaction
    {
        return $this->release($booking, (string) $booking->reference);
    }

    public function refundForDeskBooking(DeskBooking $booking, ?int $amount = null): ?CorporateWalletTransaction
    {
        return $this->refund($booking, (string) $booking->reference, $amount);
    }

    // ── Membership (workspace subscription) ────────────────────────

    /**
     * Hold the first period's price (whatever the plan's interval is)
     * so the corporate is on the hook for at least one month/year of
     * service. Recurring re-bills land in Phase 4.
     */
    public function attachToMembership(
        Membership $membership,
        Corporate $corporate,
        ?CorporateMember $member = null,
    ): ?CorporateWalletTransaction {
        $property = $membership->workspace?->property
            ?? $membership->plan?->workspace?->property;
        if ($property === null || ! (bool) $property->accepts_corporates) {
            return null;
        }

        $corporateDiscount = 0;
        $netDue = (int) $membership->amount_naira;
        if ($membership->workspace !== null) {
            $rate = $this->pricing->forWorkspace($corporate, $membership->workspace, $netDue);
            if ($rate !== null) {
                $corporateDiscount = (int) $rate['off_per_unit'];
                $netDue = (int) $rate['unit_price'];
            }
        }

        $hold = $this->holdAndStamp(
            corporate: $corporate,
            member: $member,
            subject: $membership,
            ref: 'membership-'.$membership->id,
            netDue: $netDue,
        );

        $membership->forceFill([
            'corporate_id'              => $corporate->id,
            'corporate_member_id'       => $member?->id,
            'corporate_discount_naira'  => $corporateDiscount,
            'amount_naira'              => $netDue,
        ])->save();

        return $hold;
    }

    public function captureForMembership(Membership $membership): ?CorporateWalletTransaction
    {
        return $this->capture($membership, 'membership-'.$membership->id);
    }

    public function releaseForMembership(Membership $membership): ?CorporateWalletTransaction
    {
        return $this->release($membership, 'membership-'.$membership->id);
    }

    public function refundForMembership(Membership $membership, ?int $amount = null): ?CorporateWalletTransaction
    {
        return $this->refund($membership, 'membership-'.$membership->id, $amount);
    }

    // ── Lease (residential annual) ─────────────────────────────────

    /**
     * Hold the first year's annual rent + deposit + service charge so
     * the corporate's pre-funded wallet covers move-in. Subsequent
     * annual renewals can re-hold from the same wallet in Phase 4.
     */
    public function attachToLease(
        Lease $lease,
        Corporate $corporate,
        ?CorporateMember $member = null,
    ): ?CorporateWalletTransaction {
        $property = $lease->unit?->property;
        if ($property === null || ! (bool) $property->accepts_corporates) {
            return null;
        }

        $corporateDiscount = 0;
        $effectiveAnnual = (int) $lease->annual_rent_naira;
        if ($lease->unit !== null) {
            $rate = $this->pricing->forUnit($corporate, $lease->unit);
            if ($rate !== null) {
                $corporateDiscount = (int) $rate['off_per_unit'];
                $effectiveAnnual = (int) $rate['unit_price'];
            }
        }

        // First-year hold = (negotiated) annual rent + deposit + service charge.
        $firstYearTotal = $effectiveAnnual
            + (int) $lease->deposit_naira
            + (int) $lease->service_charge_naira;

        $hold = $this->holdAndStamp(
            corporate: $corporate,
            member: $member,
            subject: $lease,
            ref: (string) $lease->reference,
            netDue: $firstYearTotal,
        );

        $lease->forceFill([
            'corporate_id'              => $corporate->id,
            'corporate_member_id'       => $member?->id,
            'corporate_discount_naira'  => $corporateDiscount,
            'annual_rent_naira'         => $effectiveAnnual,
        ])->save();

        return $hold;
    }

    public function captureForLease(Lease $lease): ?CorporateWalletTransaction
    {
        return $this->capture($lease, (string) $lease->reference);
    }

    public function releaseForLease(Lease $lease): ?CorporateWalletTransaction
    {
        return $this->release($lease, (string) $lease->reference);
    }

    public function refundForLease(Lease $lease, ?int $amount = null): ?CorporateWalletTransaction
    {
        return $this->refund($lease, (string) $lease->reference, $amount);
    }

    // ── Internal helpers ───────────────────────────────────────────

    /**
     * Common path: enforce caps, place wallet hold, attach causer.
     * Caller does the subject-specific column stamping (deposit
     * recalcs, status flags) since those vary.
     */
    private function holdAndStamp(
        Corporate $corporate,
        ?CorporateMember $member,
        Model $subject,
        string $ref,
        int $netDue,
    ): CorporateWalletTransaction {
        if ($member !== null) {
            $this->enforceCaps($member, $netDue);
        }

        return $this->wallets->hold(
            corporate: $corporate,
            amount: $netDue,
            description: 'Hold for '.$this->subjectLabel($subject).' '.$ref,
            meta: $this->subjectMeta($subject, $ref),
            causer: $member,
            member: $member,
        );
    }

    private function capture(Model $subject, string $ref): ?CorporateWalletTransaction
    {
        $corporateId = $this->corporateIdFor($subject);
        if ($corporateId === null) {
            return null;
        }

        $hold = $this->latestTransactionForSubject($subject, WalletTransactionType::Hold);
        if ($hold === null) {
            return null;
        }
        if ($this->hasTransaction($subject, WalletTransactionType::Spend)) {
            return null;
        }

        $corporate = $this->corporateFor($subject);
        if ($corporate === null) {
            return null;
        }

        return $this->wallets->spend(
            corporate: $corporate,
            amount: (int) abs($hold->amount_naira),
            description: 'Spend for '.$this->subjectLabel($subject).' '.$ref,
            meta: $this->subjectMeta($subject, $ref),
            member: $this->memberFor($subject),
        );
    }

    private function release(Model $subject, string $ref): ?CorporateWalletTransaction
    {
        if ($this->corporateIdFor($subject) === null) {
            return null;
        }

        $hold = $this->latestTransactionForSubject($subject, WalletTransactionType::Hold);
        if ($hold === null) {
            return null;
        }
        if ($this->hasTransaction($subject, WalletTransactionType::Spend)) {
            return null;
        }
        if ($this->hasTransaction($subject, WalletTransactionType::Release)) {
            return null;
        }

        $corporate = $this->corporateFor($subject);
        if ($corporate === null) {
            return null;
        }

        return $this->wallets->release(
            corporate: $corporate,
            amount: (int) abs($hold->amount_naira),
            description: 'Released hold — '.$this->subjectLabel($subject).' '.$ref.' cancelled',
            meta: $this->subjectMeta($subject, $ref),
            member: $this->memberFor($subject),
        );
    }

    private function refund(Model $subject, string $ref, ?int $amount): ?CorporateWalletTransaction
    {
        if ($this->corporateIdFor($subject) === null) {
            return null;
        }

        $spend = $this->latestTransactionForSubject($subject, WalletTransactionType::Spend);
        if ($spend === null) {
            return null;
        }

        $refundAmount = $amount ?? (int) abs($spend->amount_naira);
        $refundAmount = min($refundAmount, (int) abs($spend->amount_naira));
        if ($refundAmount <= 0) {
            return null;
        }

        $corporate = $this->corporateFor($subject);
        if ($corporate === null) {
            return null;
        }

        return $this->wallets->refund(
            corporate: $corporate,
            amount: $refundAmount,
            description: 'Refund for cancelled '.$this->subjectLabel($subject).' '.$ref,
            meta: $this->subjectMeta($subject, $ref),
            member: $this->memberFor($subject),
        );
    }

    private function enforceCaps(CorporateMember $member, int $proposedAmount): void
    {
        if ($member->daily_cap_naira !== null) {
            $todaySpend = $this->memberOutflowSince($member, Carbon::today()->startOfDay());
            if (($todaySpend + $proposedAmount) > (int) $member->daily_cap_naira) {
                $remaining = max(0, (int) $member->daily_cap_naira - $todaySpend);
                throw new RuntimeException(
                    'This booking would exceed your daily corporate cap. '
                    .'Remaining today: ₦'.number_format($remaining).'.'
                );
            }
        }

        if ($member->monthly_cap_naira !== null) {
            $monthSpend = $this->memberOutflowSince($member, Carbon::now()->startOfMonth());
            if (($monthSpend + $proposedAmount) > (int) $member->monthly_cap_naira) {
                $remaining = max(0, (int) $member->monthly_cap_naira - $monthSpend);
                throw new RuntimeException(
                    'This booking would exceed your monthly corporate cap. '
                    .'Remaining this month: ₦'.number_format($remaining).'.'
                );
            }
        }
    }

    private function memberOutflowSince(CorporateMember $member, Carbon $since): int
    {
        return (int) abs((int) CorporateWalletTransaction::query()
            ->where('corporate_member_id', $member->id)
            ->whereIn('type', [
                WalletTransactionType::Hold->value,
                WalletTransactionType::Spend->value,
            ])
            ->where('created_at', '>=', $since)
            ->sum('amount_naira'));
    }

    /**
     * Find the most recent ledger entry of a given type that points at
     * this subject. We match on both the subject's class and id stored
     * in `meta` so e.g. Booking #5 and DeskBooking #5 don't collide.
     */
    private function latestTransactionForSubject(
        Model $subject,
        WalletTransactionType $type,
    ): ?CorporateWalletTransaction {
        return CorporateWalletTransaction::query()
            ->where('corporate_id', $this->corporateIdFor($subject))
            ->where('type', $type->value)
            ->whereJsonContains('meta->subject_id', $subject->getKey())
            ->whereJsonContains('meta->subject_type', $subject::class)
            ->latest('id')
            ->first();
    }

    private function hasTransaction(Model $subject, WalletTransactionType $type): bool
    {
        return $this->latestTransactionForSubject($subject, $type) !== null;
    }

    private function corporateIdFor(Model $subject): ?int
    {
        $id = $subject->getAttribute('corporate_id');

        return $id === null ? null : (int) $id;
    }

    private function corporateFor(Model $subject): ?Corporate
    {
        $id = $this->corporateIdFor($subject);
        if ($id === null) {
            return null;
        }

        return Corporate::query()->find($id);
    }

    private function memberFor(Model $subject): ?CorporateMember
    {
        $id = $subject->getAttribute('corporate_member_id');
        if ($id === null) {
            return null;
        }

        return CorporateMember::query()->find((int) $id);
    }

    /**
     * @return array{subject_type:string, subject_id:int, ref:string}
     */
    private function subjectMeta(Model $subject, string $ref): array
    {
        return [
            'subject_type' => $subject::class,
            'subject_id'   => (int) $subject->getKey(),
            'ref'          => $ref,
        ];
    }

    private function subjectLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof Booking     => 'booking',
            $subject instanceof DeskBooking => 'desk booking',
            $subject instanceof Membership  => 'membership',
            $subject instanceof Lease       => 'lease',
            default                         => 'item',
        };
    }
}
