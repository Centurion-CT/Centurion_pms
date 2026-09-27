@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Overview</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            {{ $corporate->name }}
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Welcome back, {{ $member->name }}. Here's how your team is using Keverna.
        </p>
    </div>

    @if ($lowBalance)
        <p role="status" data-testid="low-balance-warn" class="lp-status-banner"
           style="background: rgba(140,30,30,0.06); color: #8c1e1e; border-color: rgba(140,30,30,0.18);">
            Wallet balance is below the threshold you set. Top up to keep your team booking.
        </p>
    @endif

    {{-- ── Counters ──────────────────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Wallet balance</span>
            <p data-testid="wallet-balance" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($wallet->balance_naira) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                ₦{{ number_format($wallet->held_naira) }} held · ₦{{ number_format($wallet->available()) }} available
            </span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Spend last 30d</span>
            <p data-testid="spend-30" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($spend30) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Active members</span>
            <p data-testid="active-members" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($activeMembers) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Bookings total</span>
            <p data-testid="bookings-total" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($bookingsCount + $deskCount + $membershipsCount + $leasesCount) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                {{ $bookingsCount }}st · {{ $deskCount }}d · {{ $membershipsCount }}m · {{ $leasesCount }}l
            </span>
        </div>
    </div>

    {{-- ── Quick links ───────────────────────────────────────────── --}}
    <section class="grid gap-3 lg:grid-cols-3">
        <a href="{{ route('corporate.bookings.index') }}" wire:navigate
           class="lp-dash-card" style="text-decoration: none; color: inherit; padding: 22px 24px;">
            <div class="lp-dash-card-head" style="margin-bottom: 8px;">
                <span class="lp-dash-card-title">Bookings</span>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">→</span>
            </div>
            <p class="text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.55;">
                Every stay, desk session, membership and lease across your team.
            </p>
        </a>
        @if ($member->role->value === 'admin')
            <a href="{{ route('corporate.members.index') }}" wire:navigate
               class="lp-dash-card" style="text-decoration: none; color: inherit; padding: 22px 24px;">
                <div class="lp-dash-card-head" style="margin-bottom: 8px;">
                    <span class="lp-dash-card-title">Members</span>
                    <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">→</span>
                </div>
                <p class="text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.55;">
                    Invite people, set daily/monthly caps, suspend offboarded staff.
                </p>
            </a>
            <a href="{{ route('corporate.wallet') }}" wire:navigate
               class="lp-dash-card" style="text-decoration: none; color: inherit; padding: 22px 24px;">
                <div class="lp-dash-card-head" style="margin-bottom: 8px;">
                    <span class="lp-dash-card-title">Wallet</span>
                    <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">→</span>
                </div>
                <p class="text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.55;">
                    Top up, view every hold + spend + refund. Set low-balance alerts.
                </p>
            </a>
        @endif
    </section>

    {{-- ── Recent activity ───────────────────────────────────────── --}}
    @if ($recentTransactions->isNotEmpty())
        <section class="lp-dash-card" style="padding: 0;" data-testid="recent-activity">
            <div class="lp-dash-card-head" style="padding: 20px 24px;">
                <span class="lp-dash-card-title">Recent wallet activity</span>
                <span class="lp-dash-card-meta">Latest 10</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance after</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentTransactions as $tx)
                            @php
                                $tag = match($tx->type->value) {
                                    'topup', 'refund', 'release' => 'lp-tag--confirmed',
                                    'adjustment'                 => 'lp-tag--checked-in',
                                    default                      => 'lp-tag--checked-out',
                                };
                            @endphp
                            <tr>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; white-space: nowrap;">
                                    {{ $tx->created_at?->format('d M · g:i A') }}
                                </td>
                                <td><span class="lp-tag {{ $tag }}">{{ $tx->type->label() }}</span></td>
                                <td style="color: {{ $tx->amount_naira >= 0 ? 'var(--color-ink)' : '#8c1e1e' }}; font-weight: 500; white-space: nowrap;">
                                    {{ $tx->amount_naira >= 0 ? '+' : '' }}₦{{ number_format($tx->amount_naira) }}
                                </td>
                                <td style="color: var(--color-ink); white-space: nowrap;">₦{{ number_format($tx->balance_after_naira) }}</td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $tx->description ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
