@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back link + header ───────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">Pricing</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $room->name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Base rate <span class="lp-mono" style="color: var(--color-ink); font-weight: 500;">₦{{ number_format($room->base_rate) }}</span> per night.
            </p>
        </div>
    </div>

    {{-- Save confirmations surface via the global notify modal. --}}

    {{-- ── Settings + Overrides ─────────────────────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-2">

        {{-- Discounts & deposit --}}
        <section data-testid="settings-form" class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Discounts &amp; deposit</span>
            </div>

            <form wire:submit="saveSettings" style="display: grid; gap: 18px;">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="weekly" class="lp-form-label">Weekly discount %</label>
                        <input id="weekly" type="number" wire:model.number="weekly_discount_percent"
                               min="0" max="100" data-testid="weekly" class="lp-input">
                        @error('weekly_discount_percent') <p data-error="weekly" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="monthly" class="lp-form-label">Monthly discount %</label>
                        <input id="monthly" type="number" wire:model.number="monthly_discount_percent"
                               min="0" max="100" data-testid="monthly" class="lp-input">
                        @error('monthly_discount_percent') <p data-error="monthly" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <fieldset class="lp-fieldset">
                    <legend>Hold deposit</legend>
                    <div class="grid gap-1 mt-2">
                        <label class="lp-radio-row">
                            <input type="radio" wire:model="deposit_type" value="none">
                            <span>None</span>
                        </label>
                        <label class="lp-radio-row">
                            <input type="radio" wire:model="deposit_type" value="fixed">
                            <span>Fixed amount &middot; <span style="color: var(--color-ink-mute);">in ₦</span></span>
                        </label>
                        <label class="lp-radio-row">
                            <input type="radio" wire:model="deposit_type" value="percent">
                            <span>Percent of subtotal</span>
                        </label>
                    </div>
                    <div class="mt-3">
                        <label for="deposit-value" class="lp-form-label">Value</label>
                        <input id="deposit-value" type="number" wire:model.number="deposit_value"
                               min="0" data-testid="deposit-value" class="lp-input">
                        @error('deposit_value') <p data-error="deposit_value" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </fieldset>

                <div class="flex justify-end">
                    <button type="submit" class="lp-pill">
                        <span wire:loading.remove wire:target="saveSettings">Save settings</span>
                        <span wire:loading wire:target="saveSettings">Saving…</span>
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </form>
        </section>

        {{-- Seasonal pricing --}}
        <section data-testid="overrides-section" class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Seasonal pricing</span>
            </div>

            <form wire:submit="addOverride" data-testid="override-form" style="display: grid; gap: 14px;">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="lp-form-label">Start</label>
                        <input type="date" wire:model="override_start" required class="lp-input">
                        @error('override_start') <p data-error="override_start" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label">End</label>
                        <input type="date" wire:model="override_end" required class="lp-input">
                        @error('override_end') <p data-error="override_end" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="lp-form-label">Nightly rate (₦)</label>
                        <input type="number" wire:model.number="override_rate" min="1" required class="lp-input">
                        @error('override_rate') <p data-error="override_rate" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label">Label</label>
                        <input type="text" wire:model="override_label" maxlength="80"
                               class="lp-input" placeholder="Festive Season">
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="lp-btn lp-btn--primary">Add seasonal price</button>
                </div>
            </form>

            <div class="mt-5 overflow-x-auto" style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
                <table data-testid="overrides-table" class="lp-table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Rate</th>
                            <th style="text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($overrides as $o)
                            <tr data-testid="override-row-{{ $o->id }}">
                                <td style="color: var(--color-ink);">{{ $o->label ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $o->start_date->toDateString() }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $o->end_date->toDateString() }}</td>
                                <td><span class="lp-table-amount">₦{{ number_format($o->rate) }}</span></td>
                                <td style="text-align: right;">
                                    <button type="button" wire:click="deleteOverride({{ $o->id }})"
                                            wire:confirm="Delete this seasonal price?"
                                            data-testid="delete-override-{{ $o->id }}"
                                            style="color: #B85C42; background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: 12.5px;">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" data-testid="no-overrides"
                                    style="text-align: center; padding: 24px 0; color: var(--color-ink-mute); font-size: 13px;">
                                    No seasonal prices set.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- ── Price preview ────────────────────────────────────────── --}}
    <section data-testid="preview-section" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Price preview</span>
            <span class="lp-dash-card-meta">live calculation</span>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label">Check-in</label>
                <input type="date" wire:model.live="preview_checkin"
                       data-testid="preview-checkin" class="lp-input">
            </div>
            <div>
                <label class="lp-form-label">Check-out</label>
                <input type="date" wire:model.live="preview_checkout"
                       data-testid="preview-checkout" class="lp-input">
            </div>
        </div>

        @if ($preview)
            <dl data-testid="preview-breakdown" class="lp-dl mt-5">
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Nights</dt>
                    <dd class="lp-dl-value" data-testid="preview-nights">{{ $preview->nights }}</dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Subtotal</dt>
                    <dd class="lp-dl-value" data-testid="preview-subtotal">
                        ₦{{ number_format($preview->subtotal) }}
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Discount</dt>
                    <dd class="lp-dl-value">
                        {{ $preview->discountPercent }}%
                        <span class="muted">(₦{{ number_format($preview->discountAmount) }})</span>
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Deposit</dt>
                    <dd class="lp-dl-value" data-testid="preview-deposit">
                        ₦{{ number_format($preview->depositAmount) }}
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Paystack fee</dt>
                    <dd class="lp-dl-value muted">₦{{ number_format($preview->paystackFee) }}</dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">{{ config('brand.name') }} fee</dt>
                    <dd class="lp-dl-value muted">₦{{ number_format($preview->kevernaFee) }}</dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Total due</dt>
                    <dd class="lp-dl-value" data-testid="preview-total">
                        <span class="amount">₦{{ number_format($preview->totalDue) }}</span>
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Operator payout</dt>
                    <dd class="lp-dl-value">
                        <span class="amount" style="color: var(--color-accent-deep);">
                            ₦{{ number_format($preview->operatorPayout) }}
                        </span>
                    </dd>
                </div>
            </dl>
        @else
            <p data-testid="preview-empty" class="mt-4 text-[13.5px]" style="color: var(--color-ink-mute);">
                Pick a date range to see the breakdown.
            </p>
        @endif
    </section>
</div>
