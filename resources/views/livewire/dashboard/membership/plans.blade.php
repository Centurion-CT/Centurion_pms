<div class="lp-dash">

    {{-- ── Hero: title + New plan primary CTA ─────────────
         Right side hosts the total plan count so the operator
         sees how big the catalog is at a glance. --}}
    @include('_partials._back-button')

    <section class="lp-res-idx-hero mt-3" data-testid="plans-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Membership · Plans</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Plans
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Prices and validity windows for each business.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="newPlan"
                            data-testid="membership-new-plan"
                            @disabled($businesses->isEmpty())
                            class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New plan
                    </button>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Plan count">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Plans</p>
                    <p class="lp-ih-hero-accrued-value" style="color: var(--color-ink);">
                        {{ number_format($plans->count()) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        across {{ $businesses->count() }} {{ Str::plural('business', $businesses->count()) }}
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Business filter tabstrip ───────────────────── --}}
    @if ($businesses->isNotEmpty())
        <section class="lp-dash-card" style="padding: 12px 14px;">
            <div class="lp-tabstrip" data-testid="plan-business-tabs" role="tablist">
                <button type="button" wire:click="$set('businessFilter', '')"
                        role="tab"
                        aria-selected="{{ $businessFilter === '' ? 'true' : 'false' }}"
                        class="lp-tabstrip-btn {{ $businessFilter === '' ? 'is-active' : '' }}">
                    All
                    <span class="lp-tabstrip-count">{{ $businesses->count() }}</span>
                </button>
                @foreach ($businesses as $b)
                    <button type="button" wire:click="$set('businessFilter', '{{ $b->id }}')"
                            role="tab"
                            aria-selected="{{ $businessFilter === (string) $b->id ? 'true' : 'false' }}"
                            class="lp-tabstrip-btn {{ $businessFilter === (string) $b->id ? 'is-active' : '' }}">
                        {{ $b->name }}
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    @if ($formOpen)
        <section class="lp-dash-card" data-testid="membership-plan-form">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">
                    {{ $editingId ? 'Edit plan' : 'New plan' }}
                </span>
                <button type="button" wire:click="closeForm"
                        class="lp-btn" style="font-size: 12px;">Close</button>
            </div>
            <form wire:submit.prevent="save" class="grid gap-3 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="lp-form-label" for="plan-biz">Business</label>
                    <select id="plan-biz" wire:model="businessId"
                            data-testid="plan-biz" class="lp-input" required>
                        <option value="">Pick a business</option>
                        @foreach ($businesses as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="lp-form-label" for="plan-name">Name</label>
                    <input id="plan-name" type="text" wire:model="name"
                           data-testid="plan-name" class="lp-input"
                           placeholder="e.g. Monthly Gym" required>
                </div>
                <div>
                    <label class="lp-form-label" for="plan-desc">Description
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></label>
                    <input id="plan-desc" type="text" wire:model="description"
                           data-testid="plan-desc" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="plan-price">Price (&#8358;)</label>
                    <input id="plan-price" type="number" min="1" step="1" wire:model="price"
                           data-testid="plan-price" class="lp-input" required>
                </div>
                <div>
                    <label class="lp-form-label" for="plan-days">Validity (days)</label>
                    <input id="plan-days" type="number" min="1" step="1" wire:model="validityDays"
                           data-testid="plan-days" class="lp-input" required>
                </div>
                <label class="md:col-span-2"
                       style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" wire:model="active" data-testid="plan-active"
                           style="accent-color: var(--color-accent-deep);">
                    <span class="text-[13px]" style="color: var(--color-ink);">Active</span>
                </label>
                <div class="md:col-span-2 flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="closeForm"
                            class="lp-btn" style="font-size: 12px;">Cancel</button>
                    <button type="submit" data-testid="plan-save"
                            class="lp-btn lp-btn--primary" style="font-size: 12px;">
                        Save
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if ($plans->isEmpty())
        <section class="lp-dash-card" data-testid="no-plans" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'     => 'sparkle',
                'title'    => 'No plans yet.',
                'sub'      => $businesses->isEmpty()
                                 ? 'Create a business first, then add plans under it.'
                                 : 'Add plan tiers so operators can sign members up.',
                'ctaLabel'   => $businesses->isEmpty() ? null : 'New plan',
                'ctaClickJs' => $businesses->isEmpty() ? null : "\$wire.newPlan()",
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">
                    {{ $businessFilter === '' ? 'All plans' : 'Plans for '.optional($businesses->firstWhere('id', (int) $businessFilter))->name }}
                </span>
                <span class="lp-dash-card-meta">
                    {{ number_format($plans->count()) }} {{ Str::plural('plan', $plans->count()) }}
                </span>
            </div>
            <ul class="lp-ledger" data-testid="plans-table">
                @foreach ($plans as $p)
                    @php
                        $tone = $p->active ? 'paid' : 'muted';
                    @endphp
                    <li class="lp-ledger-item lp-category-row" data-testid="plan-row-{{ $p->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <span class="lp-category-thumb lp-category-thumb--empty" aria-hidden style="cursor: default; border-style: solid;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l3 3v15H6z"/><path d="M15 3v3h3M8 10h8M8 14h8M8 18h5"/></svg>
                        </span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                    {{ $p->name }}
                                </span>
                                <span class="lp-category-type">{{ $p->business?->name ?? '-' }}</span>
                                @if ($p->active)
                                    <span class="lp-tag lp-tag--confirmed">Active</span>
                                @else
                                    <span class="lp-tag lp-tag--on-hold">Inactive</span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                <span class="lp-mono">{{ $p->validity_days }}</span>
                                {{ Str::plural('day', (int) $p->validity_days) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                <span class="lp-mono">{{ $p->active_subscriptions_count }}</span>
                                active {{ Str::plural('member', (int) $p->active_subscriptions_count) }}
                                @if ($p->description)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ \Illuminate\Support\Str::limit($p->description, 60) }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-category-right">
                            <span class="lp-category-rate">₦{{ number_format($p->price) }}</span>
                            <div class="lp-ledger-actions">
                                <button type="button" wire:click="toggleActive({{ $p->id }})"
                                        class="lp-ledger-action">
                                    {{ $p->active ? 'Deactivate' : 'Activate' }}
                                </button>
                                <button type="button" wire:click="editPlan({{ $p->id }})"
                                        data-testid="edit-plan-{{ $p->id }}"
                                        class="lp-ledger-action">Edit</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</div>
