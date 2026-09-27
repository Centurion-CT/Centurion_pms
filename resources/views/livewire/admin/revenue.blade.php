<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Finance</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Revenue
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Platform-wide subscription, churn and transaction-fee performance.
        </p>
    </div>

    {{-- ── Subscriptions ─────────────────────────────────────────── --}}
    <section data-testid="revenue-subscriptions" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Subscriptions</span>
            <span class="lp-dash-card-meta">Recurring revenue from operator plans</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Active subscriptions</span>
                <p data-testid="active-subscriptions"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    {{ number_format($active_subscriptions) }}
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">ARR</span>
                <p data-testid="arr-ngn"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    ₦{{ number_format($arr_ngn) }}
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">MRR (ARR ÷ 12)</span>
                <p data-testid="mrr-ngn"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    ₦{{ number_format($mrr_ngn) }}
                </p>
            </div>
        </div>
    </section>

    {{-- ── Churn ──────────────────────────────────────────────────── --}}
    <section data-testid="revenue-churn" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Churn</span>
            <span class="lp-dash-card-meta">Trailing {{ $churn_window_days }} days</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Churned subscriptions</span>
                <p data-testid="churned-count"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    {{ number_format($churned_count) }}
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Churn rate</span>
                <p data-testid="churn-pct"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    {{ $churn_pct }}%
                </p>
                @if ($churn_pct > 0)
                    <span class="lp-tag lp-tag--checked-in" style="margin-top: 8px;">Watch</span>
                @else
                    <span class="lp-tag lp-tag--confirmed" style="margin-top: 8px;">No churn</span>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Transaction fees ──────────────────────────────────────── --}}
    <section data-testid="revenue-fees" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Transaction fees</span>
            <span class="lp-dash-card-meta">Trailing {{ $churn_window_days }} days</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Keverna fee collected</span>
                <p data-testid="keverna-fees"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    ₦{{ number_format($keverna_fees_window_ngn) }}
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Paystack fee collected</span>
                <p data-testid="paystack-fees"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink-soft); font-weight: 600;">
                    ₦{{ number_format($paystack_fees_window_ngn) }}
                </p>
            </div>
        </div>
    </section>

    {{-- ── Keverna Stays marketplace ──────────────────────────────
         Verifies our 5% is landing on marketplace bookings + shows
         the flow: guest GMV -> Keverna's cut -> Paystack's cut ->
         operator payout. Numbers scoped to bookings whose
         source_note is "Keverna Stays marketplace" (stamped by
         CreateStaysBooking on every marketplace booking). Empty
         window shows all zeros - not an error state; just no
         marketplace bookings settled yet.
    --}}
    <section data-testid="revenue-stays" class="lp-dash-card"
             style="margin-top: 16px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">
                Keverna Stays marketplace
                <span class="lp-mono text-[10px] tracking-[0.14em] uppercase"
                      style="color: var(--color-accent-deep); margin-left: 8px;">
                    {{ rtrim(rtrim(number_format((float) config('keverna.pricing.stays_fee_percent_default', 2.5), 1), '0'), '.') }}% platform fee
                </span>
            </span>
            <span class="lp-dash-card-meta">Trailing {{ $churn_window_days }} days</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Bookings settled</span>
                <p data-testid="stays-bookings-count"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    {{ number_format($stays_bookings_count) }}
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">GMV</span>
                <p data-testid="stays-gmv"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    ₦{{ number_format($stays_gmv_window_ngn) }}
                </p>
                <p style="font-size: 11px; color: var(--color-ink-mute); margin-top: 2px;">
                    What guests paid at checkout, before splits.
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-accent-deep);">Keverna cut</span>
                <p data-testid="stays-keverna-fees"
                   class="lp-display mt-2 text-[26px]"
                   style="color: var(--color-accent-deep); font-weight: 700;">
                    ₦{{ number_format($stays_keverna_fees_window_ngn) }}
                </p>
                <p style="font-size: 11px; color: var(--color-ink-mute); margin-top: 2px;">
                    {{ rtrim(rtrim(number_format((float) config('keverna.pricing.stays_fee_percent_default', 2.5), 1), '0'), '.') }}% of subtotal on every marketplace booking.
                </p>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2" style="margin-top: 20px;">
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Paystack processing</span>
                <p data-testid="stays-paystack-fees"
                   class="lp-display mt-2 text-[22px]"
                   style="color: var(--color-ink-soft); font-weight: 600;">
                    ₦{{ number_format($stays_paystack_fees_window_ngn) }}
                </p>
            </div>
            <div>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                      style="color: var(--color-ink-mute);">Operator payouts</span>
                <p data-testid="stays-operator-payouts"
                   class="lp-display mt-2 text-[22px]"
                   style="color: var(--color-ink); font-weight: 600;">
                    ₦{{ number_format($stays_operator_payouts_window_ngn) }}
                </p>
                <p style="font-size: 11px; color: var(--color-ink-mute); margin-top: 2px;">
                    Reached the operator's Paystack subaccount.
                </p>
            </div>
        </div>

        @if ($stays_bookings_count === 0)
            <p style="font-size: 12px; color: var(--color-ink-mute); margin-top: 16px;
                      padding: 10px 12px; background: rgba(20,23,26,0.03); border-radius: 8px;">
                No Keverna Stays bookings settled in this window yet. The rollup lights up
                on the first successful marketplace payment.
            </p>
        @endif
    </section>
</div>
