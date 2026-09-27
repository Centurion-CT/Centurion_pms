<div class="lp-dash">

    {{-- ── Hero: title + New business primary CTA ────────
         Right side hosts the total count so the operator sees
         the catalog size at a glance. --}}
    @include('_partials._back-button')

    <section class="lp-res-idx-hero mt-3" data-testid="businesses-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Membership · Businesses</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Businesses
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Sub-services that host their own plans. Gym, spa, snooker, pool, and anything else you charge a recurring fee for.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="newBusiness"
                            data-testid="membership-new-business"
                            class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New business
                    </button>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Business count">
                @php $activeCount = $businesses->where('active', true)->count(); @endphp
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Active</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $activeCount > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                        {{ number_format($activeCount) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        of {{ number_format($businesses->count()) }} total
                    </p>
                </div>
            </aside>
        </div>
    </section>

    @if ($formOpen)
        <section class="lp-dash-card" data-testid="membership-business-form">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">
                    {{ $editingId ? 'Edit business' : 'New business' }}
                </span>
                <button type="button" wire:click="closeForm"
                        class="lp-btn" style="font-size: 12px;">Close</button>
            </div>
            <form wire:submit.prevent="save" class="grid gap-3">
                <div>
                    <label class="lp-form-label" for="biz-name">Name</label>
                    <input id="biz-name" type="text" wire:model="name"
                           data-testid="biz-name" class="lp-input" required>
                </div>
                <div>
                    <label class="lp-form-label" for="biz-desc">Description
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></label>
                    <input id="biz-desc" type="text" wire:model="description"
                           data-testid="biz-desc" class="lp-input">
                </div>
                @if ($properties->count() > 1)
                    <div>
                        <label class="lp-form-label" for="biz-property">Property</label>
                        <select id="biz-property" wire:model="propertyId"
                                data-testid="biz-property" class="lp-input">
                            <option value="">All properties</option>
                            @foreach ($properties as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" wire:model="active" data-testid="biz-active"
                           style="accent-color: var(--color-accent-deep);">
                    <span class="text-[13px]" style="color: var(--color-ink);">Active</span>
                </label>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="closeForm"
                            class="lp-btn" style="font-size: 12px;">Cancel</button>
                    <button type="submit" data-testid="biz-save"
                            class="lp-btn lp-btn--primary" style="font-size: 12px;">
                        Save
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if ($businesses->isEmpty())
        <section class="lp-dash-card" data-testid="no-businesses" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'       => 'sparkle',
                'title'      => 'No businesses yet.',
                'sub'        => 'Add gym, spa, snooker, or pool to start signing customers up.',
                'ctaLabel'   => 'New business',
                'ctaClickJs' => "\$wire.newBusiness()",
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">All businesses</span>
                <span class="lp-dash-card-meta">
                    {{ number_format($businesses->count()) }} {{ Str::plural('business', $businesses->count()) }}
                </span>
            </div>
            <ul class="lp-ledger" data-testid="businesses-table">
                @foreach ($businesses as $b)
                    @php $tone = $b->active ? 'paid' : 'muted'; @endphp
                    <li class="lp-ledger-item lp-category-row" data-testid="biz-row-{{ $b->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <span class="lp-category-thumb lp-category-thumb--empty" aria-hidden style="cursor: default; border-style: solid;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h18v12H3z"/><path d="M8 8V6a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M3 13h18"/></svg>
                        </span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                    {{ $b->name }}
                                </span>
                                @if ($b->active)
                                    <span class="lp-tag lp-tag--confirmed">Active</span>
                                @else
                                    <span class="lp-tag lp-tag--on-hold">Inactive</span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                <span class="lp-mono">{{ $b->plan_count }}</span>
                                {{ Str::plural('plan', (int) $b->plan_count) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                <span class="lp-mono">{{ $b->active_subscriptions_count }}</span>
                                active {{ Str::plural('member', (int) $b->active_subscriptions_count) }}
                                @if ($b->description)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ \Illuminate\Support\Str::limit($b->description, 60) }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-ledger-actions">
                            <button type="button" wire:click="toggleActive({{ $b->id }})"
                                    class="lp-ledger-action">
                                {{ $b->active ? 'Deactivate' : 'Activate' }}
                            </button>
                            <button type="button" wire:click="editBusiness({{ $b->id }})"
                                    data-testid="edit-biz-{{ $b->id }}"
                                    class="lp-ledger-action">Edit</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</div>
