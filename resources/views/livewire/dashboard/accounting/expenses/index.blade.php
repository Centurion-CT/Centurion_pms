<div class="lp-dash">
    @include('_partials._back-button')

    {{-- ── Hero: title + this-month topline ────────────────
         Right side hosts this-month expense total in Fraunces
         with the vs-last-month delta as a coloured pill so the
         operator sees whether spend is trending up or down. --}}
    @php
        $expDelta = (int) $insights['this_month_total'] - (int) $insights['last_month_total'];
        $expPct = $insights['last_month_total'] > 0
            ? (int) round(($expDelta / $insights['last_month_total']) * 100)
            : null;
        $expDeltaColor = $expDelta > 0 ? '#B85C42' : ($expDelta < 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)');
    @endphp
    <section class="lp-res-idx-hero mt-3" data-testid="expenses-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Accounting · Expenses</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Expenses
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Log every money-out, utilities, F&amp;B supplies, repairs, and export at month-end.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="openCreate"
                            data-testid="expenses-open-create"
                            class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Log expense
                    </button>
                    <button type="button" wire:click="openCategories"
                            data-testid="expenses-open-categories"
                            class="lp-res-action">
                        Categories
                    </button>
                    <a href="{{ route('tenant.accounting.expenses.export', request()->query()) }}"
                       data-testid="expenses-export"
                       class="lp-res-action lp-res-action--ghost">
                        Export CSV
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="This month expenses">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">This month</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $insights['this_month_total'] > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="expenses-hero-this-month">
                        ₦{{ number_format((int) $insights['this_month_total']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub" style="color: {{ $expDeltaColor }};">
                        @if ($expDelta > 0)
                            +₦{{ number_format($expDelta) }}
                        @elseif ($expDelta < 0)
                            −₦{{ number_format(abs($expDelta)) }}
                        @else
                            same as last month
                        @endif
                        @if ($expPct !== null)
                            ({{ $expPct > 0 ? '+' : '' }}{{ $expPct }}%)
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Insights strip — dot-tile pulse ────────────────
         Mirrors the dashboard KPI vocabulary so the operator
         scans this the same way. --}}
    @php
        $expenseTiles = [
            [
                'label' => 'This month', 'sub' => 'money out so far',
                'value' => '₦'.number_format((int) $insights['this_month_total']),
                'dot'   => (int) $insights['this_month_total'] > 0 ? '#F97316' : 'var(--color-accent-deep)',
                'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Last month', 'sub' => 'prior-month total',
                'value' => '₦'.number_format((int) $insights['last_month_total']),
                'dot'   => 'var(--color-ink-mute)',
                'color' => 'var(--color-ink-soft)',
            ],
            [
                'label' => 'Top category',
                'sub'   => $insights['top_category']
                            ? '₦'.number_format((int) $insights['top_category_total']).' this month'
                            : 'nothing logged',
                'value' => $insights['top_category']?->name ?? '—',
                'text'  => true,
                'dot'   => $insights['top_category']?->color ?: '#7A6EA7',
                'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Change',
                'sub'   => $expPct !== null ? ($expPct > 0 ? '+' : '').$expPct.'% vs last month' : 'no prior data',
                'value' => $expDelta > 0
                    ? '+₦'.number_format($expDelta)
                    : ($expDelta < 0 ? '−₦'.number_format(abs($expDelta)) : 'Same'),
                'dot'   => $expDelta > 0 ? '#B85C42' : ($expDelta < 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)'),
                'color' => $expDeltaColor,
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="expenses-insights">
        @foreach ($expenseTiles as $tile)
            <div class="lp-dash-card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    @if (! empty($tile['text']))
                        <p class="lp-display" style="font-size: 17px; font-weight: 600; color: {{ $tile['color'] }}; letter-spacing: -0.005em; line-height: 1.15;">
                            {{ $tile['value'] }}
                        </p>
                    @else
                        <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                            {{ $tile['value'] }}
                        </p>
                    @endif
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

    {{-- ── Filters ─────────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="expenses-filters"
             style="padding: 16px; display: grid; gap: 12px;">
        <div class="grid gap-3 sm:grid-cols-5">
            <div>
                <label class="lp-form-label">From</label>
                <input type="date" wire:model.live="filterFrom" class="lp-input"
                       data-testid="expenses-filter-from">
            </div>
            <div>
                <label class="lp-form-label">To</label>
                <input type="date" wire:model.live="filterTo" class="lp-input"
                       data-testid="expenses-filter-to">
            </div>
            <div>
                <label class="lp-form-label">Category</label>
                <select wire:model.live="filterCategoryId" class="lp-input"
                        data-testid="expenses-filter-category">
                    <option value="0">All categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label">Paid via</label>
                <select wire:model.live="filterPaidVia" class="lp-input"
                        data-testid="expenses-filter-paid-via">
                    <option value="">Any method</option>
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                    <option value="card">Card</option>
                    <option value="pos">POS</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="lp-form-label">Branch</label>
                <select wire:model.live="filterBranch" class="lp-input"
                        data-testid="expenses-filter-branch">
                    <option value="current">This branch + HQ</option>
                    <option value="hq">HQ only</option>
                    <option value="all">All branches</option>
                </select>
            </div>
        </div>
        <p class="text-[12.5px]" style="color: var(--color-ink-mute); margin: 0;">
            Showing {{ $expenses->count() }} of the filter set -
            <strong style="color: var(--color-ink);">₦{{ number_format($filteredTotal) }}</strong> total.
        </p>
    </section>

    {{-- ── Two-column: expenses table | per-category sidebar ── --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="lp-dash-card lg:col-span-2" data-testid="expenses-list">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Recent expenses</span>
                <span class="lp-dash-card-meta">Newest first</span>
            </div>
            @if ($expenses->isEmpty())
                @include('livewire.dashboard._partials._empty', [
                    'icon'      => 'wallet',
                    'title'     => 'No expenses match those filters.',
                    'sub'       => 'Log every money-out so month-end doesn\'t become guesswork.',
                    'ctaLabel'  => 'Log expense',
                    'ctaClickJs'=> "\$wire.openCreate()",
                ])
            @else
                <ul class="lp-ledger" data-testid="expenses-table">
                    @foreach ($expenses as $exp)
                        @php
                            $catColor = $exp->category?->color;
                        @endphp
                        <li class="lp-ledger-item" data-testid="expenses-row-{{ $exp->id }}">
                            <span class="lp-ledger-marker"
                                  style="{{ $catColor ? 'background: '.$catColor.'; box-shadow: 0 0 0 3px '.$catColor.'22;' : '' }}"
                                  aria-hidden></span>
                            <div class="lp-ledger-body">
                                <div class="lp-gr-primary">
                                    <span class="lp-ledger-desc" style="font-weight: 500; color: var(--color-ink);">
                                        {{ $exp->description }}
                                    </span>
                                    <span class="lp-expense-cat"
                                          style="background: {{ $catColor ? $catColor.'22' : 'var(--color-card-deep)' }};
                                                 color: {{ $catColor ?: 'var(--color-ink)' }};">
                                        {{ $exp->category?->name ?? 'Uncategorised' }}
                                    </span>
                                </div>
                                <p class="lp-ledger-meta">
                                    <span class="lp-mono">{{ $exp->incurred_on->format('j M Y') }}</span>
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    via {{ ucfirst($exp->paid_via) }}
                                    @if ($exp->vendor)
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ $exp->vendor }}
                                    @endif
                                    @if ($exp->reference)
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        <span class="lp-mono">ref {{ $exp->reference }}</span>
                                    @endif
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $exp->property?->name ?? 'HQ' }}
                                </p>
                            </div>
                            <div class="lp-ledger-right">
                                <span class="lp-invoice-total">₦{{ number_format($exp->amount) }}</span>
                                <button type="button"
                                        wire:click="deleteExpense({{ $exp->id }})"
                                        wire:confirm="Delete this expense line?"
                                        data-testid="expenses-delete-{{ $exp->id }}"
                                        class="lp-ledger-action lp-ledger-action--danger">
                                    Remove
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <aside class="lp-dash-card" data-testid="expenses-per-category">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">By category</span>
                <span class="lp-dash-card-meta">This month</span>
            </div>
            @if ($insights['per_category']->isEmpty())
                <p class="text-[12.5px]" style="color: var(--color-ink-mute); padding: 12px 0;">
                    Nothing logged this month yet.
                </p>
            @else
                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px;">
                    @php $maxTotal = $insights['per_category']->max('total') ?: 1; @endphp
                    @foreach ($insights['per_category'] as $row)
                        <li>
                            <div class="flex justify-between text-[13px]" style="align-items: baseline;">
                                <span style="color: var(--color-ink); font-weight: 500;">
                                    {{ $row['category']->name }}
                                </span>
                                <span class="lp-mono" style="color: var(--color-ink);">
                                    ₦{{ number_format($row['total']) }}
                                </span>
                            </div>
                            <div style="height: 6px; background: var(--color-card-deep);
                                        border-radius: 999px; margin-top: 4px; overflow: hidden;">
                                <div style="width: {{ ($row['total'] / $maxTotal) * 100 }}%;
                                            height: 100%;
                                            background: {{ $row['category']->color ?: 'var(--color-ink)' }};"></div>
                            </div>
                            <p class="text-[11px]" style="color: var(--color-ink-mute); margin-top: 3px;">
                                {{ $row['count'] }} {{ Str::plural('entry', $row['count']) }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>

    {{-- ── Create-expense modal ────────────────────────────────── --}}
    @if ($createOpen)
        <div class="lp-page lp-modal-backdrop"
             data-testid="expenses-create-backdrop"
             wire:click.self="closeCreate"
             x-on:keydown.escape.window="$wire.closeCreate()"
             style="z-index: 80; background: rgba(20, 22, 24, 0.42); backdrop-filter: blur(2px);">
            <div style="width: 100%; max-width: 520px; max-height: calc(100vh - 40px); overflow-y: auto;
                        background: var(--color-card);
                        border-radius: 16px; border: 1px solid var(--color-line-soft);
                        box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                        padding: 22px 24px 20px;">
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                    Log expense
                </p>
                <h2 class="lp-display mt-1" style="font-size: 20px; font-weight: 600;">
                    Add a money-out line
                </h2>

                <form wire:submit="createExpense" style="display: grid; gap: 12px; margin-top: 14px;">
                    <div>
                        <label class="lp-form-label">Description</label>
                        <input type="text" wire:model="newDescription" class="lp-input"
                               placeholder="e.g. Diesel refill · 200L"
                               data-testid="expenses-new-description">
                        @error('newDescription') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="lp-form-label">Amount (₦)</label>
                            <input type="number" wire:model="newAmount" min="1" step="1"
                                   class="lp-input lp-mono"
                                   data-testid="expenses-new-amount">
                            @error('newAmount') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lp-form-label">Date</label>
                            <input type="date" wire:model="newIncurredOn" class="lp-input"
                                   data-testid="expenses-new-incurred-on">
                            @error('newIncurredOn') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="lp-form-label">Category</label>
                            <select wire:model="newCategoryId" class="lp-input"
                                    data-testid="expenses-new-category">
                                <option value="0">Pick one…</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            @error('newCategoryId') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lp-form-label">Paid via</label>
                            <select wire:model="newPaidVia" class="lp-input"
                                    data-testid="expenses-new-paid-via">
                                <option value="cash">Cash</option>
                                <option value="transfer">Transfer</option>
                                <option value="card">Card</option>
                                <option value="pos">POS</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="lp-form-label">Vendor · optional</label>
                            <input type="text" wire:model="newVendor" class="lp-input"
                                   placeholder="e.g. Total Filling Station"
                                   data-testid="expenses-new-vendor">
                        </div>
                        <div>
                            <label class="lp-form-label">Invoice / receipt # · optional</label>
                            <input type="text" wire:model="newReference" class="lp-input lp-mono"
                                   data-testid="expenses-new-reference">
                        </div>
                    </div>
                    <div>
                        <label class="lp-form-label">Notes · optional</label>
                        <textarea wire:model="newNotes" rows="2" class="lp-input"
                                  data-testid="expenses-new-notes"></textarea>
                    </div>
                    <label class="flex items-start gap-3 text-[13px]"
                           style="color: var(--color-ink); padding: 8px 10px;
                                  background: var(--color-card-deep); border-radius: 10px;">
                        <input type="checkbox" wire:model="newAttachToBranch"
                               data-testid="expenses-new-attach-branch"
                               style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                        <span>
                            <strong style="font-weight: 600;">Attach to this branch</strong>
                            <p class="text-[12px] mt-1" style="color: var(--color-ink-soft); line-height: 1.5;">
                                On means the expense belongs to the branch on the sidebar. Off makes it a chain-level
                                (HQ) line - visible from every branch's report.
                            </p>
                        </span>
                    </label>

                    <div class="flex justify-end gap-3 pt-2"
                         style="border-top: 1px solid var(--color-line-soft);">
                        <button type="button" wire:click="closeCreate" class="lp-btn">Cancel</button>
                        <button type="submit" class="lp-btn lp-btn--primary"
                                data-testid="expenses-create-submit"
                                wire:loading.attr="disabled" wire:target="createExpense">
                            <span wire:loading.remove wire:target="createExpense">Log expense</span>
                            <span wire:loading wire:target="createExpense">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── Categories manager modal ────────────────────────────── --}}
    @if ($categoriesOpen)
        <div class="lp-page lp-modal-backdrop"
             data-testid="expenses-categories-backdrop"
             wire:click.self="closeCategories"
             x-on:keydown.escape.window="$wire.closeCategories()"
             style="z-index: 80; background: rgba(20, 22, 24, 0.42); backdrop-filter: blur(2px);">
            <div style="width: 100%; max-width: 460px; max-height: calc(100vh - 40px); overflow-y: auto;
                        background: var(--color-card);
                        border-radius: 16px; border: 1px solid var(--color-line-soft);
                        padding: 22px 24px 20px;">
                <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin: 0;">
                    Expense categories
                </h2>
                <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                    Buckets to group expenses so reports read cleanly.
                </p>

                <form wire:submit="createCategory" style="display: flex; gap: 8px; margin-top: 14px;">
                    <input type="text" wire:model="newCategoryName" class="lp-input"
                           placeholder="e.g. Utilities"
                           data-testid="expenses-new-category-name" style="flex: 1;">
                    <input type="color" wire:model="newCategoryColor"
                           data-testid="expenses-new-category-color"
                           style="width: 44px; height: 44px; border: 1px solid var(--color-line);
                                  border-radius: 10px; padding: 4px; cursor: pointer;">
                    <button type="submit" class="lp-btn lp-btn--primary"
                            data-testid="expenses-add-category">Add</button>
                </form>
                @error('newCategoryName') <p class="lp-form-error mt-2">{{ $message }}</p> @enderror

                <ul style="list-style: none; padding: 0; margin: 16px 0 0;
                           display: flex; flex-direction: column; gap: 6px;
                           max-height: 320px; overflow-y: auto;">
                    @forelse ($categories as $cat)
                        <li class="flex items-center justify-between"
                            style="padding: 10px 12px; background: var(--color-card-deep);
                                   border-radius: 10px;"
                            data-testid="expenses-category-row-{{ $cat->id }}">
                            <span style="display: inline-flex; align-items: center; gap: 8px;">
                                <span style="width: 12px; height: 12px; border-radius: 4px;
                                             background: {{ $cat->color ?: 'var(--color-ink)' }};"></span>
                                <span style="font-weight: 500;">{{ $cat->name }}</span>
                            </span>
                            <button type="button"
                                    wire:click="deleteCategory({{ $cat->id }})"
                                    wire:confirm="Delete '{{ $cat->name }}'? Only works if no expenses use it."
                                    style="background: transparent; border: 0; cursor: pointer;
                                           font: inherit; font-size: 12px; color: var(--color-ink-mute);
                                           text-decoration: underline; text-underline-offset: 3px;">
                                Remove
                            </button>
                        </li>
                    @empty
                        <li style="padding: 12px 4px; color: var(--color-ink-mute); font-size: 12.5px;">
                            No categories yet - add one above.
                        </li>
                    @endforelse
                </ul>

                <div class="flex justify-end pt-3 mt-3"
                     style="border-top: 1px solid var(--color-line-soft);">
                    <button type="button" wire:click="closeCategories" class="lp-btn">Done</button>
                </div>
            </div>
        </div>
    @endif
</div>
