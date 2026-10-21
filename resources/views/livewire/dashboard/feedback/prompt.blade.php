<div>
    @if ($shouldRender)
        <div class="kv-feedback-inline"
             x-data="{
                shown: false,
                timer: null,
                start() {
                    // 8s dwell OR any scroll past 40px reveals the strip.
                    // On subsequent wire:navigate hops the timer restarts
                    // so a fresh module gets its own reveal window.
                    if (this.timer) clearTimeout(this.timer);
                    this.shown = false;
                    this.timer = setTimeout(() => { this.shown = true; }, 8_000);
                    const onScroll = () => {
                        if (window.scrollY > 40) {
                            this.shown = true;
                            window.removeEventListener('scroll', onScroll);
                            clearTimeout(this.timer);
                        }
                    };
                    window.addEventListener('scroll', onScroll, { passive: true });
                },
                init() {
                    this.start();
                    document.addEventListener('livewire:navigated', () => {
                        const route = document.body.dataset.currentRoute ?? '';
                        $wire.refreshForRoute(route);
                        this.start();
                    });
                },
             }"
             x-show="shown || {{ $open ? 'true' : 'false' }} || {{ $submitted ? 'true' : 'false' }}"
             x-cloak
             x-transition.opacity.duration.180ms
             data-testid="feedback-prompt">
            @if ($submitted)
                <div class="kv-feedback-inline-thanks" data-testid="feedback-thanks">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
                    <span>Thanks for your feedback. We read every note.</span>
                    <button type="button" wire:click="skip"
                            class="kv-feedback-inline-close" aria-label="Dismiss"
                            data-testid="feedback-close">&times;</button>
                </div>
            @elseif (! $open)
                <div class="kv-feedback-inline-strip">
                    <span class="kv-feedback-inline-q">How's the {{ $module }} area going?</span>
                    <div class="kv-feedback-inline-stars" data-testid="feedback-stars">
                        @for ($s = 1; $s <= 5; $s++)
                            <button type="button"
                                    wire:click="$set('rating', {{ $s }}); $set('open', true)"
                                    class="kv-feedback-inline-star @if ($rating >= $s) is-on @endif"
                                    aria-label="{{ $s }} of 5"
                                    data-testid="feedback-star-{{ $s }}">★</button>
                        @endfor
                    </div>
                    <button type="button" wire:click="skip"
                            class="kv-feedback-inline-close" aria-label="Dismiss"
                            data-testid="feedback-close">&times;</button>
                </div>
            @else
                <div class="kv-feedback-inline-card" role="dialog" aria-modal="false"
                     data-testid="feedback-card">
                    <div class="kv-feedback-inline-cardhead">
                        <div>
                            <b>How's the {{ $module }} area going?</b>
                            <div style="font-size: 12.5px; color: var(--color-ink-soft); margin-top: 2px;">
                                A quick note helps us prioritise what's next.
                            </div>
                        </div>
                        <button type="button" wire:click="skip"
                                class="kv-feedback-inline-close" aria-label="Skip"
                                data-testid="feedback-close">&times;</button>
                    </div>
                    <form wire:submit="submit" class="kv-feedback-inline-form">
                        <div class="kv-feedback-inline-stars" data-testid="feedback-stars">
                            @for ($s = 1; $s <= 5; $s++)
                                <button type="button"
                                        wire:click="$set('rating', {{ $s }})"
                                        class="kv-feedback-inline-star @if ($rating >= $s) is-on @endif"
                                        aria-label="{{ $s }} of 5"
                                        data-testid="feedback-star-{{ $s }}">★</button>
                            @endfor
                        </div>
                        @error('rating') <p style="font-size: 12px; color: #B85C42; margin: 0;">{{ $message }}</p> @enderror

                        <textarea wire:model.blur="body" maxlength="2000" rows="3"
                                  class="kv-feedback-inline-body"
                                  placeholder="What worked? What could be smoother? (Optional)"
                                  data-testid="feedback-body"></textarea>

                        <div class="kv-feedback-inline-actions">
                            <button type="button" wire:click="skip"
                                    class="kv-feedback-inline-skip" data-testid="feedback-skip">
                                Skip
                            </button>
                            <button type="submit" class="kv-feedback-inline-submit"
                                    wire:loading.attr="disabled" wire:target="submit"
                                    data-testid="feedback-submit">
                                <span wire:loading.remove wire:target="submit">Send feedback</span>
                                <span wire:loading wire:target="submit">Sending…</span>
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        <style>
            /* Inline feedback strip. Sits in the natural document flow
               between page sections — not floating, no shadow-stack,
               nothing shouting. Reads like a small contextual ask,
               dismissable via the × on the right. */
            .kv-feedback-inline {
                margin: 0 24px;
                max-width: 720px;
            }
            .kv-feedback-inline-strip,
            .kv-feedback-inline-thanks {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 10px 14px 10px 16px;
                background: var(--color-card, #FCFAF5);
                border: 1px solid var(--color-line, rgba(20,23,26,0.10));
                border-radius: 12px;
                font-size: 13px;
                color: var(--color-ink, #14171A);
            }
            .kv-feedback-inline-q {
                flex: 1 1 auto;
                min-width: 0;
                color: var(--color-ink-soft, #3A4148);
            }
            .kv-feedback-inline-stars {
                display: inline-flex;
                gap: 2px;
                flex: 0 0 auto;
            }
            .kv-feedback-inline-star {
                width: 26px; height: 26px;
                border: 0; background: transparent;
                font-size: 18px; color: #DADADA;
                cursor: pointer; padding: 0;
                transition: color 100ms ease, transform 100ms ease;
            }
            .kv-feedback-inline-star:hover { transform: scale(1.1); }
            .kv-feedback-inline-star.is-on { color: #E7AB2E; }
            .kv-feedback-inline-close {
                flex: 0 0 auto;
                width: 24px; height: 24px;
                border: 0; background: transparent;
                color: var(--color-ink-mute, #6E767D);
                font-size: 18px; line-height: 1;
                cursor: pointer; padding: 0;
                border-radius: 999px;
                transition: background 120ms ease, color 120ms ease;
            }
            .kv-feedback-inline-close:hover {
                background: var(--color-card-deep, #F2EDE2);
                color: var(--color-ink, #14171A);
            }
            .kv-feedback-inline-thanks {
                color: var(--color-accent-deep, #285480);
            }
            .kv-feedback-inline-thanks span {
                flex: 1 1 auto;
            }
            .kv-feedback-inline-card {
                background: var(--color-card, #FCFAF5);
                border: 1px solid var(--color-line, rgba(20,23,26,0.10));
                border-radius: 12px;
                padding: 16px 18px;
            }
            .kv-feedback-inline-cardhead {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 12px;
            }
            .kv-feedback-inline-form { display: grid; gap: 10px; }
            .kv-feedback-inline-body {
                width: 100%; padding: 8px 10px; border-radius: 8px;
                border: 1px solid var(--color-line, rgba(20,23,26,0.10));
                background: var(--color-paper, #FFFFFF);
                font-size: 13px; resize: vertical;
                font-family: inherit;
                color: var(--color-ink, #14171A);
            }
            .kv-feedback-inline-actions {
                display: flex; align-items: center; justify-content: space-between; gap: 8px;
            }
            .kv-feedback-inline-skip {
                background: transparent; border: 0; color: var(--color-ink-soft, #3A4148);
                cursor: pointer; font-size: 13px; padding: 6px 10px;
            }
            .kv-feedback-inline-submit {
                padding: 8px 14px; border-radius: 999px;
                background: var(--color-ink, #14171A); color: var(--color-card, #FCFAF5);
                border: 0; font-size: 13px; font-weight: 500; cursor: pointer;
            }
            .kv-feedback-inline-submit[disabled] { opacity: 0.55; cursor: default; }
        </style>
    @endif
</div>
