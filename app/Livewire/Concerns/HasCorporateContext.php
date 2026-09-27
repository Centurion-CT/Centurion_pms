<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\WalletTransactionType;
use App\Models\CorporateWalletTransaction;
use App\Models\Room;
use App\Services\Corporates\CorporatePricingResolver;
use App\Services\Corporates\CorporateResolver;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;

/**
 * Adds live corporate detection to a public booking-form Livewire
 * component.
 *
 * The host component must expose `string $guest_email` and a method
 * {@see roomForCorporateContext()} that returns the {@see Room} the
 * guest is about to book. The trait then provides a `corporateContext`
 * computed property, null when no corporate matches, otherwise an
 * object carrying:
 *
 *   - corporate            \App\Models\Corporate
 *   - member               ?\App\Models\CorporateMember
 *   - propertyAccepts      bool, has the property toggled accepts_corporates
 *   - rate                 ?array, pricing override from CorporatePricingResolver
 *   - totalSaving          int, projected saving on the guest's basket
 *   - capWarning           ?string, soft message if the member's cap will be exceeded
 *
 * Views render a banner off this; the booking action layer
 * (CreateBooking->attachToBooking) re-derives the same context
 * server-side for the actual hold, so the trait is purely UX -
 * skipping or spoofing it doesn't bypass the authoritative server
 * check.
 */
trait HasCorporateContext
{
    /**
     * @return array{
     *   corporate:        \App\Models\Corporate,
     *   member:           ?\App\Models\CorporateMember,
     *   propertyAccepts:  bool,
     *   rate:             ?array,
     *   totalSaving:      int,
     *   capWarning:       ?string,
     * }|null
     */
    #[Computed]
    public function corporateContext(): ?array
    {
        $email = trim((string) ($this->guest_email ?? ''));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $resolved = app(CorporateResolver::class)->resolve($email);
        if ($resolved === null) {
            return null;
        }

        $corporate = $resolved['corporate'];
        $member = $resolved['member'];

        $room = $this->roomForCorporateContext();
        if ($room === null) {
            // Email matches a corporate, but we don't yet have a room
            // (the guest hasn't picked one). Still surface the
            // identity so the banner can confirm "we recognise you".
            return [
                'corporate'       => $corporate,
                'member'          => $member,
                'propertyAccepts' => false,
                'rate'            => null,
                'totalSaving'     => 0,
                'capWarning'      => null,
            ];
        }

        $propertyAccepts = (bool) ($room->property?->accepts_corporates);
        $rate = null;
        $totalSaving = 0;

        if ($propertyAccepts) {
            $rate = app(CorporatePricingResolver::class)->forRoom($corporate, $room);
            if ($rate !== null) {
                $totalSaving = (int) $rate['off_per_unit'] * max(1, $this->corporateContextNights());
            }
        }

        $capWarning = null;
        if ($member !== null) {
            $projected = $this->corporateProjectedAmount($rate);
            if ($projected > 0) {
                $capWarning = $this->checkCap($member, $projected);
            }
        }

        return [
            'corporate'       => $corporate,
            'member'          => $member,
            'propertyAccepts' => $propertyAccepts,
            'rate'            => $rate,
            'totalSaving'     => $totalSaving,
            'capWarning'      => $capWarning,
        ];
    }

    /**
     * Host-provided number of nights for the current price preview.
     * Default tries to compute from $checkin/$checkout if present.
     */
    protected function corporateContextNights(): int
    {
        if (! property_exists($this, 'checkin') || ! property_exists($this, 'checkout')) {
            return 1;
        }
        $in = (string) $this->checkin;
        $out = (string) $this->checkout;
        if ($in === '' || $out === '') {
            return 1;
        }
        try {
            $a = Carbon::parse($in);
            $b = Carbon::parse($out);

            return max(1, (int) $a->diffInDays($b, absolute: true));
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Host-provided estimate of what this booking would cost the
     * corporate wallet (post negotiated rate). Used solely to evaluate
     * the cap warning. Hosts that have a full PricingBreakdown handy
     * can override.
     */
    protected function corporateProjectedAmount(?array $rate): int
    {
        $room = $this->roomForCorporateContext();
        if ($room === null) {
            return 0;
        }

        $unit = $rate !== null ? (int) $rate['unit_price'] : (int) $room->base_rate;

        return $unit * $this->corporateContextNights();
    }

    private function checkCap(\App\Models\CorporateMember $member, int $projected): ?string
    {
        if ($member->daily_cap_naira !== null) {
            $todaySpend = $this->memberOutflowSince($member, Carbon::today()->startOfDay());
            if (($todaySpend + $projected) > (int) $member->daily_cap_naira) {
                $remaining = max(0, (int) $member->daily_cap_naira - $todaySpend);

                return 'This booking would exceed your daily corporate cap (remaining: ₦'
                    .number_format($remaining).'). Try a smaller booking or contact your corporate admin.';
            }
        }

        if ($member->monthly_cap_naira !== null) {
            $monthSpend = $this->memberOutflowSince($member, Carbon::now()->startOfMonth());
            if (($monthSpend + $projected) > (int) $member->monthly_cap_naira) {
                $remaining = max(0, (int) $member->monthly_cap_naira - $monthSpend);

                return 'This booking would exceed your monthly corporate cap (remaining: ₦'
                    .number_format($remaining).').';
            }
        }

        return null;
    }

    private function memberOutflowSince(\App\Models\CorporateMember $member, Carbon $since): int
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
     * Hosts MUST implement this, returns the Room (or null) the
     * guest is about to book.
     */
    abstract protected function roomForCorporateContext(): ?Room;
}
