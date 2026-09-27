{{-- ── Cmd+K command palette ──────────────────────────────────────────
     Global keyboard-driven navigator for the operator dashboard. Open
     with ⌘K / Ctrl+K, type to fuzzy-match against every sidebar nav
     item + a small static "quick action" list. Enter navigates via
     Livewire wire:navigate for the SPA transition, or a plain <a>
     click as fallback. Esc / click-outside closes.

     Mounts once at the layout level so every dashboard page shares
     the same instance + keyboard handler. Reuses `$sidebarNavItems`
     (already computed for the sidebar) so a new item added upstream
     shows up here for free. --}}

<div x-data="commandPalette({
        items: {{ \Illuminate\Support\Js::from($sidebarNavItems ?? []) }},
        quickActions: {{ \Illuminate\Support\Js::from([
            ['id' => 'new-booking',  'label' => 'New booking',           'hint' => 'Take a walk-in reservation',              'route' => 'tenant.bookings.create'],
            ['id' => 'new-review',   'label' => 'Add a review',          'hint' => 'Log a guest review',                      'route' => 'tenant.reviews.create'],
            ['id' => 'today',        'label' => 'Today on the calendar', 'hint' => 'Jump to today',                           'route' => 'tenant.calendar'],
            ['id' => 'guest-req',    'label' => 'Guest requests',        'hint' => 'Live queue',                              'route' => 'tenant.guest-requests.index'],
            ['id' => 'approvals',    'label' => 'Approvals',             'hint' => 'Low-trust portal taps waiting for a tap', 'route' => 'tenant.approvals.index'],
        ]) }},
     })"
     x-init="init()"
     x-on:keydown.window="onWindowKey($event)"
     x-cloak
     class="kv-palette-root"
     data-testid="command-palette">

    <div x-show="open"
         x-transition.opacity.duration.150ms
         class="kv-palette-scrim"
         x-on:click="close()"
         aria-hidden="true"></div>

    <div x-show="open"
         x-transition.opacity.duration.150ms
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="kv-palette-shell"
         role="dialog"
         aria-modal="true"
         aria-label="Command palette">

        <div class="kv-palette-search">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="7" cy="7" r="4"/>
                <path d="M10 10l3 3"/>
            </svg>
            <input type="text"
                   x-ref="input"
                   x-model="query"
                   x-on:input="onInput()"
                   x-on:keydown.down.prevent="move(1)"
                   x-on:keydown.up.prevent="move(-1)"
                   x-on:keydown.enter.prevent="commit()"
                   x-on:keydown.escape.prevent="close()"
                   placeholder="Jump to any page, action, or setting"
                   aria-label="Command palette search"
                   spellcheck="false"
                   autocomplete="off"
                   data-testid="command-palette-input">
            <kbd class="kv-palette-kbd">esc</kbd>
        </div>

        <div class="kv-palette-results"
             x-ref="results"
             role="listbox"
             aria-label="Search results">
            <template x-if="filtered.length === 0">
                <div class="kv-palette-empty">
                    <span>No matches. Try a shorter term.</span>
                </div>
            </template>

            <template x-for="(group, gi) in grouped" :key="gi">
                <div class="kv-palette-group">
                    <div class="kv-palette-group-label" x-text="group.label"></div>
                    <template x-for="item in group.items" :key="item._key">
                        <a :href="item.href"
                           :data-testid="'command-palette-item-' + item._key"
                           :class="item._index === activeIndex ? 'kv-palette-item is-active' : 'kv-palette-item'"
                           x-on:mouseenter="activeIndex = item._index"
                           x-on:click.prevent="commitItem(item)"
                           role="option"
                           :aria-selected="item._index === activeIndex ? 'true' : 'false'">
                            <span class="kv-palette-item-label" x-text="item.label"></span>
                            <span class="kv-palette-item-hint" x-text="item.hint || item.section || ''"></span>
                        </a>
                    </template>
                </div>
            </template>
        </div>

        <div class="kv-palette-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
            <span><kbd>↵</kbd> open</span>
            <span><kbd>esc</kbd> close</span>
        </div>
    </div>
</div>

@once
    <style>
        .kv-palette-root [x-cloak] { display: none !important; }
        .kv-palette-scrim {
            position: fixed; inset: 0;
            background: rgba(11, 9, 6, 0.44);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            z-index: 90;
        }
        .kv-palette-shell {
            position: fixed;
            top: 15vh;
            left: 50%;
            transform: translateX(-50%);
            width: min(640px, calc(100vw - 32px));
            max-height: 70vh;
            display: flex; flex-direction: column;
            background: var(--color-paper, #FCFAF5);
            color: var(--color-ink, #14171A);
            border-radius: 14px;
            box-shadow:
                0 24px 60px -12px rgba(11, 9, 6, 0.35),
                0 0 0 1px rgba(20, 23, 26, 0.08);
            overflow: hidden;
            z-index: 91;
        }
        .kv-palette-search {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid rgba(20, 23, 26, 0.06);
        }
        .kv-palette-search svg {
            width: 16px; height: 16px;
            color: var(--color-ink-mute, #7A7F87);
            flex-shrink: 0;
        }
        .kv-palette-search input {
            flex: 1;
            font-family: var(--font-body);
            font-size: 15px;
            font-weight: 500;
            background: transparent;
            border: 0;
            outline: 0;
            color: var(--color-ink);
        }
        .kv-palette-search input::placeholder {
            color: var(--color-ink-mute, #7A7F87);
            font-weight: 400;
        }
        .kv-palette-kbd,
        .kv-palette-foot kbd {
            display: inline-flex;
            align-items: center; justify-content: center;
            min-width: 22px; height: 20px;
            padding: 0 5px;
            border-radius: 4px;
            background: rgba(20, 23, 26, 0.06);
            color: var(--color-ink-soft, #3D434A);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10.5px;
            letter-spacing: 0.04em;
        }
        .kv-palette-results {
            flex: 1;
            overflow-y: auto;
            padding: 8px 8px 12px;
        }
        .kv-palette-empty {
            padding: 32px 20px;
            text-align: center;
            color: var(--color-ink-mute, #7A7F87);
            font-size: 13px;
        }
        .kv-palette-group + .kv-palette-group { margin-top: 8px; }
        .kv-palette-group-label {
            padding: 10px 12px 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--color-ink-mute, #7A7F87);
        }
        .kv-palette-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 12px;
            border-radius: 8px;
            color: var(--color-ink, #14171A);
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: background 100ms ease;
        }
        .kv-palette-item:hover,
        .kv-palette-item.is-active {
            background: var(--color-accent-soft, rgba(201, 148, 65, 0.14));
        }
        .kv-palette-item-hint {
            font-size: 11.5px;
            color: var(--color-ink-mute, #7A7F87);
            font-weight: 400;
        }
        .kv-palette-foot {
            display: flex;
            gap: 16px;
            padding: 8px 16px;
            border-top: 1px solid rgba(20, 23, 26, 0.06);
            font-size: 11px;
            color: var(--color-ink-mute, #7A7F87);
            background: rgba(20, 23, 26, 0.02);
        }
        .kv-palette-foot span { display: inline-flex; align-items: center; gap: 5px; }

        /* Dark palette overlay when the dashboard runs on ink surfaces
           (later) — keeping cream-on-cream today but ready for the flip. */
        @media (prefers-color-scheme: dark) {
            .kv-palette-shell {
                background: #1B1814;
                color: #F5F1E8;
                box-shadow:
                    0 24px 60px -12px rgba(0, 0, 0, 0.5),
                    0 0 0 1px rgba(245, 241, 232, 0.08);
            }
            .kv-palette-search { border-color: rgba(245, 241, 232, 0.08); }
            .kv-palette-search input { color: #F5F1E8; }
            .kv-palette-foot { background: rgba(245, 241, 232, 0.03); border-color: rgba(245, 241, 232, 0.08); }
            .kv-palette-item.is-active,
            .kv-palette-item:hover { background: rgba(245, 241, 232, 0.06); }
        }
    </style>

    <script>
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('commandPalette', (config) => ({
                open: false,
                query: '',
                activeIndex: 0,
                items: [],
                quickActions: [],
                filtered: [],
                grouped: [],

                init() {
                    // Normalise the sidebar items map into a searchable list.
                    // The keys are URL paths; the values carry label +
                    // section + href, which is exactly what we need.
                    this.items = Object.values(config.items || {}).map(it => ({
                        _kind: 'nav',
                        _key:  'nav-' + (it.route || it.path || it.label).replace(/[^a-z0-9]/gi, '-').toLowerCase(),
                        label: it.label,
                        hint:  it.section,
                        section: it.section,
                        href:  it.href,
                    }));
                    this.quickActions = (config.quickActions || []).map(qa => ({
                        _kind: 'action',
                        _key:  'action-' + qa.id,
                        label: qa.label,
                        hint:  qa.hint,
                        // The route is looked up client-side against the
                        // items map; falls back to a hard route() print
                        // by keeping href empty so a missing route hides
                        // gracefully.
                        href:  this._resolveActionHref(qa),
                    })).filter(qa => qa.href);
                    this.applyFilter();
                },

                _resolveActionHref(qa) {
                    // Prefer the same path from $sidebarNavItems (routes
                    // that already carry the tenant subdomain resolved).
                    const hit = Object.values(config.items || {}).find(
                        i => i.route === qa.route,
                    );
                    return hit ? hit.href : '';
                },

                onWindowKey(e) {
                    // ⌘K / Ctrl+K opens the palette from anywhere. ⌘/ also
                    // as an inspiration from Superhuman + Linear.
                    const isMac = /Mac|iPhone|iPod|iPad/.test(navigator.platform);
                    const cmd = isMac ? e.metaKey : e.ctrlKey;
                    if (cmd && (e.key === 'k' || e.key === 'K' || e.key === '/')) {
                        // Don't hijack the key inside contenteditable
                        // or textareas — a manager writing a note should
                        // still be able to select-all via ⌘K on rare
                        // keyboards, though that combo is uncommon.
                        const tag = (e.target?.tagName || '').toUpperCase();
                        if (['TEXTAREA'].includes(tag)) return;
                        e.preventDefault();
                        this.toggle();
                    }
                },

                toggle()  { this.open ? this.close() : this.openIt(); },
                openIt()  {
                    this.open = true;
                    this.query = '';
                    this.applyFilter();
                    this.$nextTick(() => this.$refs.input?.focus());
                },
                close()   { this.open = false; },

                onInput() { this.applyFilter(); this.activeIndex = 0; },

                applyFilter() {
                    const q = this.query.trim().toLowerCase();
                    const all = [...this.quickActions, ...this.items];
                    let matched;
                    if (q === '') {
                        matched = all;
                    } else {
                        matched = all.filter(it => {
                            const hay = (it.label + ' ' + (it.hint || '') + ' ' + (it.section || '')).toLowerCase();
                            // Simple sub-string match plus a lenient
                            // "acronym" pass so "gr" matches "Guest requests".
                            if (hay.includes(q)) return true;
                            const acronym = it.label.split(/\s+/).map(w => w[0] || '').join('').toLowerCase();
                            return acronym.startsWith(q);
                        });
                    }
                    // Number each entry for arrow-key nav across groups.
                    matched.forEach((it, i) => { it._index = i; });
                    this.filtered = matched;

                    // Group by kind, preserving order within each group.
                    const actions = matched.filter(it => it._kind === 'action');
                    const nav = matched.filter(it => it._kind === 'nav');
                    this.grouped = [];
                    if (actions.length) this.grouped.push({ label: 'Actions',    items: actions });
                    if (nav.length)     this.grouped.push({ label: 'Navigate to', items: nav });
                },

                move(delta) {
                    if (this.filtered.length === 0) return;
                    let next = this.activeIndex + delta;
                    if (next < 0) next = this.filtered.length - 1;
                    if (next >= this.filtered.length) next = 0;
                    this.activeIndex = next;
                    // Keep the highlighted row inside the scroll port.
                    this.$nextTick(() => {
                        const rows = this.$refs.results?.querySelectorAll('.kv-palette-item');
                        rows?.[next]?.scrollIntoView({ block: 'nearest' });
                    });
                },

                commit() {
                    const it = this.filtered[this.activeIndex];
                    if (it) this.commitItem(it);
                },

                commitItem(it) {
                    if (!it?.href) return;
                    this.close();
                    // Use Livewire's wire:navigate SPA transition when
                    // available so the click feels instant + preserves
                    // the sidebar scroll position; fall back to a hard
                    // navigation otherwise.
                    if (window.Livewire?.navigate) {
                        window.Livewire.navigate(it.href);
                    } else {
                        window.location.assign(it.href);
                    }
                },
            }));
        });
    </script>
@endonce
