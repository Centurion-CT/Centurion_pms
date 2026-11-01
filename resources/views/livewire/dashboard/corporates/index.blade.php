<div class="lp-dash">

    {{-- ── Hero: title + Owed side panel ──────────────────
         Right side surfaces the total outstanding balance
         across all corporates in amber warn tone, since it's
         the number that drives most operator action on this
         page. --}}
    @php $debtorCount = $rows->filter(fn ($r) => $r->outstanding > 0)->count(); @endphp
    <section class="lp-res-idx-hero" data-testid="corporates-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Partners</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Corporates
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Register a corporate to profile their staff for pre-authorized check-in, or view the ones you have done business with.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.corporates.create') }}" wire:navigate
                       class="lp-res-action lp-res-action--primary"
                       data-testid="new-corporate">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Register corporate
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Owed to us">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Owed to us</p>
                    <p class="lp-ih-hero-accrued-value {{ (int) $stats['outstanding'] > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                       data-testid="corporates-hero-owed">
                        {{ money((int) $stats['outstanding']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ((int) $stats['outstanding'] > 0)
                            across {{ $debtorCount }} {{ Str::plural('corp', $debtorCount) }}
                        @else
                            everyone paid up
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Stats pulse strip ────────────────────────────── --}}
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="corp-stats">
        <div class="lp-dash-card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
            <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                      background: var(--color-accent-deep);
                                      box-shadow: 0 0 0 3px rgba(93, 132, 44, 0.14);
                                      flex-shrink: 0;"></span>
            <div style="min-width: 0; flex: 1;">
                <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                    {{ number_format($stats['corporates']) }}
                </p>
                <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                   style="color: var(--color-ink-mute); margin-top: 2px;">
                    Corporates
                </p>
            </div>
        </div>
        <div class="lp-dash-card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
            <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                      background: #4B7BB0;
                                      box-shadow: 0 0 0 3px rgba(75, 123, 176, 0.14);
                                      flex-shrink: 0;"></span>
            <div style="min-width: 0; flex: 1;">
                <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                    {{ money($stats['gmv']) }}
                </p>
                <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                   style="color: var(--color-ink-mute); margin-top: 2px;">
                    All-time GMV
                </p>
            </div>
        </div>

        <button type="button"
                data-testid="corp-outstanding-card"
                @if ($stats['outstanding'] > 0) wire:click="openOutstandingPanel" @else disabled @endif
                class="lp-dash-card"
                style="padding: 14px 18px; text-align: left; cursor: {{ $stats['outstanding'] > 0 ? 'pointer' : 'default' }};
                       font: inherit; border: 1px solid var(--color-line-soft);
                       display: flex; align-items: center; gap: 14px;
                       {{ $stats['outstanding'] > 0 ? 'background: rgba(180, 83, 9, 0.05); border-color: rgba(180, 83, 9, 0.25);' : '' }}">
            <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                      background: {{ $stats['outstanding'] > 0 ? '#a36318' : 'var(--color-accent-deep)' }};
                                      box-shadow: 0 0 0 3px {{ $stats['outstanding'] > 0 ? 'rgba(163, 99, 24, 0.18)' : 'rgba(93, 132, 44, 0.14)' }};
                                      flex-shrink: 0;"></span>
            <div style="min-width: 0; flex: 1;">
                <p class="lp-num-lg text-[22px]" style="color: {{ $stats['outstanding'] > 0 ? '#a36318' : 'var(--color-ink)' }};">
                    {{ money($stats['outstanding']) }}
                </p>
                <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                   style="color: var(--color-ink-mute); margin-top: 2px;">
                    Owed to us
                    <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute);
                                 font-size: 11px; margin-left: 4px;">
                        @if ($stats['outstanding'] > 0)
                            across {{ $debtorCount }} {{ Str::plural('corp', $debtorCount) }} · tap for breakdown
                        @else
                            all clear
                        @endif
                    </span>
                </p>
            </div>
        </button>

        {{-- Debtor breakdown panel — right-side slide-out, teleported
             to <body> so the drawer isn't clipped by the stats grid. --}}
        @if ($outstandingPanelOpen)
            @php $debtors = $rows->filter(fn ($r) => $r->outstanding > 0)->values(); @endphp
            <template x-teleport="body">
            <div wire:key="corp-owed-panel-root" class="lp-page">
                <div x-transition.opacity.duration.150ms
                     wire:click="closeOutstandingPanel"
                     style="position: fixed; inset: 0; z-index: 199;
                            background: rgba(20, 17, 12, 0.28);"></div>
                <aside data-testid="corp-owed-panel"
                       role="dialog" aria-modal="true"
                       x-transition:enter="transition ease-out duration-200"
                       x-transition:enter-start="translate-x-full"
                       x-transition:enter-end="translate-x-0"
                       x-on:keydown.escape.window="$wire.closeOutstandingPanel()"
                       style="position: fixed; top: 0; right: 0; bottom: 0;
                              width: min(560px, 100vw); z-index: 200;
                              background: var(--color-card);
                              border-left: 1px solid var(--color-line);
                              box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                              display: flex; flex-direction: column;">
                    <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                        <div>
                            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                               style="color: var(--color-ink-mute);">
                                Corporates
                            </p>
                            <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                Who owes us
                            </h2>
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                {{ $debtors->count() }} corp{{ $debtors->count() === 1 ? '' : 's' }}
                                with a live balance, totalling
                                <strong style="color: #a36318;">{{ money($stats['outstanding']) }}</strong>.
                                Sums unpaid invoices + stays that haven't been billed yet.
                            </p>
                        </div>
                        <button type="button" wire:click="closeOutstandingPanel"
                                class="lp-btn" style="font-size: 12px;"
                                aria-label="Close panel">Close</button>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 12px 22px 22px;">
                        @if ($debtors->isEmpty())
                            <p class="text-[13.5px] text-center"
                               style="padding: 40px 0; color: var(--color-ink-mute);"
                               data-testid="no-debtors">
                                Everyone's paid up.
                            </p>
                        @else
                            <div style="display: grid; gap: 8px;">
                                @foreach ($debtors as $r)
                                    <a href="{{ $r->slug ? route('tenant.corporates.show', $r->slug) : '#' }}"
                                       @if ($r->slug) wire:navigate @endif
                                       data-testid="debtor-row-{{ $r->corporate_id }}"
                                       style="display: flex; align-items: center; justify-content: space-between;
                                              gap: 12px; padding: 12px 14px;
                                              background: var(--color-card); border: 1px solid var(--color-line-soft);
                                              border-radius: 10px; text-decoration: none;">
                                        <div style="min-width: 0; flex: 1;">
                                            <div style="color: var(--color-ink); font-weight: 500; font-size: 14px;">
                                                {{ $r->name }}
                                            </div>
                                            <div class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                                {{ $r->count }} booking{{ $r->count === 1 ? '' : 's' }}
                                                @if ($r->last_activity)
                                                    <span style="margin: 0 4px;">·</span>
                                                    last {{ $r->last_activity->diffForHumans(['parts' => 1]) }}
                                                @endif
                                            </div>
                                        </div>
                                        <div style="text-align: right; white-space: nowrap;">
                                            <div class="lp-num"
                                                 style="color: #a36318; font-weight: 600; font-size: 15px;">
                                                {{ money($r->outstanding) }}
                                            </div>
                                            <div class="lp-mono text-[10px] tracking-[0.14em] uppercase"
                                                 style="color: var(--color-ink-mute); margin-top: 2px;">
                                                Open →
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </aside>
            </div>
            </template>
        @endif
        <div class="lp-dash-card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
            <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                      background: #7A6EA7;
                                      box-shadow: 0 0 0 3px rgba(122, 110, 167, 0.14);
                                      flex-shrink: 0;"></span>
            <div style="min-width: 0; flex: 1;">
                <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                    {{ money($stats['last30d']) }}
                </p>
                <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                   style="color: var(--color-ink-mute); margin-top: 2px;">
                    Last 30 days
                    <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute);
                                 font-size: 11px; margin-left: 4px;">
                        rolling GMV
                    </span>
                </p>
            </div>
        </div>
    </div>

    <section class="lp-dash-card" style="padding: 14px 16px;">
        <form wire:submit.prevent>
            <label for="corp-search" class="lp-rate-sub-label">Search</label>
            <input id="corp-search" type="search"
                   wire:model.live.debounce.300ms="search"
                   class="lp-input" placeholder="Corporate name" data-testid="corp-search"
                   style="height: 36px;">
        </form>
    </section>

    @if ($rows->isEmpty())
        <section class="lp-dash-card" data-testid="empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'sparkle',
                'title' => 'No corporate bookings yet.',
                'sub'   => 'Turn on "Accept corporate bookings" in your property settings so your corporate clients can book at their negotiated rates.',
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">All corporates</span>
                <span class="lp-dash-card-meta">{{ number_format($rows->count()) }} {{ Str::plural('corp', $rows->count()) }}</span>
            </div>
            <ul class="lp-ledger" data-testid="corp-table">
                @foreach ($rows as $r)
                    @php
                        $isDebtor = $r->outstanding > 0;
                        $tone = $isDebtor ? 'warn' : ($r->last_activity && $r->last_activity->gt(now()->subMonths(2)) ? 'paid' : 'muted');
                        $corpHref = $r->slug ? route('tenant.corporates.show', $r->slug) : null;
                    @endphp
                    <li class="lp-ledger-item lp-category-row lp-channel-row"
                        data-testid="corp-row-{{ $r->corporate_id }}"
                        @if ($corpHref)
                            data-row-href="{{ $corpHref }}"
                            tabindex="0"
                            x-on:click="window.location.href = '{{ $corpHref }}'"
                            x-on:keydown.enter.prevent="window.location.href = '{{ $corpHref }}'"
                        @endif>
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <span class="lp-category-thumb lp-category-thumb--empty" aria-hidden style="cursor: default; border-style: solid;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V7a2 2 0 012-2h8a2 2 0 012 2v14M15 11h4a2 2 0 012 2v8M7 9h4M7 13h4M7 17h4"/></svg>
                        </span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                @if ($corpHref)
                                    <a href="{{ $corpHref }}" wire:navigate @click.stop
                                       class="lp-ledger-desc"
                                       style="font-weight: 600; color: var(--color-ink); text-decoration: none;">
                                        {{ $r->name }}
                                    </a>
                                @else
                                    <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                        {{ $r->name }}
                                    </span>
                                @endif
                                @if ($r->kyc_status)
                                    <span class="lp-category-type">KYC · {{ $r->kyc_status }}</span>
                                @endif
                                @if ($isDebtor)
                                    <span class="lp-tag" style="background: rgba(163, 99, 24, 0.14); color: #a36318; font-size: 9.5px;">
                                        Owes
                                    </span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                <span class="lp-mono">{{ $r->count }}</span>
                                {{ Str::plural('booking', (int) $r->count) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                <span class="lp-mono">
                                    {{ $r->count_by_kind['stay'] }}st
                                    · {{ $r->count_by_kind['desk'] }}d
                                    · {{ $r->count_by_kind['membership'] }}m
                                    · {{ $r->count_by_kind['lease'] }}l
                                </span>
                                <span style="color: var(--color-ink-mute);">·</span>
                                <span class="lp-mono">{{ $r->members_count }}</span>
                                {{ Str::plural('member', (int) $r->members_count) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ money($r->gmv_30d) }} last 30d
                                @if ($r->last_activity)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    last {{ $r->last_activity->diffForHumans() }}
                                @endif
                                <span data-testid="outstanding-{{ $r->corporate_id }}" hidden>
                                    {{ money($r->outstanding) }}
                                </span>
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total">{{ money($r->gmv) }}</span>
                            @if ($isDebtor)
                                <span class="lp-mono" style="color: #a36318; font-size: 11px; letter-spacing: 0.06em;">
                                    {{ money($r->outstanding) }} owed
                                </span>
                            @endif
                            @if ($corpHref)
                                <a href="{{ $corpHref }}" wire:navigate @click.stop
                                   data-testid="view-{{ $r->corporate_id }}"
                                   aria-label="Open {{ $r->name }}"
                                   class="lp-ledger-action">Open →</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
