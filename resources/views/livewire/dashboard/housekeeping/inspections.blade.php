<div class="lp-dash" data-testid="inspections-page">

    {{-- ── Hero: title + open-count side tile ─────────────
         Identity block on the left; right side is a Fraunces
         open count coloured against the accent-deep. --}}
    @php
        $openTotal = $lanes['pending']->count()
            + $lanes['cleaning_done']->count()
            + $lanes['porter_verified']->count();
    @endphp
    <section class="lp-res-idx-hero" data-testid="inspections-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate · Housekeeping</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Room inspections
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Every open room-turnover inspection. Housekeeper &rarr; porter &rarr; supervisor signs off in order; check-in unlocks once the room is cleared.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.housekeeping.index') }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost"
                       data-testid="hk-back-to-ops">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M10 4L6 8l4 4"/></svg>
                        Housekeeping ops
                    </a>
                    @if ($myLane)
                        <span class="lp-res-action" style="cursor: default; background: rgba(93, 132, 44, 0.12); color: var(--color-accent-deep); border-color: rgba(93, 132, 44, 0.35);">
                            Your queue is highlighted
                        </span>
                    @endif
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Open inspections">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Open</p>
                    <p class="lp-ih-hero-accrued-value {{ $openTotal > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                       style="{{ $openTotal === 0 ? 'color: var(--color-accent-deep);' : '' }}"
                       data-testid="inspections-open-total">
                        {{ number_format($openTotal) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ($openTotal === 0)
                            All rooms cleared
                        @else
                            {{ $lanes['pending']->count() }} awaiting cleaner ·
                            {{ $lanes['cleaning_done']->count() }} porter ·
                            {{ $lanes['porter_verified']->count() }} supervisor
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Lanes ────────────────────────────────────────────────── --}}
    @if ($openTotal === 0)
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-open-inspections" class="text-[16px]"
               style="color: var(--color-ink); font-weight: 500;">
                All rooms are cleared.
            </p>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                Fresh inspections open automatically after each guest checkout.
            </p>
        </section>
    @else
        <section class="lp-dash-card" data-testid="inspection-lanes">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Open ({{ $openTotal }})</span>
                @if ($myLane)
                    <span class="lp-dash-card-meta">
                        Your queue is highlighted.
                    </span>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                @foreach ([
                    'pending'         => ['Awaiting housekeeper',   'Not yet cleaned'],
                    'cleaning_done'   => ['Awaiting porter',        'Housekeeper submitted'],
                    'porter_verified' => ['Awaiting supervisor',    'Porter cleared'],
                ] as $key => [$title, $subtitle])
                    <div data-testid="lane-{{ $key }}"
                         style="{{ $myLane === $key ? 'border: 1px solid var(--color-accent-deep);' : 'border: 1px solid var(--color-line-soft);' }}
                                border-radius: 10px; padding: 12px; background: var(--color-card);">
                        <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 8px;">
                            <div>
                                <div class="lp-mono text-[10.5px]" style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                                    {{ $title }}
                                    @if ($myLane === $key)
                                        · <span style="color: var(--color-accent-deep);">my queue</span>
                                    @endif
                                </div>
                                <div class="text-[12.5px]" style="color: var(--color-ink-soft); margin-top: 2px;">
                                    {{ $subtitle }}
                                </div>
                            </div>
                            <div class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                                {{ $lanes[$key]->count() }}
                            </div>
                        </div>

                        @if ($lanes[$key]->isEmpty())
                            <p class="text-[12px] mt-3" style="color: var(--color-ink-mute); font-style: italic;">
                                Nothing here.
                            </p>
                        @else
                            <ul class="mt-3" style="display: grid; gap: 6px;">
                                @foreach ($lanes[$key] as $ins)
                                    <li>
                                        <a href="{{ route('tenant.housekeeping.inspections.show', $ins) }}" wire:navigate
                                           data-testid="lane-{{ $key }}-inspection-{{ $ins->id }}"
                                           style="display: flex; align-items: center; justify-content: space-between; gap: 8px;
                                                  padding: 8px 10px; border-radius: 8px; text-decoration: none;
                                                  background: var(--color-card-deep);">
                                            <span class="lp-mono" style="color: var(--color-ink); font-weight: 500;">
                                                Rm {{ $ins->room?->room_number ?? '-' }}
                                            </span>
                                            <span class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                                {{ $ins->room?->category?->name ?? '' }}
                                                · opened {{ $ins->created_at->diffForHumans(short: true) }}
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── Recent history ───────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="inspection-recent">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Recent</span>
            <span class="lp-dash-card-meta">Last 15 closed</span>
        </div>
        @if ($recent->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute); font-style: italic;">
                No closed inspections yet.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Closed</th>
                            <th>Status</th>
                            <th class="lp-mono" style="text-align: right;">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $r)
                            <tr data-testid="recent-row-{{ $r->id }}">
                                <td class="lp-mono" style="font-weight: 500;">
                                    <a href="{{ route('tenant.housekeeping.inspections.show', $r) }}" wire:navigate
                                       style="color: var(--color-ink); text-decoration: none;">
                                        Rm {{ $r->room?->room_number ?? '-' }}
                                    </a>
                                    @if (in_array($r->room_id, $currentOccupantRoomIds, true))
                                        <span class="lp-mono text-[10px]"
                                              style="margin-left: 6px; padding: 1px 6px; border-radius: 999px;
                                                     letter-spacing: 0.06em; text-transform: uppercase;
                                                     background: #DBEAFE; color: #1E40AF; font-weight: 500;"
                                              data-testid="recent-occupied-{{ $r->id }}">
                                            Occupied now
                                        </span>
                                    @endif
                                    <div class="text-[10.5px]" style="color: var(--color-ink-mute); font-weight: 400;">
                                        {{ $r->room?->category?->name ?? '' }}
                                    </div>
                                </td>
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                    {{ $r->updated_at->format('D, j M · g:i A') }}
                                </td>
                                <td>
                                    <span class="lp-mono text-[11px]"
                                          style="padding: 2px 8px; border-radius: 999px;
                                                 letter-spacing: 0.06em; text-transform: uppercase;
                                                 {{ $r->status->value === 'passed'
                                                    ? 'background: #DCFCE7; color: #166534;'
                                                    : 'background: #FEE2E2; color: #B85C42;' }}">
                                        {{ $r->status->label() }}
                                    </span>
                                </td>
                                <td class="lp-mono" style="text-align: right; color: var(--color-ink);">
                                    {{ $r->readiness_score }}/100
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
