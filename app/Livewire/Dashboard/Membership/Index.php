<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Membership;

use App\Enums\PaymentStatus;
use App\Models\MembershipBusiness;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Membership landing. Rolls up the KPIs an operator running gym /
 * spa / snooker subscriptions cares about at a glance and points at
 * expiring-soon so the front desk can prompt renewal at check-in.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public function render(): View
    {
        $now = Carbon::now();
        $soon = $now->copy()->addDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        $activeCount = MembershipSubscription::query()
            ->where('status', MembershipSubscription::STATUS_ACTIVE)
            ->where('expires_at', '>', $now)
            ->count();

        $expiringCount = MembershipSubscription::query()
            ->where('status', MembershipSubscription::STATUS_ACTIVE)
            ->whereBetween('expires_at', [$now, $soon])
            ->count();

        $mrr = (int) Payment::query()
            ->whereNotNull('membership_subscription_id')
            ->where('status', PaymentStatus::Succeeded)
            ->where('paid_at', '>=', $thirtyDaysAgo)
            ->sum('amount');

        $businessCount = MembershipBusiness::query()
            ->where('active', true)
            ->count();

        $expiringSoon = MembershipSubscription::query()
            ->with(['plan', 'business', 'customer'])
            ->where('status', MembershipSubscription::STATUS_ACTIVE)
            ->whereBetween('expires_at', [$now, $soon])
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

        $recentPayments = Payment::query()
            ->whereNotNull('membership_subscription_id')
            ->with([
                'membershipSubscription.customer',
                'membershipSubscription.plan',
                'membershipSubscription.business',
            ])
            ->orderByDesc('paid_at')
            ->limit(8)
            ->get();

        $businesses = MembershipBusiness::query()
            ->withCount([
                'subscriptions as active_subscriptions_count' => function ($q) use ($now): void {
                    $q->where('status', MembershipSubscription::STATUS_ACTIVE)
                      ->where('expires_at', '>', $now);
                },
                'plans as plan_count',
            ])
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard.membership.index', [
            'kpis' => [
                'active'    => $activeCount,
                'expiring'  => $expiringCount,
                'mrr'       => $mrr,
                'businesses'=> $businessCount,
            ],
            'expiringSoon'   => $expiringSoon,
            'recentPayments' => $recentPayments,
            'businesses'     => $businesses,
        ]);
    }
}
