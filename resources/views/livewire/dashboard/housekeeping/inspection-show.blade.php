<div class="lp-dash" data-testid="inspection-detail">

    <div>
        @include('_partials._back-button')

        <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="lp-eyebrow-strip">Room inspection</span>
                <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
                    Rm {{ $inspection->room?->room_number ?? '-' }}
                    <span style="color: var(--color-ink-mute); font-weight: 400;">
                        · {{ $inspection->room?->category?->name ?? 'Uncategorised' }}
                    </span>
                </h1>
                <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-soft);">
                    Opened {{ $inspection->created_at->diffForHumans() }}
                    @if ($inspection->triggeredByBooking)
                        · after {{ $inspection->triggeredByBooking->guest_name }}
                        ({{ $inspection->triggeredByBooking->booking_ref }})
                        checked out
                    @endif
                </p>
            </div>

            <div style="text-align: right;">
                <span class="lp-mono text-[10.5px]" style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Status
                </span>
                <div class="text-[15px]" data-testid="inspection-status"
                     style="font-family: var(--font-body);
                            color: {{ $inspection->status->value === 'passed' ? '#3D7B4F'
                            : ($inspection->status->value === 'failed' ? '#B85C42' : 'var(--color-ink)') }};
                            font-weight: 600; letter-spacing: -0.01em; margin-top: 4px;">
                    {{ $inspection->status->label() }}
                </div>
                @if ($inspection->readiness_score > 0)
                    <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 4px;">
                        Readiness {{ $inspection->readiness_score }}/100
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Sign-off audit trail ──────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Sign-off chain</span>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['Housekeeper', $inspection->housekeeper_at, 'housekeeper_by_operator_id'],
                ['Porter',      $inspection->porter_at,      'porter_by_operator_id'],
                ['Supervisor',  $inspection->supervisor_at,  'supervisor_by_operator_id'],
            ] as [$title, $at, $byField])
                <div style="padding: 10px 12px; border: 1px solid var(--color-line-soft); border-radius: 8px;">
                    <div class="lp-mono text-[10.5px]" style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                        {{ $title }}
                    </div>
                    @if ($at)
                        <div class="text-[13px] mt-1" style="color: var(--color-ink); font-weight: 500;">
                            {{ $at->format('D, j M · g:i A') }}
                        </div>
                        <div class="text-[11.5px]" style="color: var(--color-ink-mute);">
                            {{ optional(\App\Models\Operator::find($inspection->{$byField}))->name ?? '-' }}
                        </div>
                    @else
                        <div class="text-[12.5px] mt-1" style="color: var(--color-ink-mute); font-style: italic;">
                            Pending
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Checklist ─────────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="inspection-checklist">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Checklist</span>
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="lp-dash-card-meta">
                    {{ $inspection->items->where('checked', true)->count() }}
                    of {{ $inspection->items->count() }} ticked
                </span>
                {{-- Bulk tick — same permissions as ticking one item.
                     Skips items that are already ticked (idempotent). --}}
                @if ($canTick && $inspection->items->where('checked', false)->count() > 0)
                    <button type="button"
                            wire:click="markAllDone"
                            wire:confirm="Tick every remaining checklist item as done?"
                            data-testid="inspection-mark-all-done"
                            style="background: var(--color-accent); color: var(--color-on-accent);
                                   border: 0; padding: 5px 12px; border-radius: 999px;
                                   font-family: inherit; font-size: 11.5px; font-weight: 600;
                                   cursor: pointer;">
                        Mark all done
                    </button>
                @endif
            </div>
        </div>

        @if ($inspection->items->isEmpty())
            <div data-testid="inspection-empty-checklist"
                 style="padding: 12px 14px; background: var(--color-card-deep); border-radius: 8px;">
                <p class="text-[13.5px]" style="color: var(--color-ink-soft); margin: 0;">
                    <strong style="color: var(--color-ink);">No checklist items on this inspection.</strong>
                    No checklist bundle is attached to
                    <strong style="color: var(--color-ink);">{{ $inspection->room?->category?->name ?? 'this room\'s category' }}</strong>.
                    Once a manager attaches one on the
                    <a href="{{ route('tenant.housekeeping.index') }}" wire:navigate
                       style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">Housekeeping page</a>,
                    the items appear here automatically, no button to press.
                </p>
            </div>
        @else
            <ul style="display: grid; gap: 8px;">
                @foreach ($inspection->items as $item)
                    <li data-testid="inspection-item-{{ $item->id }}"
                        style="display: grid; grid-template-columns: 24px 1fr; gap: 10px;
                               padding: 10px 12px;
                               background: {{ $item->checked ? 'var(--color-card-deep)' : 'var(--color-card)' }};
                               border: 1px solid var(--color-line-soft); border-radius: 8px;">
                        <label style="display: inline-flex; align-items: center; margin: 0;">
                            <input type="checkbox"
                                   @if ($item->checked) checked @endif
                                   @disabled(! $canTick)
                                   wire:click="toggleItem({{ $item->id }})"
                                   data-testid="inspection-item-tick-{{ $item->id }}"
                                   style="width: 18px; height: 18px; cursor: pointer;
                                          accent-color: var(--color-accent-deep);">
                        </label>
                        <div>
                            <div style="color: var(--color-ink);
                                        font-weight: 500;
                                        {{ $item->checked ? 'text-decoration: line-through; opacity: 0.65;' : '' }}">
                                {{ $item->label }}
                            </div>
                            @if ($canTick)
                                <input type="text"
                                       wire:blur="updateDamageNote({{ $item->id }}, $event.target.value)"
                                       value="{{ $item->damage_note ?? '' }}"
                                       placeholder="Damaged / missing? Note it here…"
                                       data-testid="inspection-item-damage-{{ $item->id }}"
                                       maxlength="500"
                                       class="lp-input"
                                       style="margin-top: 6px; font-size: 12.5px; padding: 6px 8px; height: 32px;">
                            @elseif ($item->damage_note)
                                <div class="text-[11.5px]" style="color: #B85C42; margin-top: 4px;">
                                    ⚠ {{ $item->damage_note }}
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Summary note, visible to all signers, editable by the
             current one so the next signer sees fresh context. --}}
        <div style="margin-top: 16px;">
            <label class="lp-form-label" for="ins-summary">Summary / hand-over note</label>
            <textarea id="ins-summary"
                      wire:model="summaryNote"
                      rows="3"
                      maxlength="2000"
                      data-testid="inspection-summary"
                      class="lp-input"
                      placeholder="e.g. Deep clean done, bedside lamp bulb blown, swap before next guest."></textarea>
        </div>

        {{-- Sign-off actions, only the button for the current stage
             renders, gated by the operator's role. --}}
        <div class="flex flex-wrap items-center justify-end gap-2 mt-4 pt-3"
             style="border-top: 1px solid var(--color-line-soft);">
            @if ($canSubmit)
                <button type="button" wire:click="submitCleaning"
                        wire:loading.attr="disabled" wire:target="submitCleaning"
                        data-testid="inspection-submit-cleaning"
                        class="lp-btn lp-btn--primary">
                    Submit for porter
                </button>
            @endif

            @if ($canPorter)
                <button type="button" wire:click="porterVerify(false)"
                        wire:loading.attr="disabled" wire:target="porterVerify"
                        wire:confirm="Reject this inspection? A supervisor will need to open a fresh one."
                        data-testid="inspection-porter-reject"
                        class="lp-btn" style="color: #B85C42;">
                    Reject
                </button>
                <button type="button" wire:click="porterVerify(true)"
                        wire:loading.attr="disabled" wire:target="porterVerify"
                        data-testid="inspection-porter-verify"
                        class="lp-btn lp-btn--primary">
                    Verify, send to supervisor
                </button>
            @endif

            @if ($canSupervisor)
                <button type="button" wire:click="supervisorSignOff(false)"
                        wire:loading.attr="disabled" wire:target="supervisorSignOff"
                        wire:confirm="Reject this inspection? Housekeeping will have to redo the room."
                        data-testid="inspection-supervisor-reject"
                        class="lp-btn" style="color: #B85C42;">
                    Reject
                </button>
                <button type="button" wire:click="supervisorSignOff(true)"
                        wire:loading.attr="disabled" wire:target="supervisorSignOff"
                        data-testid="inspection-supervisor-pass"
                        class="lp-btn lp-btn--primary">
                    Sign off, clear for guests
                </button>
            @endif
        </div>
    </section>
</div>
