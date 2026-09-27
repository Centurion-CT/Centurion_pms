<div class="lp-dash">

    {{-- ── Hero: title + held-in-till side panel ──────────
         Right side surfaces the amount of cash still held in
         the till against cancelled or no-show bookings, in
         Fraunces amber when > 0. --}}
    <section class="lp-res-idx-hero" data-testid="refunds-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Refunds
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Guests who paid but whose reservation was cancelled or marked no-show, and no refund was recorded. Issue the refund from the reservation, or record the amount as a forfeit so the ledger stops carrying it.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ $exportUrl }}"
                       data-testid="refunds-export"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M8 2v9M4 7l4 4 4-4M2 13h12"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Held in till">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Held in till</p>
                    <p class="lp-ih-hero-accrued-value {{ (int) $kpis['held_total'] > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                       data-testid="refunds-hero-held">
                        ₦{{ number_format((int) $kpis['held_total']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ((int) $kpis['count'] > 0)
                            across {{ number_format((int) $kpis['count']) }}
                            {{ Str::plural('booking', (int) $kpis['count']) }}
                        @else
                            nothing unresolved
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── KPI strip — dot-tile pulse ───────────────────── --}}
    @php
        $refundTiles = [
            [
                'label' => 'Unresolved',
                'value' => number_format((int) $kpis['count']),
                'sub'   => 'bookings with cash still held',
                'dot'   => (int) $kpis['count'] > 0 ? '#A8650A' : 'var(--color-accent-deep)',
                'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Held in till',
                'value' => '₦'.number_format((int) $kpis['held_total']),
                'sub'   => 'either refund or record as forfeit',
                'dot'   => (int) $kpis['held_total'] > 0 ? '#A8650A' : 'var(--color-accent-deep)',
                'color' => (int) $kpis['held_total'] > 0 ? '#A8650A' : 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-2" data-testid="refunds-kpis">
        @foreach ($refundTiles as $tile)
            <div class="lp-dash-card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        {{ $tile['value'] }}
                    </p>
                    <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $tile['label'] }}
                        <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute);
                                     font-size: 11px; margin-left: 4px;">
                            {{ $tile['sub'] }}
                        </span>
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Filters ──────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="refunds-filters" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 12px;">
            <span class="lp-dash-card-title">Filter</span>
            <button type="button" wire:click="resetFilters"
                    class="lp-mono text-[11.5px]" data-testid="refunds-reset"
                    style="color: var(--color-accent-deep); background: transparent; border: 0;
                           cursor: pointer; text-decoration: underline; text-underline-offset: 3px;">
                Reset
            </button>
        </div>

        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-4">
            <div>
                <label for="refunds-search" class="lp-form-label">Search</label>
                <input id="refunds-search" type="search"
                       wire:model.live.debounce.400ms="search"
                       placeholder="Ref, name, phone, email"
                       data-testid="refunds-search" class="lp-input">
            </div>
            <div>
                <label for="refunds-status" class="lp-form-label">Status</label>
                <select id="refunds-status" wire:model.live="statusFilter"
                        data-testid="refunds-status" class="lp-input">
                    <option value="">Cancelled + no-show</option>
                    <option value="cancelled">Cancelled only</option>
                    <option value="no_show">No-show only</option>
                </select>
            </div>
            <div>
                <label for="refunds-from" class="lp-form-label">From (updated)</label>
                <input id="refunds-from" type="date"
                       wire:model.live="dateFrom"
                       data-testid="refunds-from" class="lp-input">
            </div>
            <div>
                <label for="refunds-to" class="lp-form-label">To (updated)</label>
                <input id="refunds-to" type="date"
                       wire:model.live="dateTo"
                       data-testid="refunds-to" class="lp-input">
            </div>
        </form>
    </section>

    {{-- Client-side detail panel — click a row to see full
         reservation summary + cancellation trail + payment
         history. Payload pre-hydrated so opening is instant. --}}
    @include('livewire.dashboard.refunds._panel', ['panels' => $panels])

    {{-- ── Refunds list ─────────────────────────────────── --}}
    @if ($bookings->isEmpty())
        <section class="lp-dash-card" data-testid="refunds-empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'sparkle',
                'title' => 'Nothing pending.',
                'sub'   => 'Every cancelled or no-show booking has either been refunded or has no held cash. Widen the date range if you\'re auditing further back.',
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Pending refunds</span>
                <span class="lp-dash-card-meta">
                    {{ $bookings->firstItem() ? number_format($bookings->firstItem()).'–'.number_format($bookings->lastItem()) : '0' }}
                    of {{ number_format($bookings->total()) }}
                </span>
            </div>
            <ul class="lp-ledger" data-testid="refunds-table">
                @foreach ($bookings as $b)
                    @php
                        $row = $paidByBooking[$b->id] ?? ['paid' => 0, 'refunded' => 0];
                        $held = max(0, (int) $row['paid'] - (int) $row['refunded']);
                        $statusVal = $b->booking_status?->value ?? '-';
                        $anchorAt = $statusVal === 'no_show'
                            ? ($b->no_show_at ?? $b->updated_at)
                            : $b->updated_at;
                        $statusChipBg = $statusVal === 'no_show' ? '#FEF3C7' : 'rgba(184,92,66,0.10)';
                        $statusChipFg = $statusVal === 'no_show' ? '#92400E' : '#B85C42';
                        $tone = $statusVal === 'no_show' ? 'warn' : 'warn';
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="refunds-row-{{ $b->booking_ref }}"
                        x-on:click="$dispatch('refunds-panel-open', { id: {{ $b->id }} })"
                        tabindex="0"
                        x-on:keydown.enter.prevent="$dispatch('refunds-panel-open', { id: {{ $b->id }} })"
                        x-on:keydown.space.prevent="$dispatch('refunds-panel-open', { id: {{ $b->id }} })">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="font-weight: 500; color: var(--color-ink);">
                                    {{ $b->guest_name ?? 'Unnamed guest' }}
                                </span>
                                <span class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px;">
                                    {{ $b->booking_ref }}
                                </span>
                                <span class="lp-mono"
                                      style="padding: 2px 8px; border-radius: 999px;
                                             font-size: 9.5px;
                                             letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;
                                             background: {{ $statusChipBg }}; color: {{ $statusChipFg }};">
                                    {{ str_replace('_', ' ', $statusVal) }}
                                </span>
                            </div>
                            <p class="lp-ledger-meta">
                                @if ($b->room)
                                    Rm <span class="lp-mono">{{ $b->room->room_number }}</span>
                                    <span style="color: var(--color-ink-mute);">·</span>
                                @endif
                                @if ($b->guest_phone)
                                    <span class="lp-mono">{{ $b->guest_phone }}</span>
                                    <span style="color: var(--color-ink-mute);">·</span>
                                @endif
                                updated {{ $anchorAt?->format('j M Y, g:i A') ?? '-' }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                paid <span class="lp-mono">₦{{ number_format((int) $row['paid']) }}</span>
                                @if ((int) $row['refunded'] > 0)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    refunded <span class="lp-mono">₦{{ number_format((int) $row['refunded']) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total" style="color: #A8650A;">
                                ₦{{ number_format($held) }}
                            </span>
                            <span class="lp-mono" style="font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-ink-mute);">
                                held
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($bookings->hasPages())
                <div class="mt-4">{{ $bookings->links() }}</div>
            @endif
        </section>
    @endif
</div>
