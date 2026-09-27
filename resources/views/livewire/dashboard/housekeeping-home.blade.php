{{--
    Role-tuned dashboard for housekeeping-chain operators
    (housekeeper, porter, supervisor).

    Layout:
      1. Header, greeting + role + one-line context on the queue.
      2. KPI strip, what's on my plate, what I've done, what's blocked.
      3. "Waiting on you" list, my actual queue, linked to the
         inspection page so a tap starts the work.
      4. "Recent activity", what I signed off today.
      5. "Rejected, needs re-open", Failed inspections across the
         property; anyone in the chain should see them so the manager
         gets flagged.
--}}
<div class="lp-dash" wire:poll.30s="refresh" data-testid="hk-dashboard">

    @php
        $firstName = explode(' ', trim((string) $op->name))[0] ?? '';
        $queueCount = (int) $kpis['queue_count'];
        $doneCount = (int) $kpis['done_today'];
        $failedCount = (int) $kpis['failed_count'];

        $contextBits = [];
        if ($queueCount > 0) {
            $contextBits[] = $queueCount.' '.($queueCount === 1 ? 'room' : 'rooms').' waiting on you';
        }
        if ($doneCount > 0) {
            $contextBits[] = $doneCount.' cleared today';
        }
        $contextLine = $contextBits === []
            ? 'Nothing waiting on you right now, good work.'
            : implode(' · ', $contextBits).'.';

        // Stage-specific verbs for the button + action label.
        $ctaVerb = match ($role) {
            \App\Enums\OperatorRole::HouseCleaner => 'Start cleaning',
            \App\Enums\OperatorRole::Porter => 'Verify',
            \App\Enums\OperatorRole::Supervisor => 'Sign off',
        };
    @endphp

    {{-- ── Header ─────────────────────────────────────────────────── --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">{{ $roleLabel }} · today</span>
            <h1 data-testid="dashboard-heading"
                class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $contextLine }}
            </p>
        </div>
    </div>

    {{-- ── KPI strip ──────────────────────────────────────────────── --}}
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="hk-dashboard-kpis">
        <div class="lp-kpi" data-testid="hk-kpi-queue">
            <span class="label">Waiting on you</span>
            <span class="value"
                  style="color: {{ $queueCount > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink)' }};">
                {{ number_format($queueCount) }}
            </span>
            <span class="sub">{{ $queueStatus->label() }}</span>
        </div>

        <div class="lp-kpi" data-testid="hk-kpi-done-today">
            <span class="label">Cleared today</span>
            <span class="value">{{ number_format($doneCount) }}</span>
            <span class="sub">rooms you signed off</span>
        </div>

        <div class="lp-kpi" data-testid="hk-kpi-in-flight">
            <span class="label">In flight</span>
            <span class="value">{{ number_format((int) $kpis['in_flight_count']) }}</span>
            <span class="sub">open across the chain</span>
        </div>

        <div class="lp-kpi" data-testid="hk-kpi-failed">
            <span class="label">Rejected</span>
            <span class="value" style="color: {{ $failedCount > 0 ? '#B85C42' : 'var(--color-ink)' }};">
                {{ number_format($failedCount) }}
            </span>
            <span class="sub">need a fresh inspection</span>
        </div>
    </div>

    {{-- Supply attention card, low trolley stock surfaces here for
         house cleaners / porters / supervisors right alongside their
         inspection queue. Renders nothing when everything is stocked. --}}
    <livewire:dashboard.alerts-card />

    {{-- ── Waiting on you ─────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="hk-queue">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Waiting on you</span>
            <a href="{{ route('tenant.housekeeping.inspections.index') }}" wire:navigate
               class="lp-mono text-[11px]"
               style="color: var(--color-ink-mute); text-decoration: underline;
                      letter-spacing: 0.06em; text-transform: uppercase;">
                See all
            </a>
        </div>

        @if ($queue->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute); margin: 0;">
                No rooms are waiting on you right now.
            </p>
        @else
            <ul style="display: grid; gap: 8px;">
                @foreach ($queue as $ins)
                    @php
                        $room = $ins->room;
                        $categoryName = $room?->category?->name ?? 'Uncategorised';
                        $openedFor = $ins->created_at->diffForHumans(short: true);
                    @endphp
                    <li data-testid="hk-queue-row-{{ $ins->id }}"
                        style="display: flex; align-items: center; justify-content: space-between;
                               gap: 12px; padding: 12px 14px;
                               border: 1px solid var(--color-line-soft); border-radius: 10px;
                               background: var(--color-card);">
                        <div style="min-width: 0;">
                            <div style="color: var(--color-ink); font-weight: 500;">
                                Rm {{ $room?->room_number ?? '-' }}
                                <span style="color: var(--color-ink-mute); font-weight: 400;">
                                    · {{ $categoryName }}
                                </span>
                            </div>
                            <div class="lp-mono text-[11px]"
                                 style="color: var(--color-ink-mute); margin-top: 3px;">
                                opened {{ $openedFor }}
                                @if ($ins->triggeredByBooking)
                                    · after {{ $ins->triggeredByBooking->guest_name }}
                                    ({{ $ins->triggeredByBooking->booking_ref }})
                                @endif
                            </div>
                        </div>
                        <a href="{{ route('tenant.housekeeping.inspections.show', $ins) }}"
                           wire:navigate class="lp-pill"
                           data-testid="hk-queue-cta-{{ $ins->id }}">
                            {{ $ctaVerb }} →
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ── Recent activity ────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="hk-recent">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">You signed off today</span>
            <span class="lp-mono text-[11px]"
                  style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
                {{ $doneCount }} total
            </span>
        </div>

        @if ($doneCount === 0)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute); margin: 0;">
                Nothing signed off yet today.
            </p>
        @else
            <ul style="display: grid; gap: 6px;">
                @foreach ($doneToday as $ins)
                    @php
                        $room = $ins->room;
                        $stampedAt = match ($role) {
                            \App\Enums\OperatorRole::HouseCleaner => $ins->housekeeper_at,
                            \App\Enums\OperatorRole::Porter => $ins->porter_at,
                            \App\Enums\OperatorRole::Supervisor => $ins->supervisor_at,
                        };
                    @endphp
                    <li data-testid="hk-recent-row-{{ $ins->id }}"
                        style="display: flex; align-items: center; justify-content: space-between;
                               gap: 12px; padding: 10px 12px;
                               border-bottom: 1px solid var(--color-line-soft);">
                        <div style="min-width: 0;">
                            <span style="color: var(--color-ink); font-weight: 500;">
                                Rm {{ $room?->room_number ?? '-' }}
                            </span>
                            <span style="color: var(--color-ink-mute); font-weight: 400;">
                                · {{ $room?->category?->name ?? '-' }}
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                {{ $stampedAt?->format('g:i A') ?? '-' }}
                            </span>
                            <a href="{{ route('tenant.housekeeping.inspections.show', $ins) }}"
                               wire:navigate
                               class="lp-mono text-[11px]"
                               style="color: var(--color-accent-deep); text-decoration: underline;">
                                Open
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ── Rejected / needs re-open ───────────────────────────────── --}}
    @if ($failed->isNotEmpty())
        <section class="lp-dash-card" data-testid="hk-failed"
                 style="border-left: 3px solid #B85C42; background: rgba(224, 122, 95, 0.06);">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title" style="color: #B85C42;">
                    Rejected, needs a fresh inspection
                </span>
                <span class="lp-mono text-[11px]"
                      style="color: #B85C42; letter-spacing: 0.06em; text-transform: uppercase;">
                    {{ count($failed) }} open
                </span>
            </div>

            <ul style="display: grid; gap: 6px;">
                @foreach ($failed as $ins)
                    <li data-testid="hk-failed-row-{{ $ins->id }}"
                        style="display: flex; align-items: center; justify-content: space-between;
                               gap: 12px; padding: 10px 12px;">
                        <div style="min-width: 0;">
                            <span style="color: var(--color-ink); font-weight: 500;">
                                Rm {{ $ins->room?->room_number ?? '-' }}
                            </span>
                            <span style="color: var(--color-ink-mute); font-weight: 400;">
                                · {{ $ins->room?->category?->name ?? '-' }}
                            </span>
                            @if ($ins->summary_note)
                                <div class="text-[12px]" style="color: #7a2a13; margin-top: 3px;">
                                    ⚠ {{ $ins->summary_note }}
                                </div>
                            @endif
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                {{ $ins->updated_at->diffForHumans(short: true) }}
                            </span>
                            <a href="{{ route('tenant.housekeeping.inspections.show', $ins) }}"
                               wire:navigate
                               class="lp-mono text-[11px]"
                               style="color: #B85C42; text-decoration: underline;">
                                Review
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
