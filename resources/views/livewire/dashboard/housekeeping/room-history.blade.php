<div class="lp-dash" data-testid="hk-room-history">

    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Room history</span>
            <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
                Rm {{ $room->room_number ?? '-' }}
                <span style="color: var(--color-ink-mute); font-weight: 400;">
                    · {{ $room->category?->name ?? 'Uncategorised' }}
                </span>
            </h1>
        </div>
    </div>

    {{-- Occupancy banner, surfaces the current CheckedIn guest so the
         page doesn't read as "cleared for guests" while a guest is
         actually in the room. Historical inspection chips below stay
         accurate as records of past events. --}}
    @if ($currentOccupant)
        <div data-testid="hk-room-occupancy"
             style="padding: 12px 14px; border-radius: 10px; border: 1px solid #BFDBFE;
                    background: #EFF6FF;">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <span class="lp-mono text-[11px]"
                          style="padding: 2px 8px; border-radius: 999px;
                                 background: #DBEAFE; color: #1E40AF; margin-right: 8px;">
                        Currently occupied
                    </span>
                    <strong style="color: var(--color-ink);">{{ $currentOccupant->guest_name }}</strong>
                    <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                        · {{ $currentOccupant->booking_ref }}
                    </span>
                </div>
                <div class="text-[12px]" style="color: var(--color-ink-soft);">
                    {{ \Illuminate\Support\Carbon::parse($currentOccupant->checkin_date)->format('j M') }}
                    &rarr;
                    {{ \Illuminate\Support\Carbon::parse($currentOccupant->checkout_date)->format('j M') }}
                </div>
            </div>
        </div>
    @endif

    {{-- ── Flag banner / manager controls ──────────────────────
         Read from the room's latest inspection: Failed = out of
         service (either a porter/supervisor rejection OR a manager
         flag). Managers see a Clear button when flagged and a Flag
         button when not; everyone else sees the banner but no
         controls, so they know why check-in is blocked. --}}
    @if ($isFlagged)
        <div data-testid="hk-room-flag-banner"
             style="padding: 12px 14px; border-radius: 10px;
                    border: 1px solid #FCA5A5; background: #FEF2F2;
                    display: flex; flex-wrap: wrap; gap: 12px;
                    align-items: center; justify-content: space-between;">
            <div style="min-width: 0; flex: 1 1 260px;">
                <div class="lp-mono text-[11px]"
                     style="color: #B91C1C; letter-spacing: 0.14em; text-transform: uppercase; font-weight: 600;">
                    Flagged &middot; out of service
                </div>
                <p class="text-[13px]" style="color: var(--color-ink); margin-top: 2px;">
                    Check-in is blocked on this room until the flag is cleared.
                </p>
                @if ($flagReasonNote)
                    <p class="text-[12.5px]"
                       style="color: var(--color-ink-soft); font-style: italic; margin-top: 4px;">
                        Reason: {{ $flagReasonNote }}
                    </p>
                @endif
            </div>
            @if ($isManager)
                <button type="button"
                        wire:click="clearRoomFlag"
                        wire:confirm="Clear the flag on Rm {{ $room->room_number }}? Check-in will be re-enabled."
                        data-testid="hk-room-clear-flag"
                        class="lp-btn"
                        style="background: var(--color-ink); color: var(--color-card);
                               border: 0; padding: 6px 14px; border-radius: 999px;
                               font-family: inherit; font-size: 12.5px; font-weight: 600;">
                    Clear flag
                </button>
            @endif
        </div>
    @elseif ($isManager)
        {{-- Not flagged, manager-only "flag this room" card. Small
             inline form so the reason is captured up front — required
             by the FlagRoom action for the audit trail. --}}
        <div data-testid="hk-room-flag-form"
             x-data="{ open: false }"
             style="padding: 12px 14px; border-radius: 10px;
                    border: 1px dashed var(--color-line); background: var(--color-card);">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div style="min-width: 0;">
                    <div class="lp-mono text-[11px]"
                         style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                        Manager controls
                    </div>
                    <p class="text-[12.5px]" style="color: var(--color-ink-soft); margin-top: 2px;">
                        Flag this room to block check-ins (broken AC, plumbing, damage waiting on repair).
                    </p>
                </div>
                <button type="button" @click="open = ! open"
                        x-text="open ? 'Cancel' : 'Flag room'"
                        data-testid="hk-room-flag-toggle"
                        class="lp-btn"
                        style="background: transparent; color: #B85C42;
                               border: 1px solid #B85C42;
                               padding: 6px 14px; border-radius: 999px;
                               font-family: inherit; font-size: 12.5px; font-weight: 500;">
                    Flag room
                </button>
            </div>
            <form x-show="open" x-cloak x-transition
                  wire:submit.prevent="flagRoom"
                  style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">
                <input type="text"
                       wire:model="flagReason"
                       maxlength="500"
                       required
                       placeholder="Reason (e.g., AC broken, awaiting parts)"
                       data-testid="hk-room-flag-reason"
                       style="flex: 1 1 260px; min-width: 0;
                              padding: 8px 12px; border-radius: 999px;
                              border: 1px solid var(--color-line);
                              background: var(--color-card); color: var(--color-ink);
                              font-family: inherit; font-size: 13px;">
                <button type="submit"
                        data-testid="hk-room-flag-submit"
                        style="background: #B85C42; color: #fff;
                               border: 0; padding: 8px 16px; border-radius: 999px;
                               font-family: inherit; font-size: 12.5px; font-weight: 600;
                               cursor: pointer;">
                    Confirm flag
                </button>
            </form>
        </div>
    @endif

    {{-- ── Summary ─────────────────────────────────────────────── --}}
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="hk-room-summary">
        <div class="lp-kpi">
            <span class="label">Total inspections</span>
            <span class="value">{{ number_format($summary['total']) }}</span>
            <span class="sub">last 20 shown below</span>
        </div>
        <div class="lp-kpi">
            <span class="label">Issues · 7d</span>
            <span class="value" style="color: {{ $summary['issues_7d'] > 0 ? '#B85C42' : 'var(--color-ink)' }};">
                {{ number_format($summary['issues_7d']) }}
            </span>
            <span class="sub">damage flags in the last week</span>
        </div>
        <div class="lp-kpi">
            <span class="label">Issues · 30d</span>
            <span class="value">{{ number_format($summary['issues_30d']) }}</span>
            <span class="sub">rolling</span>
        </div>
        <div class="lp-kpi">
            <span class="label">Open damage</span>
            <span class="value" style="color: {{ $summary['open_damage'] > 0 ? '#B85C42' : 'var(--color-ink)' }};">
                {{ number_format($summary['open_damage']) }}
            </span>
            <span class="sub">still to fix</span>
        </div>
    </div>

    @if ($summary['last_passed'])
        <p class="text-[12.5px]" style="color: var(--color-ink-soft);">
            Last cleared for guests
            <strong style="color: var(--color-ink);">
                {{ $summary['last_passed']->diffForHumans() }}
            </strong>
            ({{ $summary['last_passed']->format('D, j M · g:i A') }}).
        </p>
    @endif

    {{-- ── Timeline ────────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="hk-room-timeline">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Inspection timeline</span>
        </div>

        @if ($inspections->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No inspections have been recorded for this room yet.
            </p>
        @else
            <ul style="display: grid; gap: 12px;">
                @foreach ($inspections as $ins)
                    @php
                        $ticked = $ins->items->where('checked', true)->count();
                        $totalItems = $ins->items->count();
                        $damage = $ins->items->filter(fn ($i) => trim((string) $i->damage_note) !== '');
                        $openDamage = $damage->filter(fn ($i) => $i->resolved_at === null);
                    @endphp
                    <li data-testid="hk-room-inspection-{{ $ins->id }}"
                        style="padding: 14px; border: 1px solid var(--color-line-soft); border-radius: 10px;
                               background: var(--color-card);">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <div>
                                <div style="color: var(--color-ink); font-weight: 500;">
                                    Inspection #{{ $ins->id }}
                                    <span style="color: var(--color-ink-mute); font-weight: 400;">
                                        · opened {{ $ins->created_at->format('D, j M · g:i A') }}
                                    </span>
                                </div>
                                @if ($ins->triggeredByBooking)
                                    <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                        after {{ $ins->triggeredByBooking->guest_name }}
                                        ({{ $ins->triggeredByBooking->booking_ref }}) checked out
                                    </div>
                                @endif
                            </div>
                            <div style="text-align: right;">
                                <span class="lp-mono text-[11px]"
                                      style="padding: 2px 8px; border-radius: 999px;
                                             {{ $ins->status->value === 'passed'
                                                ? 'background: #DCFCE7; color: #166534;'
                                                : ($ins->status->value === 'failed'
                                                    ? 'background: #FEE2E2; color: #B85C42;'
                                                    : 'background: #FEF3C7; color: #92400E;') }}">
                                    {{ $ins->status->label() }}
                                </span>
                                @if ($ins->readiness_score > 0)
                                    <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                        score {{ $ins->readiness_score }}/100
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Signer trail: cleaner · porter · supervisor. --}}
                        <div class="grid gap-2 sm:grid-cols-3" style="margin-top: 10px;">
                            @foreach ([
                                ['Cleaner',    $ins->housekeeper_at, $ins->housekeeper_by_operator_id],
                                ['Porter',     $ins->porter_at,      $ins->porter_by_operator_id],
                                ['Supervisor', $ins->supervisor_at,  $ins->supervisor_by_operator_id],
                            ] as [$role, $at, $byId])
                                <div style="padding: 6px 10px; border: 1px solid var(--color-line-soft); border-radius: 6px;">
                                    <div class="lp-mono text-[10px]"
                                         style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                                        {{ $role }}
                                    </div>
                                    @if ($at)
                                        <div class="text-[12px]" style="color: var(--color-ink); font-weight: 500; margin-top: 2px;">
                                            {{ $at->format('j M · g:i A') }}
                                        </div>
                                        <div class="text-[11px]" style="color: var(--color-ink-soft);">
                                            {{ $operatorNames[$byId] ?? '-' }}
                                        </div>
                                    @else
                                        <div class="text-[11.5px]" style="color: var(--color-ink-mute); font-style: italic; margin-top: 2px;">
                                            not signed
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- Empty-state hint: no items snapshotted onto this
                             inspection means no bundle is attached to the
                             category. Auto-sync fires on every render of
                             this page, so the moment a manager attaches a
                             bundle on the Housekeeping page the items
                             appear here, no manual action needed. --}}
                        @if ($totalItems === 0)
                            <div style="margin-top: 12px; padding: 10px 12px;
                                        background: var(--color-card-deep); border-radius: 8px;">
                                <p class="text-[12.5px]" style="color: var(--color-ink-soft); margin: 0;">
                                    <strong style="color: var(--color-ink);">No checklist items on this inspection.</strong>
                                    No bundle is attached to <strong>{{ $room->category?->name ?? 'this category' }}</strong>.
                                    Attach one on the
                                    <a href="{{ route('tenant.housekeeping.index') }}" wire:navigate
                                       style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">Housekeeping page</a>
                                   , the items appear here automatically.
                                </p>
                            </div>
                        @endif

                        {{-- Report body, good vs bad. --}}
                        <div class="grid gap-3 sm:grid-cols-2" style="margin-top: 12px;">
                            {{-- Good (checked items with no damage flag). --}}
                            <div>
                                <div class="lp-mono text-[10.5px]"
                                     style="letter-spacing: 0.08em; text-transform: uppercase; color: #166534;">
                                    Good ({{ $ticked }}/{{ $totalItems }})
                                </div>
                                @php
                                    $good = $ins->items->filter(fn ($i) => $i->checked && trim((string) $i->damage_note) === '');
                                @endphp
                                @if ($good->isNotEmpty())
                                    <ul class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); padding-left: 14px;">
                                        @foreach ($good as $g)
                                            <li>{{ $g->label }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-[11.5px]" style="color: var(--color-ink-mute); font-style: italic; margin-top: 2px;">
                                        Nothing marked good.
                                    </p>
                                @endif
                            </div>

                            {{-- Bad (damage-flagged items). --}}
                            <div>
                                <div class="lp-mono text-[10.5px]"
                                     style="letter-spacing: 0.08em; text-transform: uppercase; color: #B85C42;">
                                    Flagged ({{ $damage->count() }})
                                    @if ($openDamage->count() > 0)
                                        · <span style="color: #B85C42;">{{ $openDamage->count() }} still open</span>
                                    @endif
                                </div>
                                @if ($damage->isNotEmpty())
                                    <ul class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); padding-left: 14px;">
                                        @foreach ($damage as $d)
                                            <li data-testid="hk-room-flag-{{ $d->id }}"
                                                style="{{ $d->resolved_at ? 'text-decoration: line-through; opacity: 0.6;' : '' }}">
                                                <strong style="color: var(--color-ink);">{{ $d->label }}</strong>
                                                <span style="color: #B85C42;">- {{ $d->damage_note }}</span>
                                                @if ($d->resolved_at)
                                                    <span class="lp-mono text-[10px]" style="color: var(--color-ink-mute); margin-left: 4px;">
                                                        resolved {{ $d->resolved_at->diffForHumans(short: true) }}
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-[11.5px]" style="color: var(--color-ink-mute); font-style: italic; margin-top: 2px;">
                                        No damage flagged.
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if ($ins->summary_note)
                            <p class="text-[12.5px]" style="color: var(--color-ink-soft); margin-top: 10px;
                                      padding: 8px 10px; background: var(--color-card-deep); border-radius: 6px;">
                                <strong style="color: var(--color-ink);">Note:</strong>
                                {{ $ins->summary_note }}
                            </p>
                        @endif

                        <div style="margin-top: 10px; text-align: right;">
                            <a href="{{ route('tenant.housekeeping.inspections.show', $ins) }}" wire:navigate
                               class="lp-mono text-[11.5px]"
                               style="color: var(--color-accent-deep); text-decoration: underline;">
                                Open full inspection &rarr;
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
