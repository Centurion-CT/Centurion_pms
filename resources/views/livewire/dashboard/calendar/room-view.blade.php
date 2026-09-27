@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back link + editorial hero ─────────────────────
         Back nav sits on its own line; hero identity carries
         the room name in Fraunces, the current month as the
         eyebrow, and a month-range nav on the right. --}}
    @include('_partials._back-button')

    <section class="lp-res-idx-hero mt-3" data-testid="room-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">{{ $anchor->format('F Y') }}</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    {{ $room->name }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    {{ $anchor->format('F Y') }}. Block dates for maintenance, owner stays, or deep cleans.
                </p>
                <div class="lp-res-idx-hero-actions" data-testid="month-nav">
                    <div class="lp-rates-month-nav">
                        <button type="button" wire:click="previousMonth"
                                data-testid="prev-month"
                                class="lp-rates-month-btn" aria-label="Previous month">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M7.5 3L4.5 6l3 3"/></svg>
                        </button>
                        <span class="lp-rates-month-label">{{ $anchor->format('M Y') }}</span>
                        <button type="button" wire:click="nextMonth"
                                data-testid="next-month"
                                class="lp-rates-month-btn" aria-label="Next month">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4.5 3l3 3-3 3"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
    {{-- ── Calendar grid ────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div data-testid="calendar-grid" class="lp-cal-grid">
            @for ($d = 1; $d <= $daysInMonth; $d++)
                @php
                    $cell = $cells[$d] ?? null;
                    $state = $cell?->type?->value ?? 'free';
                @endphp
                <span data-testid="cell-{{ $d }}"
                      data-state="{{ $state }}"
                      title="{{ $cell?->reason ?? $state }}"
                      class="lp-cal-cell">{{ $d }}</span>
            @endfor
        </div>

        <ul class="flex flex-wrap items-center gap-6 mt-5 text-[12px]"
            style="color: var(--color-ink-mute);">
            <li class="flex items-center gap-2">
                <span aria-hidden style="display: inline-block; width: 10px; height: 10px; background: var(--color-accent-soft); border-radius: 3px;"></span>
                Available
            </li>
            <li class="flex items-center gap-2">
                <span aria-hidden style="display: inline-block; width: 10px; height: 10px; background: var(--color-ink); border-radius: 3px;"></span>
                Booked
            </li>
            <li class="flex items-center gap-2">
                <span aria-hidden style="display: inline-block; width: 10px; height: 10px; background: var(--color-card-deep); border-radius: 3px;"></span>
                Manually blocked
            </li>
        </ul>
    </section>

    {{-- ── Block / unblock form ─────────────────────────────────── --}}
    <form wire:submit="block" data-testid="block-form" class="lp-dash-card" style="display: grid; gap: 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 0;">
            <span class="lp-dash-card-title">Block dates</span>
            <span class="lp-dash-card-meta">e.g. maintenance, owner stay, deep clean</span>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label">From</label>
                <input type="date" wire:model="block_start" required
                       data-testid="block-start" class="lp-input">
                @error('block_start') <p data-error="block_start" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label">To (inclusive)</label>
                <input type="date" wire:model="block_end" required
                       data-testid="block-end" class="lp-input">
                @error('block_end') <p data-error="block_end" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label">Reason</label>
                <input type="text" wire:model="block_reason" maxlength="120"
                       data-testid="block-reason" class="lp-input"
                       placeholder="Owner stay">
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <button type="button" wire:click="unblock" data-testid="unblock" class="lp-btn">Unblock range</button>
            <button type="submit" class="lp-pill">
                <span wire:loading.remove wire:target="block">Block</span>
                <span wire:loading wire:target="block">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
