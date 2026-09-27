<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Inventory · Change history</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Who changed what
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 68ch;">
                Every product or outlet field edit, every stock movement, in one timeline.
                Filter by product, operator, change type or date range.
            </p>
        </div>
    </div>

    {{-- ── Filter bar ────────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="audit-filters" style="display: grid; gap: 14px;">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="af-product" class="lp-form-label">Product</label>
                <select id="af-product" wire:model.live="productFilter" data-testid="af-product" class="lp-input">
                    <option value="">All products</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="af-operator" class="lp-form-label">Operator</label>
                <select id="af-operator" wire:model.live="operatorFilter" data-testid="af-operator" class="lp-input">
                    <option value="">All operators</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}">{{ $op->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="af-from" class="lp-form-label">From</label>
                <input id="af-from" type="date" wire:model.live="fromDate" data-testid="af-from" class="lp-input">
            </div>
            <div>
                <label for="af-to" class="lp-form-label">To</label>
                <input id="af-to" type="date" wire:model.live="toDate" data-testid="af-to" class="lp-input">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2" data-testid="af-kinds">
            <span class="lp-form-label" style="margin: 0;">Change type</span>
            @php
                $kindOptions = [
                    'price' => 'Price',
                    'cost'  => 'Cost',
                    'tax'   => 'Tax',
                    'stock' => 'Stock',
                    'other' => 'Other',
                ];
            @endphp
            @foreach ($kindOptions as $key => $label)
                <label class="lp-chip @if (in_array($key, $kindFilter, true)) is-active @endif"
                       style="cursor: pointer; user-select: none;"
                       data-testid="af-kind-{{ $key }}">
                    <input type="checkbox" wire:model.live="kindFilter" value="{{ $key }}"
                           style="display: none;">
                    {{ $label }}
                </label>
            @endforeach

            <div style="flex: 1;"></div>

            <button type="button" wire:click="clearFilters" class="lp-btn"
                    data-testid="af-clear" style="font-size: 12px; padding: 4px 12px;">
                Clear filters
            </button>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                {{ number_format($totalCount) }} row{{ $totalCount === 1 ? '' : 's' }}
            </span>
        </div>
    </section>

    {{-- ── Timeline table ────────────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="audit-table">
        @if ($entries->isEmpty())
            <div style="padding: 40px 24px; text-align: center; color: var(--color-ink-soft);">
                <p class="text-[14.5px]" style="margin: 0 0 4px; color: var(--color-ink);">Nothing matches those filters.</p>
                <p class="text-[13px]" style="color: var(--color-ink-mute); margin: 0;">
                    Try clearing them, or widening the date range.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">When</th>
                            <th>Who</th>
                            <th>What</th>
                            <th>Change</th>
                            <th class="text-right" style="min-width: 100px;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr data-testid="audit-row" data-audit-source="{{ $entry['source'] }}">
                                <td style="color: var(--color-ink-soft); vertical-align: top;">
                                    {{ $entry['when']?->toFormattedDateString() }}
                                    <span class="lp-mono text-[11px]" style="display: block; color: var(--color-ink-mute);">
                                        {{ $entry['when']?->format('g:i A') }} · {{ $entry['when']?->diffForHumans() }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink); font-weight: 500; vertical-align: top;">
                                    {{ $entry['causer_name'] ?? 'System' }}
                                </td>
                                <td style="vertical-align: top;">
                                    <span style="color: var(--color-ink); font-weight: 500;">
                                        {{ $entry['subject_label'] }}
                                    </span>
                                    <span class="lp-mono text-[10.5px]" style="display: block; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                                        {{ $entry['subject_type'] }}
                                    </span>
                                </td>
                                <td style="vertical-align: top;">
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px 6px; align-items: center;">
                                        @foreach ($entry['kinds'] as $k)
                                            <span class="lp-tag @if ($k === 'price') lp-tag--confirmed @elseif ($k === 'stock') lp-tag--checked-in @elseif ($k === 'cost') lp-tag--pending @elseif ($k === 'tax') lp-tag--on-hold @endif"
                                                  style="font-size: 10px;">{{ $k }}</span>
                                        @endforeach
                                        <span style="color: var(--color-ink); font-size: 13.5px;">{{ $entry['summary'] }}</span>
                                    </div>
                                </td>
                                {{-- Balance column, running quantity_after for stock
                                     rows (dash on activity rows). Paired chip on
                                     store-side Issued rows shows the receiving
                                     outlet's book balance the moment the stock
                                     landed + their same-day physical count when
                                     one exists, so a store↔outlet gap is visible
                                     without leaving the timeline. --}}
                                <td class="text-right lp-mono" style="vertical-align: top; white-space: nowrap;">
                                    @if (($entry['source'] ?? null) === 'stock' && array_key_exists('balance_after', $entry))
                                        <span style="color: var(--color-ink); font-weight: 600;"
                                              data-testid="audit-balance">
                                            {{ number_format($entry['balance_after']) }}
                                        </span>
                                        {{-- Rotational SKUs surface all three buckets:
                                             clean_stock (the bold number above) + in_use +
                                             at_laundry. Total holdings = sum of the three.
                                             Reads as "clean · in-use · laundry" in muted
                                             text under the primary balance. --}}
                                        @if (! empty($entry['rotational']))
                                            <div class="text-[10.5px]"
                                                 style="color: var(--color-ink-mute); font-weight: 400; text-align: right; margin-top: 2px;"
                                                 data-testid="audit-rotational">
                                                in-use <strong style="color: var(--color-ink); font-weight: 600;">{{ number_format($entry['rotational']['in_use']) }}</strong>
                                                · laundry <strong style="color: var(--color-ink); font-weight: 600;">{{ number_format($entry['rotational']['at_laundry']) }}</strong>
                                            </div>
                                        @endif
                                        @if (! empty($entry['paired']))
                                            @php
                                                $p = $entry['paired'];
                                                $counted = $p['outlet_counted'] ?? null;
                                                $gap = $counted !== null
                                                    ? (int) $counted - (int) $p['outlet_balance_after']
                                                    : null;
                                            @endphp
                                            <div class="text-[10.5px]"
                                                 style="color: var(--color-ink-mute); font-weight: 400; text-align: right; margin-top: 2px;"
                                                 data-testid="audit-paired">
                                                &rarr; {{ $p['outlet_name'] ?? 'outlet' }}
                                                bal <strong style="color: var(--color-ink); font-weight: 600;">{{ number_format($p['outlet_balance_after']) }}</strong>
                                                @if ($counted !== null)
                                                    <span @if ($gap !== 0) style="color: #B85C42; font-weight: 600;" @endif>
                                                        · counted {{ number_format($counted) }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    @else
                                        <span style="color: var(--color-ink-mute);">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 12px 16px; border-top: 1px solid var(--color-line);">
                {{ $entries->links() }}
            </div>
        @endif
    </section>
</div>
