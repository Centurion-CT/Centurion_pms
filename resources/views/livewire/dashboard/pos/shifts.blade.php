@php
    $openCount = $shifts->getCollection()->whereNull('closed_at')->count();
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    {{-- ── Hero: title + open-shift side panel ──────────
         Right side surfaces the number of shifts currently
         open so a manager sees at a glance whether any tills
         are unattended / drifting. --}}
    <section class="lp-res-idx-hero mt-3" data-testid="shifts-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">POS · Shifts</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Shift reports
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    @if ($isManager)
                        Every open + closed shift across your outlets. Owner and accountant see everything; each salesperson sees only their own.
                    @else
                        Your shifts. Open a new one from the terminal; close it when you hand off the till so the report ties out.
                    @endif
                </p>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Open shifts">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Open now</p>
                    <p class="lp-ih-hero-accrued-value"
                       style="color: {{ $openCount > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};"
                       data-testid="shifts-open-count">
                        {{ number_format($openCount) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ($openCount === 0)
                            no till open
                        @else
                            {{ Str::plural('till', $openCount) }} still running
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Filters ──────────────────────────────────── --}}
    <section class="lp-dash-card lp-cal-search" style="padding: 12px 14px;">
        <div class="lp-cal-search-row">
            @if ($isManager)
                <div class="lp-cal-search-field">
                    <label for="shifts-outlet" class="lp-rate-sub-label">Outlet</label>
                    <select id="shifts-outlet" wire:model.live="outletFilter"
                            data-testid="shifts-outlet-filter"
                            class="lp-input" style="height: 36px;">
                        <option value="">All outlets</option>
                        @foreach ($availableOutlets as $o)
                            <option value="{{ $o->id }}">{{ $o->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="lp-cal-search-field">
                <label for="shifts-status" class="lp-rate-sub-label">Status</label>
                <select id="shifts-status" wire:model.live="statusFilter"
                        data-testid="shifts-status-filter"
                        class="lp-input" style="height: 36px;">
                    <option value="">All statuses</option>
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="lp-cal-search-field">
                <label for="shifts-from" class="lp-rate-sub-label">Opened from</label>
                <input id="shifts-from" type="date" wire:model.live="dateFrom"
                       data-testid="shifts-from"
                       class="lp-input" style="height: 36px;">
            </div>
            <div class="lp-cal-search-field">
                <label for="shifts-to" class="lp-rate-sub-label">Opened to</label>
                <input id="shifts-to" type="date" wire:model.live="dateTo"
                       data-testid="shifts-to"
                       class="lp-input" style="height: 36px;">
            </div>
        </div>
    </section>

    {{-- ── Shifts list ──────────────────────────────── --}}
    @if ($shifts->isEmpty())
        <section class="lp-dash-card" data-testid="shifts-empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'wallet',
                'title' => 'No shifts match those filters.',
                'sub'   => 'Open one from a POS terminal to get started, or widen the date range.',
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Shift ledger</span>
                <span class="lp-dash-card-meta">
                    {{ $shifts->firstItem() ? number_format($shifts->firstItem()).'–'.number_format($shifts->lastItem()) : '0' }}
                    of {{ number_format($shifts->total()) }}
                </span>
            </div>
            <ul class="lp-ledger" data-testid="shifts-table">
                @foreach ($shifts as $s)
                    @php
                        $isOpen = $s->closed_at === null;
                        $tone = $isOpen ? 'info' : 'paid';
                        $showHref = route('tenant.pos.shifts.show', $s);
                        $exportHref = route('tenant.pos.shifts.export', $s);
                        $duration = $s->closed_at
                            ? $s->opened_at->diffForHumans($s->closed_at, ['parts' => 2, 'short' => true])
                            : $s->opened_at->diffForHumans(null, ['parts' => 2, 'short' => true]);
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="shift-row-{{ $s->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ $showHref }}" wire:navigate
                                   class="lp-ledger-desc"
                                   style="font-weight: 600; color: var(--color-ink); text-decoration: none;">
                                    {{ $s->outlet?->name ?? '—' }} · {{ $s->operator?->name ?? 'Operator' }}
                                </a>
                                @if ($isOpen)
                                    <span class="lp-tag lp-tag--pending">Open</span>
                                @else
                                    <span class="lp-tag lp-tag--confirmed">Closed</span>
                                @endif
                                @if ($s->close_reason)
                                    <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase;">
                                        {{ Str::limit($s->close_reason, 30) }}
                                    </span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                Opened {{ $s->opened_at->format('D, j M · g:i A') }}
                                @if ($s->closed_at)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    Closed {{ $s->closed_at->format('D, j M · g:i A') }}
                                @endif
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $duration }}
                                @if ($s->closedBy && $s->closed_by_operator_id !== $s->operator_id)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: #a36318;">force-closed by {{ $s->closedBy->name }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-ledger-actions">
                            <a href="{{ $exportHref }}"
                               data-testid="shift-export-{{ $s->id }}"
                               class="lp-ledger-action">Download CSV</a>
                            <a href="{{ $showHref }}" wire:navigate
                               data-testid="shift-open-{{ $s->id }}"
                               class="lp-ledger-action">Report →</a>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($shifts->hasPages())
                <div class="mt-4">{{ $shifts->links() }}</div>
            @endif
        </section>
    @endif
</div>
