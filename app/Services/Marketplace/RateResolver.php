<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\RateRule;
use App\Models\RoomCategory;
use App\Multitenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Resolves the effective per-night rate for a room category on the
 * public /stays surface, applying any operator-defined RateRule that
 * matches the guest's request context (device, referrer, wall-clock
 * time). Falls back to `category->base_rate` when nothing matches.
 *
 * Only used by the ONLINE booking surface (marketplace/stays/*).
 * Front-desk bookings continue to price against `base_rate` plus
 * their own discount rules — the dashboard's dedicated pricing
 * primitives (BookingCharge, BookingDiscount).
 */
final class RateResolver
{
    /**
     * Multi-night pricing. Iterates every night in [$checkin, $checkout)
     * (checkout is exclusive — you don't sleep the night you leave)
     * and resolves the effective rate per night against the same
     * request context. Returns the per-night breakdown so the caller
     * can render "3 nights, N45,000 total (was N60,000)" without
     * re-computing.
     *
     * @return array{
     *     nights: int,
     *     total: int,
     *     base_total: int,
     *     nightly: list<array{date: string, rate: int, base_rate: int, rule_id: int|null, rule_label: string|null}>,
     *     rule_ids: list<int>,
     * }
     */
    public function resolveStay(
        RoomCategory $category,
        Request $request,
        Carbon $checkin,
        Carbon $checkout,
    ): array {
        $baseRate = (int) $category->base_rate;
        $ci = $checkin->copy()->startOfDay();
        $co = $checkout->copy()->startOfDay();

        if ($co->lessThanOrEqualTo($ci)) {
            return [
                'nights'     => 0,
                'total'      => 0,
                'base_total' => 0,
                'nightly'    => [],
                'rule_ids'   => [],
            ];
        }

        $nightly = [];
        $total = 0;
        $baseTotal = 0;
        $ruleIds = [];

        for ($night = $ci->copy(); $night->lessThan($co); $night->addDay()) {
            $r = $this->resolve($category, $request, $night);
            $rule = $r['rule'];
            $nightly[] = [
                'date'       => $night->toDateString(),
                'rate'       => (int) $r['rate'],
                'base_rate'  => (int) $r['base_rate'],
                'rule_id'    => $rule?->id,
                'rule_label' => $rule?->label,
            ];
            $total += (int) $r['rate'];
            $baseTotal += (int) $r['base_rate'];
            if ($rule && ! in_array($rule->id, $ruleIds, true)) {
                $ruleIds[] = $rule->id;
            }
        }

        return [
            'nights'     => count($nightly),
            'total'      => $total,
            'base_total' => $baseTotal,
            'nightly'    => $nightly,
            'rule_ids'   => $ruleIds,
        ];
    }

    /**
     * @return array{
     *     rate: int,
     *     base_rate: int,
     *     rule: RateRule|null,
     * }
     */
    public function resolve(
        RoomCategory $category,
        Request $request,
        ?Carbon $nightlyDate = null,
    ): array {
        $baseRate = (int) $category->base_rate;
        $day = ($nightlyDate ?? Carbon::today())->copy()->startOfDay();

        $ctx = $this->contextFrom($request);

        // Rules on this category OR property-wide (room_category_id
        // null) that cover this calendar day. Query bypasses the
        // tenant scope so central-domain marketplace requests can
        // see rules belonging to the property's tenant.
        $rules = RateRule::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('property_id', $category->property_id)
            ->where(function ($q) use ($category): void {
                $q->whereNull('room_category_id')
                  ->orWhere('room_category_id', $category->id);
            })
            ->activeOn($day)
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule, $ctx)) {
                return [
                    'rate'      => (int) $rule->rate_ngn,
                    'base_rate' => $baseRate,
                    'rule'      => $rule,
                ];
            }
        }

        return [
            'rate'      => $baseRate,
            'base_rate' => $baseRate,
            'rule'      => null,
        ];
    }

    /**
     * @return array{device: string, referrer: string, now: Carbon}
     */
    private function contextFrom(Request $request): array
    {
        // First-touch cookie beats the live Referer header. Multi-branch
        // tenants hit the branch chooser first, which turns a Google
        // referral into an intra-site referral by the time we render
        // the actual detail page. CaptureFirstTouchReferrer middleware
        // stamps the original source on entry.
        $sticky = (string) $request->cookie('kv_ref', '');
        return [
            'device'   => $this->deviceFor((string) $request->userAgent()),
            'referrer' => $sticky !== ''
                ? $sticky
                : $this->referrerFor((string) $request->headers->get('referer', '')),
            'now'      => Carbon::now(),
        ];
    }

    /**
     * @param  array{device: string, referrer: string, now: Carbon}  $ctx
     */
    private function ruleMatches(RateRule $rule, array $ctx): bool
    {
        // Device gate — empty list = any.
        $devices = $rule->devices ?? [];
        if (! empty($devices) && ! in_array('any', $devices, true)
            && ! in_array($ctx['device'], $devices, true)) {
            return false;
        }

        // Referrer gate.
        $referrers = $rule->referrers ?? [];
        if (! empty($referrers) && ! in_array('any', $referrers, true)
            && ! in_array($ctx['referrer'], $referrers, true)) {
            return false;
        }

        // Wall-clock window — both null means whole day.
        if ($rule->time_start !== null || $rule->time_end !== null) {
            $now = $ctx['now']->format('H:i:s');
            $start = $rule->time_start ?? '00:00:00';
            $end   = $rule->time_end ?? '23:59:59';
            if ($start <= $end) {
                if ($now < $start || $now > $end) return false;
            } else {
                // Overnight window (e.g. 22:00 → 05:00): match if EITHER
                // after start OR before end.
                if ($now < $start && $now > $end) return false;
            }
        }

        return true;
    }

    public function deviceFor(string $userAgent): string
    {
        if ($userAgent === '') return 'desktop';
        $ua = strtolower($userAgent);
        // Same coarse split marketing analytics use — enough for pricing
        // segmentation. Tablets read as mobile per common convention.
        if (preg_match('/(android|iphone|ipad|ipod|mobile|blackberry|opera mini|iemobile)/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    public function referrerFor(string $referer): string
    {
        if ($referer === '') return 'direct';
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        return match (true) {
            str_contains($host, 'google.')     => 'google',
            str_contains($host, 'bing.')       => 'bing',
            str_contains($host, 'facebook.') || str_contains($host, 'fb.com')
                                               => 'facebook',
            str_contains($host, 'instagram.') => 'instagram',
            str_contains($host, 'wa.me') || str_contains($host, 'whatsapp.')
                                               => 'whatsapp',
            str_contains($host, 'twitter.') || str_contains($host, 'x.com')
                                               => 'twitter',
            $host === ''                       => 'direct',
            default                            => 'other',
        };
    }
}
