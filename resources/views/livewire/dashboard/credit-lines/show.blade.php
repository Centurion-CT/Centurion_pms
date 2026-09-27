<div class="lp-dash" data-testid="credit-line-detail">

    <div>
        @include('_partials._back-button')
        <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="lp-eyebrow-strip">Credit line</span>
                <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
                    {{ $line->name }}
                </h1>
                <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-soft);">
                    {{ $line->customer?->name ?? '-' }}
                    @if ($line->customer?->phone)
                        · <span class="lp-mono">{{ $line->customer->phone }}</span>
                    @endif
                    @if ($line->rooms->isNotEmpty())
                        · Rooms
                        <span class="lp-mono">{{ $line->rooms->pluck('room_number')->implode(', ') }}</span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @php
                    $badge = match ($line->status->value) {
                        'active'    => ['background: #DCFCE7; color: #166534;', 'Active'],
                        'suspended' => ['background: #FEF3C7; color: #92400E;', 'Suspended'],
                        'closed'    => ['background: var(--color-card-deep); color: var(--color-ink-mute);', 'Closed'],
                    };
                @endphp
                <span class="lp-mono text-[11px]"
                      style="padding: 4px 10px; border-radius: 999px;
                             letter-spacing: 0.06em; text-transform: uppercase; {{ $badge[0] }}">
                    {{ $badge[1] }}
                </span>
                <a href="{{ route('tenant.credit-lines.edit', $line) }}" wire:navigate
                   data-testid="cl-edit" class="lp-btn">Edit</a>
            </div>
        </div>
    </div>

    {{-- ── Pulse strip ──────────────────────────────────────────── --}}
    @php
        $clShowTiles = [
            [
                'label' => 'Charged',   'sub' => 'lifetime',
                'value' => (int) $chargedTotal,
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Settled',   'sub' => 'offline payments',
                'value' => (int) $settledTotal,
                'dot' => '#4B7BB0', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Outstanding','sub' => 'still to collect',
                'value' => (int) $outstandingTotal,
                'dot' => $outstandingTotal > 0 ? '#a36318' : 'var(--color-accent-deep)',
                'color' => $outstandingTotal > 0 ? '#a36318' : 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-1 sm:grid-cols-3" data-testid="cl-kpis">
        @foreach ($clShowTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        &#8358;{{ number_format($tile['value']) }}
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

    @if ($line->spending_limit !== null && $line->spending_limit > 0)
        @php
            $pct = $line->spending_limit > 0 ? min(100, (int) round(($chargedTotal / $line->spending_limit) * 100)) : 0;
            $over = $chargedTotal > $line->spending_limit;
        @endphp
        <section class="lp-dash-card" data-testid="cl-limit-hint" style="padding: 12px 14px;">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="lp-mono text-[11px]"
                      style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Spending limit
                </span>
                <span class="text-[12.5px]"
                      style="color: {{ $over ? '#B85C42' : 'var(--color-ink-soft)' }}; font-weight: {{ $over ? 600 : 400 }};">
                    &#8358;{{ number_format($chargedTotal) }}
                    of &#8358;{{ number_format((int) $line->spending_limit) }}
                    ({{ $pct }}%)
                    @if ($over) · <strong>over limit</strong> @endif
                </span>
            </div>
            <div style="height: 4px; background: var(--color-card-deep); border-radius: 999px; margin-top: 6px;">
                <div style="height: 100%; width: {{ $pct }}%;
                            background: {{ $over ? '#B85C42' : 'var(--color-accent-deep)' }};
                            border-radius: 999px;"></div>
            </div>
        </section>
    @endif

    {{-- ── Add-a-charge form ────────────────────────────────────── --}}
    @if ($line->status->acceptsCharges())
        <section class="lp-dash-card" data-testid="cl-add-charge">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Add a charge</span>
                <span class="lp-dash-card-meta">food, drink, damage…</span>
            </div>
            <form wire:submit="addCharge" style="display: grid; gap: 16px;">
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="cl-charge-type" class="lp-form-label">Type</label>
                        <select id="cl-charge-type" wire:model="newChargeType"
                                data-testid="cl-charge-type" class="lp-input">
                            @foreach ($chargeTypeOptions as $opt)
                                <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="cl-charge-desc" class="lp-form-label">Description</label>
                        <input id="cl-charge-desc" type="text" wire:model="newChargeDescription"
                               maxlength="240" data-testid="cl-charge-desc" class="lp-input"
                               placeholder="Rooftop bar, 3 cocktails">
                        @error('newChargeDescription') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="cl-charge-amount" class="lp-form-label">Amount (&#8358;)</label>
                        <input id="cl-charge-amount" type="number" wire:model="newChargeAmount"
                               min="1" data-testid="cl-charge-amount" class="lp-input"
                               placeholder="15000">
                        @error('newChargeAmount') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="cl-charge-room" class="lp-form-label">
                            Room <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                        </label>
                        <select id="cl-charge-room" wire:model="newChargeRoomId"
                                data-testid="cl-charge-room" class="lp-input">
                            <option value="">- unassigned -</option>
                            @foreach ($attachedRooms as $r)
                                <option value="{{ $r->id }}">Rm {{ $r->room_number }} · {{ $r->category?->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end justify-end">
                        <button type="submit" data-testid="cl-charge-submit" class="lp-pill"
                                wire:loading.attr="disabled" wire:target="addCharge">
                            <span wire:loading.remove wire:target="addCharge">Add charge</span>
                            <span wire:loading wire:target="addCharge">Adding…</span>
                        </button>
                    </div>
                </div>
            </form>
        </section>
    @endif

    {{-- ── Record a settlement ─────────────────────────────────── --}}
    @if ($line->status->acceptsSettlements() && $outstandingTotal > 0)
        <section class="lp-dash-card" data-testid="cl-record-settlement">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Record a settlement</span>
                <span class="lp-dash-card-meta">
                    offline payment · outstanding &#8358;{{ number_format($outstandingTotal) }}
                </span>
            </div>
            <form wire:submit="recordSettlement" style="display: grid; gap: 16px;">
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="cl-settle-amount" class="lp-form-label">Amount (&#8358;)</label>
                        <input id="cl-settle-amount" type="number" wire:model="settlementAmount"
                               min="1" max="{{ $outstandingTotal }}"
                               data-testid="cl-settle-amount" class="lp-input"
                               placeholder="{{ number_format($outstandingTotal) }}">
                        @error('settlementAmount') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="cl-settle-method" class="lp-form-label">Method</label>
                        <select id="cl-settle-method" wire:model="settlementMethod"
                                data-testid="cl-settle-method" class="lp-input">
                            @foreach ($settlementMethods as $m)
                                <option value="{{ $m }}">{{ ucfirst($m) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="cl-settle-note" class="lp-form-label">Reference / note</label>
                        <input id="cl-settle-note" type="text" wire:model="settlementNote"
                               maxlength="240" data-testid="cl-settle-note" class="lp-input"
                               placeholder="Bank ref, cheque #, POS trans id…">
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" data-testid="cl-settle-submit" class="lp-pill"
                            wire:loading.attr="disabled" wire:target="recordSettlement">
                        <span wire:loading.remove wire:target="recordSettlement">Record settlement</span>
                        <span wire:loading wire:target="recordSettlement">Recording…</span>
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- ── Range filter for the ledger ────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 12px 14px;">
        <div class="flex flex-wrap items-end gap-3">
            <div style="flex: 1 1 160px; min-width: 140px;">
                <label for="cl-from" class="lp-form-label"
                       style="font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 2px;">
                    From
                </label>
                <input id="cl-from" type="date" wire:model.live="fromDate"
                       data-testid="cl-from" class="lp-input" style="height: 36px;">
            </div>
            <div style="flex: 1 1 160px; min-width: 140px;">
                <label for="cl-to" class="lp-form-label"
                       style="font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 2px;">
                    To
                </label>
                <input id="cl-to" type="date" wire:model.live="toDate"
                       data-testid="cl-to" class="lp-input" style="height: 36px;">
            </div>
            @if ($fromDate !== '' || $toDate !== '')
                <button type="button" wire:click="clearRangeFilter"
                        class="lp-btn" style="height: 36px; padding: 0 14px; font-size: 12px;">
                    Clear
                </button>
            @endif
            <span class="text-[12px]" style="color: var(--color-ink-mute); margin-left: auto;">
                {{ $charges->count() }} charge(s) · {{ $settlements->count() }} settlement(s) in range
            </span>
        </div>
    </section>

    {{-- ── Charges ledger ───────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="cl-charges-ledger">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Charges</span>
        </div>
        @if ($charges->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No charges in this range.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Added</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Room</th>
                            <th class="lp-mono" style="text-align: right;">Amount (&#8358;)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($charges as $c)
                            <tr data-testid="cl-charge-row-{{ $c->id }}">
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                    {{ $c->created_at->format('j M · g:i A') }}
                                </td>
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                    {{ $c->type->label() }}
                                </td>
                                <td>{{ $c->description }}</td>
                                <td class="lp-mono text-[12px]" style="color: var(--color-ink-mute);">
                                    {{ $c->room?->room_number ? 'Rm '.$c->room->room_number : '-' }}
                                </td>
                                <td class="lp-mono" style="text-align: right; color: var(--color-ink);">
                                    {{ number_format((int) $c->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ── Settlements ledger ───────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="cl-settlements-ledger">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Settlements</span>
        </div>
        @if ($settlements->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No settlements in this range.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Settled</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="lp-mono" style="text-align: right;">Amount (&#8358;)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($settlements as $s)
                            <tr data-testid="cl-settlement-row-{{ $s->id }}">
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                    {{ $s->settled_at->format('j M · g:i A') }}
                                </td>
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                    {{ ucfirst($s->method) }}
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink);">
                                    {{ $s->reference_note ?? '-' }}
                                </td>
                                <td class="lp-mono" style="text-align: right; color: var(--color-ink);">
                                    {{ number_format((int) $s->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
