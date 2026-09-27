<div class="lp-dash" data-testid="credit-lines-page">

    {{-- ── Hero: title + Outstanding side panel ────────────
         Right side surfaces the sum still to settle offline
         in amber warn tone. --}}
    <section class="lp-res-idx-hero" data-testid="credit-lines-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Relationships</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Credit lines
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    House-account tabs. Attach one or more rooms to a customer. Food, drinks and other charges land on the line and settle offline.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.credit-lines.create') }}" wire:navigate
                       data-testid="credit-line-create"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New credit line
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Outstanding">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Outstanding</p>
                    <p class="lp-ih-hero-accrued-value {{ (int) $totals['outstanding'] > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                       data-testid="credit-lines-hero-outstanding">
                        ₦{{ number_format((int) $totals['outstanding']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        across {{ number_format((int) $totals['lines']) }}
                        {{ Str::plural('line', (int) $totals['lines']) }}
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Pulse strip ──────────────────────────────────── --}}
    @php
        $clTiles = [
            [
                'label' => 'Lines', 'sub' => 'on this page',
                'value' => (int) $totals['lines'], 'money' => false,
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Outstanding', 'sub' => 'to be settled offline',
                'value' => (int) $totals['outstanding'], 'money' => true,
                'dot' => $totals['outstanding'] > 0 ? '#a36318' : 'var(--color-accent-deep)',
                'color' => $totals['outstanding'] > 0 ? '#a36318' : 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-1 sm:grid-cols-2" data-testid="credit-lines-kpis">
        @foreach ($clTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        @if ($tile['money'])₦@endif{{ number_format($tile['value']) }}
                    </p>
                    <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $tile['label'] }}
                        <span style="text-transform: none; letter-spacing: 0;
                                     color: var(--color-ink-mute);
                                     font-size: 11px; margin-left: 4px;">
                            {{ $tile['sub'] }}
                        </span>
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Filter strip ─────────────────────────────────── --}}
    <section class="lp-dash-card lp-cal-search" style="padding: 12px 14px;">
        <div class="lp-cal-search-row">
            <div class="lp-cal-search-field lp-cal-search-field--room">
                <label for="cl-search" class="lp-rate-sub-label">Search</label>
                <input id="cl-search" type="text"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Name, customer, room #"
                       data-testid="credit-lines-search"
                       class="lp-input" style="height: 36px;">
            </div>
            <div class="lp-cal-search-field">
                <label for="cl-status" class="lp-rate-sub-label">Status</label>
                <select id="cl-status" wire:model.live="statusFilter"
                        data-testid="credit-lines-status"
                        class="lp-input" style="height: 36px;">
                    <option value="">All</option>
                    @foreach ($statusOptions as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($search !== '' || $statusFilter !== '')
                <button type="button" wire:click="clearFilters"
                        class="lp-res-action lp-res-action--ghost"
                        style="height: 36px; padding: 0 14px; font-size: 12px;">
                    Clear
                </button>
            @endif
        </div>
    </section>

    {{-- ── List ─────────────────────────────────────────── --}}
    @if ($lines->isEmpty())
        <section class="lp-dash-card" data-testid="no-credit-lines" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'wallet',
                'title'     => $search !== '' || $statusFilter !== ''
                                 ? 'No credit lines match those filters.'
                                 : 'No credit lines yet.',
                'sub'       => 'House-account tabs let regulars charge food and drinks and settle at month-end.',
                'ctaLabel'  => $search !== '' || $statusFilter !== '' ? null : 'Open one',
                'ctaHref'   => $search !== '' || $statusFilter !== '' ? null : route('tenant.credit-lines.create'),
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">All lines</span>
                <span class="lp-dash-card-meta">{{ number_format($lines->count()) }} {{ Str::plural('line', $lines->count()) }}</span>
            </div>
            <ul class="lp-ledger" data-testid="credit-lines-table">
                @foreach ($lines as $l)
                    @php
                        $charged = (int) $l->charged_total;
                        $settled = (int) $l->settled_total;
                        $outstanding = max(0, $charged - $settled);
                        $href = route('tenant.credit-lines.show', $l);
                        $statusClass = match ($l->status->value) {
                            'active'    => 'lp-tag--confirmed',
                            'suspended' => 'lp-tag--on-hold',
                            'closed'    => 'lp-tag--checked-out',
                            default     => '',
                        };
                        $tone = match ($l->status->value) {
                            'active'    => $outstanding > 0 ? 'warn' : 'paid',
                            'suspended' => 'warn',
                            'closed'    => 'muted',
                            default     => 'info',
                        };
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="credit-line-row-{{ $l->id }}"
                        role="link" tabindex="0"
                        aria-label="Open credit line {{ $l->name }}"
                        x-data
                        x-on:click="$event.target.closest('a,button') || Livewire.navigate(@js($href))"
                        x-on:keydown.enter.prevent="Livewire.navigate(@js($href))"
                        x-on:keydown.space.prevent="Livewire.navigate(@js($href))">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                    {{ $l->name }}
                                </span>
                                <span class="lp-tag {{ $statusClass }}">{{ $l->status->label() }}</span>
                                @if ($l->rooms->isNotEmpty())
                                    <span class="lp-category-type">
                                        Rm {{ $l->rooms->pluck('room_number')->implode(', ') }}
                                    </span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $l->customer?->name ?? 'No customer' }}
                                @if ($l->customer?->phone)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span class="lp-mono">{{ $l->customer->phone }}</span>
                                @endif
                                <span style="color: var(--color-ink-mute);">·</span>
                                charged <span class="lp-mono">₦{{ number_format($charged) }}</span>
                                <span style="color: var(--color-ink-mute);">·</span>
                                settled <span class="lp-mono">₦{{ number_format($settled) }}</span>
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total"
                                  style="color: {{ $outstanding > 0 ? '#a36318' : 'var(--color-ink)' }};">
                                ₦{{ number_format($outstanding) }}
                            </span>
                            <span class="lp-mono" style="font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-ink-mute);">
                                outstanding
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
