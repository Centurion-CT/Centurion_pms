@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + Members primary CTA ────────────────
         Identity block on the left; right side hosts the MRR
         (30-day collection) as a Fraunces figure so the operator
         reads recurring-revenue health at a glance. --}}
    <section class="lp-res-idx-hero" data-testid="membership-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Membership
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Gym, spa, snooker, or any recurring service that lives beside the rooms. Create businesses and plans, sign customers up, take payment, track history.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.membership.members') }}" wire:navigate
                       class="lp-res-action lp-res-action--primary"
                       data-testid="membership-manage-members">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="5.5" r="2.5"/><path d="M3 14c0-2.5 2.2-4.5 5-4.5s5 2 5 4.5"/></svg>
                        Members
                    </a>
                    <a href="{{ route('tenant.membership.plans') }}" wire:navigate
                       class="lp-res-action"
                       data-testid="membership-manage-plans">
                        Plans
                    </a>
                    <a href="{{ route('tenant.membership.businesses') }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost"
                       data-testid="membership-manage-businesses">
                        Businesses
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="30-day collection">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Collected · 30 days</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $kpis['mrr'] > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="membership-hero-mrr">
                        ₦{{ number_format((int) $kpis['mrr']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ number_format((int) $kpis['active']) }} active
                        @if ((int) $kpis['expiring'] > 0)
                            · {{ (int) $kpis['expiring'] }} expiring within 7 days
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── KPIs ─────────────────────────────────────────────────── --}}
    <div class="grid gap-3 md:grid-cols-4" data-testid="membership-kpis">
        <div class="lp-dash-card" style="padding: 14px 16px;">
            <div class="text-[11.5px] uppercase tracking-wider" style="color: var(--color-ink-mute);">Active members</div>
            <div class="mt-1 text-[26px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($kpis['active']) }}</div>
        </div>
        <div class="lp-dash-card" style="padding: 14px 16px;">
            <div class="text-[11.5px] uppercase tracking-wider" style="color: var(--color-ink-mute);">Expiring in 7 days</div>
            <div class="mt-1 text-[26px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($kpis['expiring']) }}</div>
        </div>
        <div class="lp-dash-card" style="padding: 14px 16px;">
            <div class="text-[11.5px] uppercase tracking-wider" style="color: var(--color-ink-mute);">Collected · 30 days</div>
            <div class="mt-1 text-[22px] lp-num" style="color: var(--color-ink); font-weight: 600;">
                &#8358;{{ number_format($kpis['mrr']) }}
            </div>
        </div>
        <div class="lp-dash-card" style="padding: 14px 16px;">
            <div class="text-[11.5px] uppercase tracking-wider" style="color: var(--color-ink-mute);">Active businesses</div>
            <div class="mt-1 text-[26px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($kpis['businesses']) }}</div>
        </div>
    </div>

    {{-- ── Businesses overview ──────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Businesses</span>
            <a href="{{ route('tenant.membership.businesses') }}" wire:navigate
               class="text-[12.5px]"
               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                Manage
            </a>
        </div>
        @if ($businesses->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No businesses yet. Create your first (gym, spa, snooker, pool, ...) to start signing members up.
            </p>
        @else
            <div class="grid gap-2 md:grid-cols-2 lg:grid-cols-3" data-testid="membership-business-grid">
                @foreach ($businesses as $b)
                    <div class="border rounded-lg" style="border-color: var(--color-line-soft); padding: 12px 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: start; gap: 8px;">
                            <div>
                                <div class="text-[14.5px]" style="color: var(--color-ink); font-weight: 600;">
                                    {{ $b->name }}
                                </div>
                                <div class="text-[11.5px] mt-0.5" style="color: var(--color-ink-mute);">
                                    {{ $b->plan_count }} plan{{ $b->plan_count === 1 ? '' : 's' }}
                                    <span style="margin: 0 4px;">·</span>
                                    {{ $b->active_subscriptions_count }} active
                                </div>
                            </div>
                            @if (! $b->active)
                                <span class="lp-tag lp-tag--on-hold" style="font-size: 10px;">Inactive</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ── Expiring soon ────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Expiring in the next 7 days</span>
        </div>
        @if ($expiringSoon->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                Nothing expiring soon.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="expiring-soon-table">
                    <thead>
                    <tr>
                        <th>Member</th>
                        <th>Business · Plan</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($expiringSoon as $s)
                        <tr>
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $s->customer?->name ?? '-' }}
                                <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                    {{ $s->member_ref }}
                                </div>
                            </td>
                            <td>
                                <div>{{ $s->business?->name ?? '-' }}</div>
                                <div class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    {{ $s->plan?->name ?? '-' }}
                                </div>
                            </td>
                            <td>
                                {{ $s->expires_at?->toFormattedDateString() }}
                                <div class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    in {{ $s->daysRemaining() }} day{{ $s->daysRemaining() === 1 ? '' : 's' }}
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <a href="{{ route('tenant.membership.show', $s->member_ref) }}"
                                   wire:navigate class="lp-btn" style="font-size: 12px;">
                                    Open &rarr;
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ── Recent payments ──────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Recent payments</span>
        </div>
        @if ($recentPayments->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No payments recorded yet.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="recent-payments-table">
                    <thead>
                    <tr>
                        <th>Member</th>
                        <th>Business · Plan</th>
                        <th>Kind</th>
                        <th style="text-align: right;">Amount</th>
                        <th>When</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($recentPayments as $p)
                        <tr>
                            <td>{{ $p->membershipSubscription?->customer?->name ?? '-' }}</td>
                            <td>
                                <div>{{ $p->membershipSubscription?->business?->name ?? '-' }}</div>
                                <div class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    {{ $p->membershipSubscription?->plan?->name ?? '-' }}
                                </div>
                            </td>
                            <td>
                                <span class="lp-tag" style="font-size: 10px;">
                                    {{ ucfirst((string) $p->membership_kind) }}
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <span class="lp-num">&#8358;{{ number_format($p->amount) }}</span>
                            </td>
                            <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                {{ $p->paid_at?->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

</div>
