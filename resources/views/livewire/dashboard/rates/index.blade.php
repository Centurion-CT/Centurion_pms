@php
    $months = collect(range(0, 11))->map(fn ($i) => $anchorMonth->copy()->addMonths($i));
    $categoryLabel = function ($id) use ($categories) {
        if ($id === null || $id === '') return 'Every category';
        $c = $categories->firstWhere('id', (int) $id);
        return $c?->name ?? 'Category #'.$id;
    };

    $deviceOptions = [
        'mobile'  => 'Mobile',
        'desktop' => 'Desktop',
    ];
    $referrerOptions = [
        'direct'    => 'Direct',
        'google'    => 'Google',
        'bing'      => 'Bing',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp'  => 'WhatsApp',
        'twitter'   => 'Twitter / X',
        'other'     => 'Other referrer',
    ];

    // Build a per-month, per-day map of matching rules so the calendar
    // grid can paint the affected days without recomputing per cell.
    $rulesByDay = [];
    foreach ($rules as $rule) {
        $s = \Illuminate\Support\Carbon::parse($rule->starts_on)->startOfDay();
        $e = \Illuminate\Support\Carbon::parse($rule->ends_on)->startOfDay();
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            $rulesByDay[$d->toDateString()][] = $rule;
        }
    }
@endphp

<div class="lp-dash rates-page-scope">

    {{-- ── Hero: title + New rate CTA ─────────────────────── --}}
    <section class="lp-res-idx-hero" data-testid="rates-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Bookings</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Rates
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    Set overrides for the /stays online booking page. Front-desk rates stay untouched.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="openNewRule()"
                            data-testid="new-rate-rule"
                            class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New rate
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Category picker + month nav ─────────────────────
         Category chips reuse the add-charge chip vocab; month
         range navigator sits on the right with the tabstrip
         segmented control. --}}
    <section class="lp-dash-card lp-rates-controls" style="padding: 12px 16px;">
        <div class="lp-add-charge-chips" role="radiogroup" aria-label="Category">
            <button type="button" wire:click="$set('categoryId', '')"
                    class="lp-add-charge-chip {{ $categoryId === '' ? 'is-active' : '' }}"
                    data-testid="rate-cat-all"
                    role="radio" aria-checked="{{ $categoryId === '' ? 'true' : 'false' }}">
                <span>Every category</span>
            </button>
            @foreach ($categories as $cat)
                <button type="button" wire:click="$set('categoryId', '{{ $cat->id }}')"
                        class="lp-add-charge-chip {{ (string) $categoryId === (string) $cat->id ? 'is-active' : '' }}"
                        data-testid="rate-cat-{{ $cat->id }}"
                        role="radio" aria-checked="{{ (string) $categoryId === (string) $cat->id ? 'true' : 'false' }}">
                    <span>{{ $cat->name }}</span>
                </button>
            @endforeach
        </div>
        <div class="lp-rates-month-nav">
            <button type="button" wire:click="shiftAnchor(-1)"
                    class="lp-rates-month-btn"
                    aria-label="Previous month">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M7.5 3L4.5 6l3 3"/></svg>
            </button>
            <span class="lp-rates-month-label">
                {{ $anchorMonth->format('M Y') }} – {{ $anchorMonth->copy()->addMonths(11)->format('M Y') }}
            </span>
            <button type="button" wire:click="shiftAnchor(1)"
                    class="lp-rates-month-btn"
                    aria-label="Next month">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4.5 3l3 3-3 3"/></svg>
            </button>
        </div>
    </section>

    {{-- ── 12-month calendar grid ──────────────────────────
         Each month is a mini editorial card: Fraunces month title,
         uppercase mono day-of-week strip, day cells that carry a
         green marker + mono price when a rule is active. Today gets
         a subtle ring; past days dim to 55%. --}}
    <div class="rates-cal">
        @php $today = \Illuminate\Support\Carbon::today(); @endphp
        @foreach ($months as $month)
            @php
                $daysInMonth = $month->daysInMonth;
                $firstDow = (int) $month->copy()->startOfMonth()->dayOfWeekIso;
                $isCurrentMonth = $month->isSameMonth($today);
            @endphp
            <div class="rates-cal-month {{ $isCurrentMonth ? 'is-current' : '' }}">
                <div class="rates-cal-month-head">
                    <h4 class="lp-display">{{ $month->format('F') }}</h4>
                    <span class="rates-cal-month-year">{{ $month->format('Y') }}</span>
                </div>
                <div class="rates-cal-dow">
                    @foreach (['M','T','W','T','F','S','S'] as $l)
                        <span>{{ $l }}</span>
                    @endforeach
                </div>
                <div class="rates-cal-grid">
                    @for ($i = 1; $i < $firstDow; $i++)
                        <span class="rates-cal-cell rates-cal-cell--blank"></span>
                    @endfor
                    @for ($d = 1; $d <= $daysInMonth; $d++)
                        @php
                            $iso = $month->copy()->day($d)->toDateString();
                            $dayRules = $rulesByDay[$iso] ?? [];
                            $active = collect($dayRules)->firstWhere('is_active', true) ?: $dayRules[0] ?? null;
                            $isPast = \Illuminate\Support\Carbon::parse($iso)->lt($today);
                            $isToday = \Illuminate\Support\Carbon::parse($iso)->isSameDay($today);
                            // Past days: view-only (edit existing rule if any, else no-op).
                            // Today+future: open the New-rate form seeded with this day.
                            $onClick = $isPast
                                ? ($active ? 'editRule('.$active->id.')' : null)
                                : "openNewRule('{$iso}', '{$iso}')";
                            $classes = ['rates-cal-cell'];
                            if ($active) $classes[] = 'rates-cal-cell--on';
                            if ($isPast) $classes[] = 'rates-cal-cell--past';
                            if ($isPast && ! $active) $classes[] = 'rates-cal-cell--past-empty';
                            if ($isToday) $classes[] = 'rates-cal-cell--today';
                        @endphp
                        <button type="button"
                                @if ($onClick) wire:click="{{ $onClick }}" @else disabled aria-disabled="true" @endif
                                class="{{ implode(' ', $classes) }}"
                                @if ($active)
                                    title="{{ $active->label ?? 'Rate rule' }}: ₦{{ number_format($active->rate_ngn) }}{{ $isPast ? ' (past)' : '' }}"
                                @elseif ($isPast)
                                    title="Past date, no rule set"
                                @endif
                                data-testid="rate-day-{{ $iso }}">
                            <span class="rates-cal-cell-day">{{ $d }}</span>
                            @if ($active)
                                <span class="rates-cal-cell-price">
                                    {{ $active->rate_ngn >= 1000 ? number_format($active->rate_ngn / 1000, $active->rate_ngn % 1000 === 0 ? 0 : 1).'k' : number_format($active->rate_ngn) }}
                                </span>
                            @endif
                        </button>
                    @endfor
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Active rules list ─────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 12px;">
            <span class="lp-dash-card-title">Rate rules</span>
            <span class="lp-dash-card-meta">{{ $rules->count() }} {{ Str::plural('rule', $rules->count()) }}</span>
        </div>
        @if ($rules->isEmpty())
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'sparkle',
                'title'     => 'No rate rules yet.',
                'sub'       => 'Click a day on the calendar above or add a new rate to override the /stays online booking rate.',
                'ctaLabel'  => 'New rate',
                'ctaClickJs'=> "\$wire.openNewRule()",
            ])
        @else
            <ul class="lp-ledger lp-rates-rules">
                @foreach ($rules as $rule)
                    @php
                        $markerTone = $rule->is_active ? 'paid' : 'muted';
                    @endphp
                    <li class="lp-ledger-item lp-rates-rule"
                        data-testid="rate-rule-{{ $rule->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $markerTone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc">{{ $rule->label ?? 'Rate' }}</span>
                                <span class="lp-rates-price">₦{{ number_format($rule->rate_ngn) }}</span>
                                @if (! $rule->is_active)
                                    <span class="lp-tag" style="font-size: 9.5px; padding: 1px 6px;">Paused</span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $rule->starts_on->format('M j') }} <span style="color: var(--color-ink-mute);">→</span> {{ $rule->ends_on->format('M j, Y') }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $categoryLabel($rule->room_category_id) }}
                                @if ($rule->devices)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ implode(' + ', $rule->devices) }}
                                @endif
                                @if ($rule->referrers)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    from {{ implode(' + ', $rule->referrers) }}
                                @endif
                                @if ($rule->time_start || $rule->time_end)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $rule->time_start ?? '00:00' }}–{{ $rule->time_end ?? '23:59' }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-ledger-actions">
                            <button type="button" wire:click="editRule({{ $rule->id }})"
                                    class="lp-ledger-action">Edit</button>
                            <button type="button" wire:click="toggleActive({{ $rule->id }})"
                                    class="lp-ledger-action">{{ $rule->is_active ? 'Pause' : 'Resume' }}</button>
                            <button type="button" wire:click="deleteRule({{ $rule->id }})"
                                    wire:confirm="Delete this rate rule?"
                                    class="lp-ledger-action lp-ledger-action--danger">Delete</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ── Create / edit modal ─────────────────────────────────── --}}
    @if ($showForm)
        @php
            // Base-rate lookup so the modal can show the category's
            // current /stays online default alongside the input, and
            // compute a live discount percentage as the operator types.
            $baseRatesById = $categories->pluck('base_rate', 'id')->toArray();
            $baseRatesJson = json_encode($baseRatesById + ['' => 0]);
        @endphp
        <div class="lp-modal-backdrop" wire:click.self="cancelForm" data-testid="rate-form-modal">
            <div class="lp-modal-card lp-rate-modal" role="dialog" aria-modal="true"
                 x-data="{
                    bases: {{ $baseRatesJson }},
                    baseFor(id) { return Number(this.bases?.[id] ?? this.bases?.[''] ?? 0); },
                    deltaText(id, rate) {
                        const base = this.baseFor(id);
                        const cur = Number(rate);
                        if (! base || ! cur) return '';
                        const diff = cur - base;
                        if (diff === 0) return 'Same as current rate';
                        const pct = Math.round(Math.abs(diff) / base * 100);
                        return diff < 0
                            ? pct + '% off, saves ₦' + Math.abs(diff).toLocaleString()
                            : '+' + pct + '% surge, +₦' + diff.toLocaleString();
                    },
                    deltaClass(id, rate) {
                        const base = this.baseFor(id);
                        const cur = Number(rate);
                        if (! base || ! cur || cur === base) return 'lp-rate-delta lp-rate-delta--neutral';
                        return cur < base ? 'lp-rate-delta lp-rate-delta--down' : 'lp-rate-delta lp-rate-delta--up';
                    }
                 }">
                {{-- Editorial header. --}}
                <div class="lp-rate-modal-head">
                    <div>
                        <span class="lp-eyebrow-strip">Bookings</span>
                        <h3 class="lp-display" style="font-size: 22px; font-weight: 600; margin: 4px 0 0; letter-spacing: -0.005em;">
                            {{ $editingRuleId ? 'Edit rate' : 'New rate' }}
                        </h3>
                    </div>
                    <button type="button" wire:click="cancelForm" class="lp-modal-close" aria-label="Close">×</button>
                </div>

                <form wire:submit="save" class="lp-rate-modal-body">
                    {{-- Category chip picker (single select radios). --}}
                    <fieldset class="lp-rate-field">
                        <legend class="lp-form-label">Applies to</legend>
                        <div class="lp-add-charge-chips" role="radiogroup" aria-label="Category">
                            <button type="button" wire:click="$set('categoryId', '')"
                                    class="lp-add-charge-chip {{ (string) $categoryId === '' ? 'is-active' : '' }}"
                                    role="radio" aria-checked="{{ (string) $categoryId === '' ? 'true' : 'false' }}">
                                <span>Every category</span>
                            </button>
                            @foreach ($categories as $cat)
                                <button type="button" wire:click="$set('categoryId', '{{ $cat->id }}')"
                                        class="lp-add-charge-chip {{ (string) $categoryId === (string) $cat->id ? 'is-active' : '' }}"
                                        role="radio" aria-checked="{{ (string) $categoryId === (string) $cat->id ? 'true' : 'false' }}">
                                    <span>{{ $cat->name }}</span>
                                </button>
                            @endforeach
                        </div>
                        <p class="lp-form-help" data-testid="rate-base-hint"
                           x-show="baseFor($wire.categoryId) > 0"
                           x-cloak>
                            Current /stays rate:
                            <span class="lp-mono" style="color: var(--color-ink); font-weight: 600;">
                                ₦<span x-text="baseFor($wire.categoryId).toLocaleString()"></span>
                            </span>
                            / night
                        </p>
                    </fieldset>

                    {{-- Headline rate input + live delta pill. --}}
                    <fieldset class="lp-rate-field lp-rate-hero-input"
                              x-data="{
                                display: $wire.rate_ngn ? Number($wire.rate_ngn).toLocaleString() : '',
                                format(v) {
                                    const digits = String(v ?? '').replace(/[^\d]/g, '');
                                    const n = digits === '' ? null : parseInt(digits, 10);
                                    this.display = n === null ? '' : n.toLocaleString();
                                    $wire.set('rate_ngn', n, false);
                                }
                              }"
                              x-init="$watch('$wire.rate_ngn', v => {
                                const rendered = v ? Number(v).toLocaleString() : '';
                                if (rendered !== display) display = rendered;
                              })">
                        <legend class="lp-form-label">New rate per night</legend>
                        <div class="lp-rate-hero-input-row">
                            <span class="lp-rate-hero-sign">₦</span>
                            <input type="text"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   x-model="display"
                                   x-on:input="format($event.target.value)"
                                   class="lp-rate-hero-value"
                                   data-testid="rate-input"
                                   placeholder="18,000">
                        </div>
                        <p class="lp-rate-delta-wrap"
                           x-show="baseFor($wire.categoryId) > 0 && Number($wire.rate_ngn) > 0"
                           x-cloak>
                            <span :class="deltaClass($wire.categoryId, $wire.rate_ngn)"
                                  x-text="deltaText($wire.categoryId, $wire.rate_ngn)"
                                  data-testid="rate-delta"></span>
                        </p>
                        @error('rate_ngn') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
                    </fieldset>

                    {{-- Nights window. --}}
                    <fieldset class="lp-rate-field">
                        <legend class="lp-form-label">Nights</legend>
                        <div class="lp-rate-grid-2">
                            <div>
                                <label class="lp-rate-sub-label">Starts</label>
                                <input type="date" wire:model="starts_on" class="lp-input">
                            </div>
                            <div>
                                <label class="lp-rate-sub-label">Ends</label>
                                <input type="date" wire:model="ends_on" class="lp-input">
                            </div>
                        </div>
                    </fieldset>

                    {{-- Devices — multi-select chip toggles. --}}
                    <fieldset class="lp-rate-field">
                        <legend class="lp-form-label">Devices <span class="lp-rate-legend-hint">Any if none</span></legend>
                        <div class="lp-add-charge-chips">
                            @foreach ($deviceOptions as $val => $label)
                                <label class="lp-add-charge-chip {{ in_array($val, $devices ?? [], true) ? 'is-active' : '' }}">
                                    <input type="checkbox" wire:model.live="devices" value="{{ $val }}"
                                           class="sr-only" style="position: absolute; opacity: 0;">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- Referrers — multi-select chip toggles. --}}
                    <fieldset class="lp-rate-field">
                        <legend class="lp-form-label">Referrers <span class="lp-rate-legend-hint">Any if none</span></legend>
                        <div class="lp-add-charge-chips">
                            @foreach ($referrerOptions as $val => $label)
                                <label class="lp-add-charge-chip {{ in_array($val, $referrers ?? [], true) ? 'is-active' : '' }}">
                                    <input type="checkbox" wire:model.live="referrers" value="{{ $val }}"
                                           class="sr-only" style="position: absolute; opacity: 0;">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- Time window. --}}
                    <fieldset class="lp-rate-field">
                        <legend class="lp-form-label">Time window <span class="lp-rate-legend-hint">All day if empty</span></legend>
                        <div class="lp-rate-grid-2">
                            <div>
                                <label class="lp-rate-sub-label">Start</label>
                                <input type="time" wire:model="time_start" class="lp-input">
                            </div>
                            <div>
                                <label class="lp-rate-sub-label">End</label>
                                <input type="time" wire:model="time_end" class="lp-input">
                            </div>
                        </div>
                    </fieldset>

                    {{-- Label + priority + active toggle. --}}
                    <fieldset class="lp-rate-field">
                        <legend class="lp-form-label">Label (optional)</legend>
                        <input type="text" wire:model="label" maxlength="120"
                               class="lp-input" placeholder="e.g. Weekend mobile promo">
                    </fieldset>

                    <div class="lp-rate-tail">
                        <div>
                            <label class="lp-rate-sub-label">Priority</label>
                            <input type="number" wire:model="priority" min="0" max="1000"
                                   class="lp-input" style="width: 88px;">
                        </div>
                        <label class="lp-rate-toggle">
                            <input type="checkbox" wire:model="is_active">
                            <span class="lp-rate-toggle-track" aria-hidden></span>
                            <span class="lp-rate-toggle-label">Active</span>
                        </label>
                    </div>

                    <div class="lp-rate-modal-foot">
                        <button type="button" wire:click="cancelForm" class="lp-res-action lp-res-action--ghost">Cancel</button>
                        <button type="submit" class="lp-res-action lp-res-action--primary" data-testid="save-rate">
                            {{ $editingRuleId ? 'Save changes' : 'Save rate' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>
