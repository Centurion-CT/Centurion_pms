<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Services\Discounts\DiscountEngine;

/**
 * Discount/coupon UX for any operator- or guest-facing reservation
 * form.
 *
 * Two flows funnel through here:
 *
 *   1. **Auto-apply**, host calls `refreshDiscount()` from render().
 *      We scan {@see DiscountEngine::bestAutoApply()} every render so
 *      the displayed savings always reflect the current room/dates.
 *
 *   2. **Manual code**, operator/guest types a code and hits the
 *      Apply button. `applyDiscount()` runs
 *      {@see DiscountEngine::resolveByCode()}. A rejected code surfaces
 *      its specific reason ("Discount expired on …", "Capped at 3
 *      nights", "Not valid on this room") inline.
 *
 * The applied discount's id + amount-off live in trait state. When the
 * booking is saved, the host passes
 * {@see HasDiscountLookup::discountSubmissionArgs()} into the
 * CreateBookingRequest, the action re-resolves server-side inside the
 * booking transaction for a final cap-safe commit.
 *
 * Hosts must implement {@see HasDiscountLookup::discountContext()} so
 * the trait knows which room, dates, channel and net-subtotal to
 * evaluate against. Returning null means "not enough info yet" -
 * dates not picked, room not chosen, and the trait quietly clears
 * any prior application.
 */
trait HasDiscountLookup
{
    public string $discountCode = '';

    public ?int $appliedDiscountId = null;

    public ?string $appliedDiscountName = null;

    public ?string $appliedDiscountSummary = null;   // "10% off" / "₦5,000 off"

    public ?int $appliedDiscountAmountOff = null;

    public ?string $appliedDiscountCodeUsed = null;  // null when auto-applied

    public bool $discountAutoApplied = false;

    public ?string $discountError = null;

    /**
     * Operator/guest clicked Apply. Resolves via the engine; sets
     * either applied state or an error reason.
     */
    public function applyDiscount(): void
    {
        $this->discountError = null;

        $code = trim($this->discountCode);
        if ($code === '') {
            $this->discountError = 'Type a coupon code first.';

            return;
        }

        $ctx = $this->discountContext();
        if ($ctx === null) {
            $this->discountError = 'Pick a room and check dates first.';

            return;
        }

        /** @var DiscountEngine $engine */
        $engine = app(DiscountEngine::class);
        $resolution = $engine->resolveByCode(
            code: $code,
            room: $ctx['room'],
            checkin: $ctx['checkin'],
            checkout: $ctx['checkout'],
            channel: $ctx['channel'],
            netSubtotal: $ctx['netSubtotal'],
        );

        if ($resolution === null) {
            $this->clearAppliedDiscount();
            $this->discountError = 'No discount found for that code.';

            return;
        }

        if (! $resolution->applies) {
            $this->clearAppliedDiscount();
            $this->discountError = $resolution->reason ?? 'Discount cannot be applied here.';

            return;
        }

        $this->setAppliedFromResolution($resolution, autoApplied: false);
    }

    /**
     * Drop any applied discount AND the typed code. Resets the form
     * to "no discount" until the operator picks one again.
     */
    public function clearDiscount(): void
    {
        $this->discountCode = '';
        $this->clearAppliedDiscount();
    }

    /**
     * Called from render(). Keeps the displayed savings in sync with
     * the current context, re-validates a manual code (clears if it
     * no longer applies), or finds the best codeless rule when no
     * manual code is set.
     */
    public function refreshDiscount(): void
    {
        $ctx = $this->discountContext();
        if ($ctx === null) {
            return;
        }

        /** @var DiscountEngine $engine */
        $engine = app(DiscountEngine::class);

        // Manual code was previously applied, re-verify against
        // current dates/room so a stale resolution can't slip through.
        if ($this->appliedDiscountId !== null && ! $this->discountAutoApplied && $this->appliedDiscountCodeUsed !== null) {
            $resolution = $engine->resolveByCode(
                code: $this->appliedDiscountCodeUsed,
                room: $ctx['room'],
                checkin: $ctx['checkin'],
                checkout: $ctx['checkout'],
                channel: $ctx['channel'],
                netSubtotal: $ctx['netSubtotal'],
            );

            if ($resolution === null || ! $resolution->applies) {
                $this->clearAppliedDiscount();
                $this->discountError = $resolution?->reason ?? 'Discount no longer applies for the current selection.';

                return;
            }

            // Just refresh the amount in case netSubtotal moved (e.g.
            // length-of-stay change).
            $this->appliedDiscountAmountOff = $resolution->amountOff;

            return;
        }

        // No manual code → try auto-apply.
        $resolution = $engine->bestAutoApply(
            room: $ctx['room'],
            checkin: $ctx['checkin'],
            checkout: $ctx['checkout'],
            channel: $ctx['channel'],
            netSubtotal: $ctx['netSubtotal'],
        );

        if ($resolution === null) {
            // Previous auto-apply no longer matches → clear it.
            if ($this->discountAutoApplied) {
                $this->clearAppliedDiscount();
            }

            return;
        }

        $this->setAppliedFromResolution($resolution, autoApplied: true);
    }

    /**
     * Arguments for the host to spread into CreateBookingRequest.
     *
     *   - Manual code applied  → pass the code, suppress auto-apply
     *   - Auto code applied    → no explicit code, let action re-scan
     *   - Nothing applied      → no code, let action re-scan
     *
     * @return array{discountCode: ?string, autoApplyDiscounts: bool}
     */
    public function discountSubmissionArgs(): array
    {
        if ($this->appliedDiscountCodeUsed !== null && $this->appliedDiscountId !== null) {
            return ['discountCode' => $this->appliedDiscountCodeUsed, 'autoApplyDiscounts' => false];
        }

        return ['discountCode' => null, 'autoApplyDiscounts' => true];
    }

    private function clearAppliedDiscount(): void
    {
        $this->appliedDiscountId = null;
        $this->appliedDiscountName = null;
        $this->appliedDiscountSummary = null;
        $this->appliedDiscountAmountOff = null;
        $this->appliedDiscountCodeUsed = null;
        $this->discountAutoApplied = false;
    }

    private function setAppliedFromResolution(\App\Services\Discounts\DiscountResolution $resolution, bool $autoApplied): void
    {
        $discount = $resolution->discount;
        if ($discount === null) {
            return;
        }

        $this->appliedDiscountId = $discount->id;
        $this->appliedDiscountName = $discount->name;
        $this->appliedDiscountSummary = $discount->discountSummary();
        $this->appliedDiscountAmountOff = $resolution->amountOff;
        $this->appliedDiscountCodeUsed = $autoApplied ? null : $resolution->code;
        $this->discountAutoApplied = $autoApplied;
        $this->discountError = null;
    }

    /**
     * Hosts must implement and return either:
     *   - array{room: Room, checkin: Carbon, checkout: Carbon,
     *           channel: BookingSource, netSubtotal: int}
     *   - null when not enough info to evaluate
     *
     * @return array{room: \App\Models\Room, checkin: \Illuminate\Support\Carbon, checkout: \Illuminate\Support\Carbon, channel: \App\Enums\BookingSource, netSubtotal: int}|null
     */
    abstract protected function discountContext(): ?array;
}
