{{-- Notification bell - sits in the sidebar for Manager + FrontDesk.
     Polled every 30s; Alpine watches the count and plays a subtle
     chime when it goes from 0 → N so the desk hears new events
     without having to look. Clicking the bell opens a dropdown with
     the recent items + a "Mark all seen" action that zeros the count. --}}
<div wire:poll.30s
     x-data="notificationBell({{ (int) $count }})"
     x-effect="check({{ (int) $count }})"
     data-testid="notification-bell"
     style="position: relative;">

    <button type="button"
            @click="$wire.togglePanel()"
            data-testid="notification-bell-toggle"
            aria-label="Notifications"
            :aria-expanded="@js($panelOpen) ? 'true' : 'false'"
            style="width: 40px; height: 40px; border-radius: 999px;
                   background: transparent; border: 0; cursor: pointer;
                   color: currentColor; position: relative;
                   display: inline-flex; align-items: center; justify-content: center;
                   transition: background 140ms;"
            onmouseover="this.style.background='var(--color-card-deep, rgba(0,0,0,0.05))'"
            onmouseout="this.style.background='transparent'">
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"
             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 8a5 5 0 0 1 10 0v3l1 3H4l1-3V8z"/>
            <path d="M8.5 17a1.5 1.5 0 0 0 3 0"/>
        </svg>
        @if ($count > 0)
            <span data-testid="notification-bell-badge"
                  style="position: absolute; top: 6px; right: 6px;
                         min-width: 16px; height: 16px; padding: 0 4px;
                         border-radius: 999px; background: #DC2626; color: #fff;
                         font-size: 10px; font-weight: 700;
                         display: inline-flex; align-items: center; justify-content: center;">
                {{ $count > 9 ? '9+' : $count }}
            </span>
        @endif
    </button>

    @if ($panelOpen)
        {{-- Dropdown panel - teleport to body so it isn't trapped
             inside the sidebar's transformed stacking context. --}}
        <template x-teleport="body">
            <div x-data
                 x-init="$nextTick(() => document.addEventListener('click', outsideClose, true))"
                 x-on:keydown.escape.window="$wire.togglePanel()"
                 data-testid="notification-bell-panel-backdrop"
                 style="position: fixed; inset: 0; z-index: 90;"
                 @click.self="$wire.togglePanel()">
                <div class="lp-page"
                     data-testid="notification-bell-panel"
                     style="position: fixed; top: 20px; right: 20px;
                            width: min(360px, calc(100vw - 40px));
                            max-height: 70vh; overflow-y: auto;
                            background: var(--color-paper);
                            border-radius: 14px;
                            border: 1px solid var(--color-line);
                            box-shadow: 0 24px 60px -12px rgba(20, 17, 12, 0.35);
                            padding: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline;
                                margin-bottom: 10px;">
                        <p class="lp-mono text-[10.5px]"
                           style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase; margin: 0;">
                            New activity
                        </p>
                        @if ($count > 0)
                            <button type="button" wire:click="markAllSeen"
                                    data-testid="notification-bell-mark-all"
                                    style="background: transparent; border: 0; cursor: pointer;
                                           font: inherit; font-size: 12px;
                                           color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                Mark all seen
                            </button>
                        @endif
                    </div>

                    @if ($items->isEmpty())
                        <p data-testid="notification-bell-empty"
                           style="font-size: 12.5px; color: var(--color-ink-mute); margin: 0; padding: 12px 0;">
                            You're all caught up.
                        </p>
                    @else
                        <ul style="list-style: none; padding: 0; margin: 0;
                                   display: flex; flex-direction: column; gap: 4px;">
                            @foreach ($items as $item)
                                <li>
                                    <a href="{{ $item['url'] }}" wire:navigate
                                       @click="$wire.togglePanel()"
                                       data-testid="notification-bell-item"
                                       style="display: block; padding: 10px 12px;
                                              border-radius: 10px; text-decoration: none;
                                              color: var(--color-ink);
                                              transition: background 120ms;"
                                       onmouseover="this.style.background='var(--color-card-deep)'"
                                       onmouseout="this.style.background='transparent'">
                                        <p class="lp-mono text-[10px]"
                                           style="letter-spacing: 0.12em; text-transform: uppercase;
                                                  color: var(--color-accent-deep); margin: 0 0 3px; font-weight: 600;">
                                            {{ $item['kind_label'] }}
                                        </p>
                                        <p style="font-size: 13px; font-weight: 500; margin: 0; color: var(--color-ink);">
                                            {{ $item['title'] }}
                                        </p>
                                        <p style="font-size: 11.5px; color: var(--color-ink-mute); margin: 2px 0 0;">
                                            {{ $item['subtitle'] }}
                                            · {{ $item['at']?->diffForHumans() }}
                                        </p>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </template>
    @endif

    @once
        <script>
            /* Alpine factory + chime for the notification bell.
               Debounces: we only chime when the count strictly grows
               past the last observed value (a Livewire poll that
               returns the same count = no chime). Uses WebAudio for
               a subtle sine beep so we don't ship an audio file. */
            window.notificationBell = function (initialCount) {
                return {
                    lastCount: initialCount,
                    check(freshCount) {
                        if (freshCount > this.lastCount && freshCount > 0) {
                            this.chime();
                        }
                        this.lastCount = freshCount;
                    },
                    chime() {
                        try {
                            const AC = window.AudioContext || window.webkitAudioContext;
                            if (!AC) return;
                            const ctx = new AC();
                            const osc = ctx.createOscillator();
                            const gain = ctx.createGain();
                            osc.type = 'sine';
                            osc.frequency.setValueAtTime(880, ctx.currentTime);
                            osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.10);
                            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                            gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
                            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
                            osc.connect(gain).connect(ctx.destination);
                            osc.start();
                            osc.stop(ctx.currentTime + 0.36);
                        } catch (_) { /* audio blocked - silent fallback */ }
                    },
                    outsideClose(e) {
                        /* clicks inside the panel or the toggle stay open */
                        if (e.target.closest('[data-testid="notification-bell"]')) return;
                        if (e.target.closest('[data-testid="notification-bell-panel"]')) return;
                        this.$wire?.set?.('panelOpen', false, false);
                    },
                };
            };
        </script>
    @endonce
</div>
