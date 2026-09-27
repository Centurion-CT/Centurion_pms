<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Corporate;

use App\Enums\CorporateMemberStatus;
use App\Enums\WalletTransactionType;
use App\Models\Booking;
use App\Models\CorporateMember;
use App\Models\CorporateWalletTransaction;
use App\Models\DeskBooking;
use App\Models\Lease;
use App\Models\Membership;
use App\Multitenancy\TenantScope;
use App\Services\Corporates\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.corporate-portal')]
class Dashboard extends Component
{
    public function render(WalletService $wallets): View
    {
        $member = auth('corporate')->user();
        $corporate = $member->corporate;

        $wallet = $wallets->ensureWallet($corporate);

        // Last-30d spend window, the most meaningful "what's flowing"
        // signal for a corporate admin.
        $windowStart = Carbon::now()->subDays(30)->startOfDay();

        $spend30 = (int) abs((int) CorporateWalletTransaction::query()
            ->where('corporate_id', $corporate->id)
            ->whereIn('type', [WalletTransactionType::Spend->value])
            ->where('created_at', '>=', $windowStart)
            ->sum('amount_naira'));

        $activeMembers = CorporateMember::query()
            ->where('corporate_id', $corporate->id)
            ->where('status', CorporateMemberStatus::Active->value)
            ->count();

        // Across all 4 subject types, bookings, desk bookings,
        // memberships, leases that ran on this corporate's tab. Lift
        // tenant scope so we see every property the company has used.
        $bookingsCount = (int) Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('corporate_id', $corporate->id)
            ->count();

        $deskCount = (int) DeskBooking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('corporate_id', $corporate->id)
            ->count();

        $membershipsCount = (int) Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('corporate_id', $corporate->id)
            ->count();

        $leasesCount = (int) Lease::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('corporate_id', $corporate->id)
            ->count();

        // Latest 10 wallet movements for the activity feed.
        $recentTransactions = CorporateWalletTransaction::query()
            ->where('corporate_id', $corporate->id)
            ->latest('id')
            ->limit(10)
            ->get();

        return view('livewire.portal.corporate.dashboard', [
            'member'             => $member,
            'corporate'          => $corporate,
            'wallet'             => $wallet,
            'spend30'            => $spend30,
            'activeMembers'      => $activeMembers,
            'bookingsCount'      => $bookingsCount,
            'deskCount'          => $deskCount,
            'membershipsCount'   => $membershipsCount,
            'leasesCount'        => $leasesCount,
            'recentTransactions' => $recentTransactions,
            'lowBalance'         => $wallet->low_balance_threshold_naira !== null
                && $wallet->available() < $wallet->low_balance_threshold_naira,
        ]);
    }
}
