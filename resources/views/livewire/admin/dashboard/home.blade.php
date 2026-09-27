@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Platform</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Admin dashboard
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Platform-wide tenant overview across {{ number_format($counts['tenants_total']) }}
                {{ $counts['tenants_total'] === 1 ? 'account' : 'accounts' }}.
            </p>
        </div>

        <a href="{{ route('admin.tenants.index') }}" wire:navigate class="lp-pill" data-testid="open-tenants">
            Open tenants <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>

    {{-- ── Counters ──────────────────────────────────────────────── --}}
    <section data-testid="admin-counters" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Total tenants</span>
            <p data-testid="count-total"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($counts['tenants_total']) }}
            </p>
        </div>

        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Active · trial · grace</span>
            <p data-testid="count-active"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($counts['tenants_active']) }}
            </p>
            <span class="lp-tag lp-tag--confirmed" style="margin-top: 8px;">Live</span>
        </div>

        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Locked</span>
            <p data-testid="count-locked"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($counts['tenants_locked']) }}
            </p>
            <span class="lp-tag lp-tag--checked-in" style="margin-top: 8px;">Needs attention</span>
        </div>

        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Archived</span>
            <p data-testid="count-archived"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink-soft); font-weight: 600;">
                {{ number_format($counts['tenants_archived']) }}
            </p>
        </div>
    </section>

    {{-- ── Quick links ───────────────────────────────────────────── --}}
    <section class="grid gap-3 lg:grid-cols-2">
        <a href="{{ route('admin.tenants.index') }}" wire:navigate
           class="lp-dash-card" style="text-decoration: none; color: inherit; padding: 22px 24px;">
            <div class="lp-dash-card-head" style="margin-bottom: 8px;">
                <span class="lp-dash-card-title">Tenants</span>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">→</span>
            </div>
            <p class="text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.55;">
                Search, filter and inspect any tenant. Extend subscriptions, re-verify domains,
                or generate a 15-minute impersonation link.
            </p>
        </a>

        <a href="{{ route('admin.revenue') }}" wire:navigate
           class="lp-dash-card" style="text-decoration: none; color: inherit; padding: 22px 24px;">
            <div class="lp-dash-card-head" style="margin-bottom: 8px;">
                <span class="lp-dash-card-title">Revenue</span>
                <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">→</span>
            </div>
            <p class="text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.55;">
                ARR · MRR · churn · platform fees. Aggregated across all tenants on Paystack
                subscriptions and booking transactions.
            </p>
        </a>
    </section>
</div>
