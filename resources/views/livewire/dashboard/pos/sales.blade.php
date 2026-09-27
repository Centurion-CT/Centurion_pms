@php
    // Cash summary for the filtered page — helps a cashier and their
    // supervisor see at a glance what came through the till in the
    // current view without opening every row.
    $viewTotal = (int) $sales->getCollection()->where('status.value', 'completed')->sum('total');
    $viewVoided = $sales->getCollection()->where('status.value', 'voided')->count();
    $viewCount = (int) $sales->getCollection()->where('status.value', 'completed')->count();
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    {{-- ── Hero: title + in-view completed total ────────────
         Right side shows how much came through the till on
         the page being viewed. Voided rows are surfaced in the
         sub so the reader sees why a big-looking day might be
         smaller in reality. --}}
    <section class="lp-res-idx-hero mt-3" data-testid="sales-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">POS · Ledger</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Recent sales
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Every till receipt across your outlets. Filter by outlet, status, or search a reference or guest name.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.pos.pick-outlet') }}" wire:navigate
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 4h12v8H2z"/><path d="M6 4v8M10 4v8"/></svg>
                        Open terminal
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Completed sales in view">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">In view</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $viewTotal > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="sales-hero-total">
                        ₦{{ number_format($viewTotal) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ number_format($viewCount) }} completed
                        @if ($viewVoided > 0)
                            · {{ $viewVoided }} voided
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Filters ──────────────────────────────────────── --}}
    <section class="lp-dash-card lp-cal-search" style="padding: 12px 14px;">
        <div class="lp-cal-search-row">
            <div class="lp-cal-search-field lp-cal-search-field--room">
                <label for="sales-search" class="lp-rate-sub-label">Search</label>
                <input id="sales-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       data-testid="sales-search" class="lp-input"
                       style="height: 36px;"
                       placeholder="Reference or guest name">
            </div>
            <div class="lp-cal-search-field">
                <label for="sales-outlet" class="lp-rate-sub-label">Outlet</label>
                <select id="sales-outlet" wire:model.live="outletFilter"
                        data-testid="sales-outlet-filter"
                        class="lp-input" style="height: 36px;">
                    <option value="">All outlets</option>
                    @foreach ($availableOutlets as $o)
                        <option value="{{ $o->id }}">{{ $o->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lp-cal-search-field">
                <label for="sales-status" class="lp-rate-sub-label">Status</label>
                <select id="sales-status" wire:model.live="statusFilter"
                        data-testid="sales-status-filter"
                        class="lp-input" style="height: 36px;">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    {{-- ── Sales list ──────────────────────────────────── --}}
    @if ($sales->isEmpty())
        <section class="lp-dash-card" data-testid="sales-empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'wallet',
                'title' => 'No sales for this filter.',
                'sub'   => 'Widen the outlet or status filter, or clear the search to see everything.',
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Recent sales</span>
                <span class="lp-dash-card-meta">
                    {{ $sales->firstItem() ? number_format($sales->firstItem()).'–'.number_format($sales->lastItem()) : '0' }}
                    of {{ number_format($sales->total()) }}
                </span>
            </div>

            <ul class="lp-ledger" data-testid="sales-table">
                @foreach ($sales as $s)
                    @php
                        $statusVal = $s->status->value;
                        $isComp = (bool) ($s->is_complimentary ?? false);
                        $tone = match (true) {
                            $isComp && $statusVal === 'completed' => 'info',
                            $statusVal === 'completed' => 'paid',
                            $statusVal === 'voided'    => 'warn',
                            $statusVal === 'draft'     => 'info',
                            default                     => 'muted',
                        };
                        $isVoided = $statusVal === 'voided';
                        $saleHref = route('tenant.pos.sales.show', $s);
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="sale-row-{{ $s->reference }}"
                        data-row-href="{{ $saleHref }}"
                        tabindex="0"
                        x-on:click="$event.target.closest('a,button') || (window.location.href = '{{ $saleHref }}')"
                        x-on:keydown.enter.prevent="window.location.href = '{{ $saleHref }}'">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ $saleHref }}" wire:navigate @click.stop
                                   class="lp-mono lp-invoice-number"
                                   aria-label="Open sale {{ $s->reference }}">
                                    {{ $s->reference }}
                                </a>
                                <span class="lp-category-type">{{ $s->outlet?->name ?? '-' }}</span>
                                <span class="lp-tag {{ $statusVal === 'completed' ? 'lp-tag--confirmed' : ($isVoided ? 'lp-tag--cancelled' : 'lp-tag--pending') }}">
                                    {{ $s->status->label() }}
                                </span>
                                @if ($isComp)
                                    {{-- Complimentary tag with a subtle green tint.
                                         Attribution + reason surface in the meta
                                         line below. --}}
                                    <span class="lp-tag" style="background: rgba(93, 132, 44, 0.14); color: var(--color-accent-deep); border: 1px solid rgba(93, 132, 44, 0.35);">
                                        🎁 Comp
                                    </span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                {{ optional($s->sold_at ?? $s->created_at)->format('M j · g:i A') ?? '-' }}
                                @if ($s->booking)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: var(--color-ink);">{{ $s->booking->guest_name }}</span>
                                    <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px;">
                                        ({{ $s->booking->booking_ref }} · charge-to-room)
                                    </span>
                                @elseif ($s->customer)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: var(--color-ink);">{{ $s->customer->name }}</span>
                                    @if ($s->customer->phone)
                                        <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px;">
                                            ({{ $s->customer->phone }})
                                        </span>
                                    @endif
                                @elseif ($s->customer_name_snapshot)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: var(--color-ink);">{{ $s->customer_name_snapshot }}</span>
                                    @if ($s->customer_phone_snapshot)
                                        <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px;">
                                            ({{ $s->customer_phone_snapshot }})
                                        </span>
                                    @endif
                                @else
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: var(--color-ink-mute);">Walk-in</span>
                                @endif
                                @if ($s->operator?->name)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $s->operator->name }}
                                @endif
                                @if ($isComp)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: var(--color-accent-deep);">
                                        comp by {{ $s->complimentaryBy?->name ?? $s->operator?->name ?? 'operator' }}
                                    </span>
                                    @if ($s->complimentary_reason)
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        <em style="color: var(--color-ink-soft);">"{{ $s->complimentary_reason }}"</em>
                                    @endif
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            @if ($isComp)
                                <span class="lp-invoice-total" style="color: var(--color-accent-deep);">
                                    ₦0
                                </span>
                                <span class="lp-mono" style="font-size: 9.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                                    comp value ₦{{ number_format($s->lines->sum('subtotal') ?? 0) }}
                                </span>
                            @else
                                <span class="lp-invoice-total"
                                      style="color: {{ $isVoided ? 'var(--color-ink-mute)' : 'var(--color-ink)' }};
                                             {{ $isVoided ? 'text-decoration: line-through;' : '' }}">
                                    ₦{{ number_format($s->total) }}
                                </span>
                            @endif
                            <a href="{{ $saleHref }}" wire:navigate @click.stop
                               data-testid="sale-open-{{ $s->id }}"
                               class="lp-ledger-action">Open →</a>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($sales->hasPages())
                <div class="mt-4">{{ $sales->links() }}</div>
            @endif
        </section>
    @endif
</div>
