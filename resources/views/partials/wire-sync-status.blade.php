{{--
    Global Livewire sync indicator + retry-on-fail toast.

    Front-desk complaint: on a laggy connection there's no signal
    that a click / edit is still round-tripping, and when a request
    silently fails (offline mid-typing, 500 during submit) the
    operator has no way to recover short of reloading the page and
    losing everything they've typed.

    This pill hooks into Livewire.hook('request', ...) and:

      - Shows a subtle "Syncing…" chip after a 250ms delay (avoids
        a flash on fast local requests). Hides again once every
        in-flight request has resolved.

      - On failure — non-2xx status, network drop, timeout — flips
        into a red "Connection issue" card with a Retry button. The
        retry calls $refresh() on every mounted Livewire component,
        which re-hydrates their state from the server. Alpine state
        (@@entangle'd form values) survives, so the operator doesn't
        lose the discount / deposit / guest fields they typed.

    Kept purely Alpine + Livewire hook so there's no server-side
    dependency; safe to render inside layouts/tenant-auth without
    touching every component's render method.
--}}
<div x-data="wireSyncStatus()"
     x-init="init()"
     x-cloak
     data-testid="wire-sync-status"
     style="position: fixed; top: 22px; left: 50%; transform: translateX(-50%);
            z-index: 90; pointer-events: none;">

    {{-- Syncing pill (idle → hidden). Delayed 250ms so quick server
         responses don't cause a distracting flash. --}}
    <div x-show="state === 'syncing'" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         data-testid="wire-sync-syncing"
         style="pointer-events: auto;
                display: inline-flex; align-items: center; gap: 8px;
                padding: 7px 14px 7px 12px;
                background: rgba(20, 22, 24, 0.86);
                color: #fff;
                border-radius: 999px;
                font-size: 12px; font-weight: 500;
                letter-spacing: 0.01em;
                box-shadow: 0 8px 24px -12px rgba(0,0,0,0.35);
                backdrop-filter: blur(4px);">
        <span aria-hidden
              style="width: 8px; height: 8px; border-radius: 999px;
                     background: #94D5A9;
                     animation: kv-wire-sync-pulse 900ms ease-in-out infinite;"></span>
        <span>Syncing…</span>
    </div>

    {{-- Offline-queue chip. Shows the number of reservations sitting
         in IndexedDB waiting to sync. Clicking opens a panel with the
         list + Sync now / Forget controls. --}}
    <div x-show="queued > 0 && state !== 'failed'" x-cloak
         data-testid="wire-sync-queued"
         style="pointer-events: auto;
                display: inline-flex; align-items: center; gap: 8px;
                margin-top: 6px;
                padding: 6px 12px 6px 10px;
                background: #FEF3C7; color: #78350F;
                border: 1px solid #FCD34D;
                border-radius: 999px;
                font-size: 12px; font-weight: 600;
                cursor: pointer;
                box-shadow: 0 4px 14px -8px rgba(120, 53, 15, 0.35);"
         @click="queuePanelOpen = !queuePanelOpen">
        <span aria-hidden
              style="width: 6px; height: 6px; border-radius: 999px;
                     background: #D97706;"></span>
        <span x-text="queued + (queued === 1 ? ' reservation' : ' reservations') + ' queued locally'"></span>
    </div>

    {{-- Detailed queue panel. Lists each queued reservation with
         status + a per-item action row. Alpine-only so it works
         even when the whole page is offline. --}}
    <div x-show="queuePanelOpen" x-cloak
         @click.outside="queuePanelOpen = false"
         data-testid="wire-sync-queue-panel"
         style="pointer-events: auto;
                position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%);
                min-width: 340px; max-width: min(94vw, 520px); max-height: 60vh; overflow-y: auto;
                background: var(--color-card); color: var(--color-ink);
                border-radius: 14px;
                border: 1px solid var(--color-line);
                box-shadow: 0 24px 48px -20px rgba(20, 17, 12, 0.22);">
        <div style="display: flex; align-items: center; justify-content: space-between;
                    padding: 12px 14px; border-bottom: 1px solid var(--color-line-soft);">
            <strong style="font-size: 13px;">Queued reservations</strong>
            <button type="button"
                    @click="syncNow()"
                    data-testid="wire-sync-now"
                    :disabled="!isOnline || syncing"
                    style="background: var(--color-accent);
                           color: var(--color-ink);
                           border: 0; padding: 5px 12px; border-radius: 999px;
                           font-size: 11.5px; font-weight: 600;
                           cursor: pointer;
                           opacity: (isOnline && !syncing) ? 1 : 0.5;">
                <span x-show="!syncing">Sync now</span>
                <span x-show="syncing" x-cloak>Syncing…</span>
            </button>
        </div>
        <template x-if="queuedItems.length === 0">
            <p style="padding: 18px 14px; margin: 0; text-align: center;
                      font-size: 12px; color: var(--color-ink-mute);">
                Nothing waiting. New reservations you save while offline show up here.
            </p>
        </template>
        <template x-for="item in queuedItems" :key="item.offline_key">
            <div style="padding: 10px 14px; border-bottom: 1px solid var(--color-line-soft);
                        display: grid; gap: 4px;">
                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 8px;">
                    <span x-text="item.guest_name" style="font-weight: 500; font-size: 13px;"></span>
                    <span x-text="item.status.charAt(0).toUpperCase() + item.status.slice(1)"
                          :style="'font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase;'
                                + 'padding: 2px 8px; border-radius: 999px;'
                                + (item.status === 'synced' ? 'background: #DCFCE7; color: #166534;'
                                : item.status === 'conflict' ? 'background: #FEE2E2; color: #9F1F1F;'
                                : item.status === 'failed' ? 'background: #FEE2E2; color: #9F1F1F;'
                                : item.status === 'syncing' ? 'background: #DBEAFE; color: #1E40AF;'
                                : 'background: #FEF3C7; color: #78350F;')"></span>
                </div>
                <div style="font-size: 11.5px; color: var(--color-ink-mute);">
                    <span x-text="item.checkin_date + ' → ' + item.checkout_date"></span>
                </div>
                <div x-show="item.last_error" x-cloak
                     style="font-size: 11.5px; color: #9F1F1F; margin-top: 2px;"
                     x-text="item.last_error"></div>
                <div style="display: flex; gap: 8px; margin-top: 4px;">
                    <button type="button" x-show="item.status === 'failed' || item.status === 'conflict'"
                            @click="retryOne(item.offline_key)"
                            style="background: transparent; border: 1px solid var(--color-line);
                                   padding: 3px 10px; border-radius: 6px; font-size: 11.5px;
                                   cursor: pointer; font-family: inherit;">
                        Retry
                    </button>
                    <button type="button"
                            @click="forgetOne(item.offline_key)"
                            style="background: transparent; border: 0; color: var(--color-ink-mute);
                                   padding: 3px 6px; border-radius: 6px; font-size: 11.5px;
                                   cursor: pointer; font-family: inherit; text-decoration: underline;">
                        Forget
                    </button>
                </div>
            </div>
        </template>
    </div>

    {{-- Failure card. Sticks around until retry / dismiss. --}}
    <div x-show="state === 'failed'" x-cloak
         x-transition:enter="transition ease-out duration-180"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         data-testid="wire-sync-failed"
         style="pointer-events: auto;
                display: flex; align-items: center; gap: 14px;
                padding: 10px 12px 10px 16px;
                background: #FEF2F2;
                color: #9F1F1F;
                border: 1px solid #FCA5A5;
                border-radius: 14px;
                font-size: 13px; font-weight: 500;
                max-width: min(94vw, 460px);
                box-shadow: 0 14px 30px -14px rgba(159, 31, 31, 0.28);">
        <span aria-hidden
              style="display: inline-flex; align-items: center; justify-content: center;
                     width: 20px; height: 20px; border-radius: 999px;
                     background: #FEE2E2; flex-shrink: 0;">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 3v3.5M6 8.5v.2"/>
            </svg>
        </span>
        <span style="flex: 1; line-height: 1.35;">
            <strong style="display: block; font-weight: 600;">Connection issue</strong>
            <span x-text="failMessage" style="font-size: 12px; opacity: 0.9;"></span>
        </span>
        <button type="button"
                @click="retry()"
                data-testid="wire-sync-retry"
                style="background: #9F1F1F; color: #fff;
                       padding: 6px 12px; border: 0;
                       border-radius: 8px;
                       font-size: 12px; font-weight: 600;
                       cursor: pointer;
                       font-family: inherit;">
            Retry
        </button>
        <button type="button"
                @click="dismiss()"
                data-testid="wire-sync-dismiss"
                aria-label="Dismiss"
                style="background: transparent; border: 0;
                       color: #9F1F1F;
                       cursor: pointer;
                       padding: 4px; line-height: 1;
                       font-family: inherit; font-size: 16px;
                       opacity: 0.7;">
            ×
        </button>
    </div>

    <style>
        @@keyframes kv-wire-sync-pulse {
            0%, 100% { opacity: 0.55; transform: scale(1); }
            50%      { opacity: 1;    transform: scale(1.25); }
        }
    </style>
</div>

<script>
    window.wireSyncStatus = function () {
        return {
            state: 'idle',           // 'idle' | 'syncing' | 'failed'
            inflight: 0,
            failMessage: '',
            _pendingShowTimer: null,
            // Offline reservation queue state — mirrored from IndexedDB.
            queued: 0,
            queuedItems: [],
            queuePanelOpen: false,
            syncing: false,
            isOnline: navigator.onLine,

            init() {
                const self = this;
                // Wire the sync-status pill's own state observers ONCE
                // (Livewire hook is idempotent; offline listeners fire
                // per event, so binding both here on init is fine even
                // if the layout re-renders).
                if (!window.__kvWireSyncWired) {
                    window.__kvWireSyncWired = true;
                    this._wireLivewireHook();
                }
                this._wireOfflineHooks();
                this.refreshQueue();
            },

            _wireLivewireHook() {
                const self = this;
                document.addEventListener('livewire:init', function () {
                    if (!window.Livewire || typeof Livewire.hook !== 'function') return;

                    Livewire.hook('request', function (payload) {
                        self._onRequestStart();

                        // Some Livewire versions expose `respond`, `succeed`,
                        // `fail` as callback registrars on the payload; others
                        // pass them as top-level keys. Guard both.
                        if (typeof payload.succeed === 'function') {
                            payload.succeed(function () { self._onRequestEnd(true); });
                        }
                        if (typeof payload.fail === 'function') {
                            payload.fail(function (details) {
                                self._onRequestEnd(false, details);
                            });
                        }
                    });
                });

                // Browser-level offline signal — flip straight into
                // "connection issue" even before a Livewire call fails.
                window.addEventListener('offline', function () {
                    self.isOnline = false;
                    self.state = 'failed';
                    self.failMessage = 'You appear to be offline. Reservations you save now will queue locally and sync when the connection returns.';
                });
                window.addEventListener('online', function () {
                    self.isOnline = true;
                    if (self.state === 'failed'
                        && self.failMessage.indexOf('offline') !== -1) {
                        // Auto-clear the "offline" banner once the connection
                        // is back — the queued reservations start draining
                        // automatically via the offline-reservations module.
                        self.state = 'idle';
                        self.failMessage = '';
                    }
                });
            },

            _wireOfflineHooks() {
                const self = this;
                // Refresh the queue chip whenever an item is queued /
                // synced / forgotten. Dispatched by the
                // offline-reservations module.
                window.addEventListener('kv-queue-changed', function () {
                    self.refreshQueue();
                });
                // Toast when the reserve form queues one locally so
                // the operator has clear confirmation.
                window.addEventListener('kv-offline-reserve-queued', function () {
                    self.refreshQueue();
                    // Use the success-side notify modal if present;
                    // otherwise a lightweight console line.
                    try {
                        window.dispatchEvent(new CustomEvent('notify', {
                            detail: {
                                title: 'Saved locally',
                                message: 'The reservation will sync automatically when the connection returns.',
                                kind: 'ok',
                            },
                        }));
                    } catch (_) {}
                });
                window.addEventListener('kv-offline-reserve-error', function (e) {
                    try {
                        window.dispatchEvent(new CustomEvent('notify', {
                            detail: {
                                title: 'Could not save offline',
                                message: e?.detail?.message || 'Please try again.',
                                kind: 'err',
                            },
                        }));
                    } catch (_) {}
                });
            },

            async refreshQueue() {
                const api = window.KevernaOfflineReservations;
                if (!api) { this.queued = 0; this.queuedItems = []; return; }
                try {
                    const items = await api.listQueued();
                    // Hide 'synced' items from the count — they're
                    // scheduled for deletion in a few seconds and the
                    // operator doesn't need to see them.
                    this.queuedItems = items;
                    this.queued = items.filter(function (i) { return i.status !== 'synced'; }).length;
                } catch (_) {
                    this.queued = 0;
                    this.queuedItems = [];
                }
            },

            async syncNow() {
                const api = window.KevernaOfflineReservations;
                if (!api || this.syncing) return;
                this.syncing = true;
                try { await api.drainQueue({ force: true }); } catch (_) {}
                this.syncing = false;
                await this.refreshQueue();
            },

            async retryOne(offlineKey) {
                // The drain loop respects status filtering, so bumping
                // this one back to 'queued' before draining is the
                // clean route. Done by re-queuing over itself.
                const api = window.KevernaOfflineReservations;
                if (!api) return;
                const items = await api.listQueued();
                const item = items.find(function (i) { return i.offline_key === offlineKey; });
                if (!item) return;
                item.status = 'queued';
                await api.queueReservation(item);
                await this.syncNow();
            },

            async forgetOne(offlineKey) {
                const api = window.KevernaOfflineReservations;
                if (!api) return;
                await api.forgetRecord(offlineKey);
                await this.refreshQueue();
            },

            _onRequestStart() {
                this.inflight++;
                // Delay showing so quick round-trips don't flash.
                if (this._pendingShowTimer) clearTimeout(this._pendingShowTimer);
                const self = this;
                this._pendingShowTimer = setTimeout(function () {
                    if (self.inflight > 0 && self.state !== 'failed') {
                        self.state = 'syncing';
                    }
                }, 250);
            },

            _onRequestEnd(ok, details) {
                this.inflight = Math.max(0, this.inflight - 1);
                if (this._pendingShowTimer) {
                    clearTimeout(this._pendingShowTimer);
                    this._pendingShowTimer = null;
                }
                if (!ok) {
                    this.state = 'failed';
                    // Livewire sends the response body in `content`, sometimes
                    // as HTML. Keep the operator-facing message generic; the
                    // technical detail is in the console for debugging.
                    if (details && details.status === 419) {
                        this.failMessage = 'Session expired. Reload the page and try again.';
                    } else if (details && details.status >= 500) {
                        this.failMessage = 'Server hiccup. Press Retry to resend your last change.';
                    } else if (!navigator.onLine) {
                        this.failMessage = 'You appear to be offline. Reconnect and press Retry.';
                    } else {
                        this.failMessage = 'Could not reach the server. Press Retry to resend.';
                    }
                    return;
                }
                if (this.inflight === 0) {
                    // Small hold so the pill doesn't disappear mid-transition.
                    const self = this;
                    setTimeout(function () {
                        if (self.inflight === 0 && self.state !== 'failed') {
                            self.state = 'idle';
                        }
                    }, 180);
                }
            },

            retry() {
                // Refresh every mounted Livewire component so their state
                // re-hydrates from the server. Alpine (x-model + @@entangle)
                // state persists across refreshes, so the operator's typed
                // fields don't clear.
                try {
                    if (window.Livewire && typeof Livewire.all === 'function') {
                        Livewire.all().forEach(function (c) {
                            if (typeof c.$refresh === 'function') c.$refresh();
                        });
                    } else if (window.Livewire && typeof Livewire.dispatch === 'function') {
                        // Fallback — refresh the currently focused component.
                        Livewire.dispatch('$refresh');
                    }
                } catch (e) { /* swallow; user can reload */ }
                this.state = this.inflight > 0 ? 'syncing' : 'idle';
                this.failMessage = '';
            },

            dismiss() {
                this.state = this.inflight > 0 ? 'syncing' : 'idle';
                this.failMessage = '';
            },
        };
    };
</script>
