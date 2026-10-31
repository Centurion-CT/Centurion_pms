@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Settings</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Property settings
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Property name, address and contact details shown to guests.
        </p>
    </div>

    @include('livewire.dashboard.settings._tabs', ['active' => 'property'])

    {{-- Two-column: form on the left, sticky section nav on the right.
         Sections group into 4 collapsible <details> so operators can
         hide groups they don't need. Sidebar click opens the target
         group + smooth-scrolls to the section, so the nav works even
         when groups are collapsed. Falls back to a single column
         below the md breakpoint. --}}
    @php
        $groups = [
            ['id' => 'group-general',  'label' => 'General',  'sections' => [
                ['id' => 'section-site',   'label' => 'Public site'],
                ['id' => 'section-basics', 'label' => 'Basics'],
            ]],
            ['id' => 'group-location', 'label' => 'Location', 'sections' => [
                ['id' => 'section-address', 'label' => 'Address & fence'],
                ['id' => 'section-nearby',  'label' => 'Places nearby'],
            ]],
            ['id' => 'group-policies', 'label' => 'Policies', 'sections' => [
                ['id' => 'section-checkout',    'label' => 'Check-in/out'],
                ['id' => 'section-house-rules', 'label' => 'House rules'],
            ]],
            ['id' => 'group-business', 'label' => 'Business', 'sections' => [
                ['id' => 'section-corporates', 'label' => 'Corporates'],
                ['id' => 'section-contact',    'label' => 'Contact'],
                ['id' => 'section-payouts',    'label' => 'Payouts'],
            ]],
        ];
    @endphp

    {{-- Scoped redesign for this form only - .lp-settings-modern is on
         the outer <form>. Nothing here leaks to other pages. --}}
    <style>
        /* ── Layout: form column + sticky sidebar ────────────────── */
        .lp-settings-modern.lp-settings-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (min-width: 900px) {
            .lp-settings-modern.lp-settings-layout {
                grid-template-columns: minmax(0, 1fr) 236px;
                gap: 32px;
            }
        }

        /* ── Sidebar nav ───────────────────────────────────────── */
        .lp-settings-modern .lp-settings-nav { display: none; }
        @media (min-width: 900px) {
            .lp-settings-modern .lp-settings-nav {
                display: block;
                position: sticky;
                top: 20px;
                max-height: calc(100vh - 40px);
                overflow-y: auto;
                padding: 6px 0;
            }
        }
        .lp-settings-modern .lp-settings-nav-group { margin-bottom: 18px; }
        .lp-settings-modern .lp-settings-nav-title {
            font-size: 10.5px; font-weight: 700; letter-spacing: 0.10em;
            text-transform: uppercase; color: var(--color-ink-mute);
            margin: 0 0 8px; padding: 0 12px;
        }
        .lp-settings-modern .lp-settings-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px; font-size: 13px;
            color: var(--color-ink-soft); border-radius: 8px;
            text-decoration: none; margin-bottom: 2px;
            transition: background 120ms, color 120ms;
        }
        .lp-settings-modern .lp-settings-nav-link::before {
            content: ''; width: 3px; height: 14px; border-radius: 3px;
            background: transparent; flex-shrink: 0;
            transition: background 120ms;
        }
        .lp-settings-modern .lp-settings-nav-link:hover {
            background: var(--color-line-soft); color: var(--color-ink);
        }
        .lp-settings-modern .lp-settings-nav-link.is-active {
            background: var(--color-accent-soft); color: var(--color-ink);
            font-weight: 600;
        }
        .lp-settings-modern .lp-settings-nav-link.is-active::before {
            background: var(--color-accent-deep);
        }

        /* ── Section groups (formerly accordions) ────────────── */
        .lp-settings-modern .lp-group {
            border: 1px solid var(--color-line);
            border-radius: 14px;
            margin-bottom: 14px;
            background: var(--color-card);
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(20, 23, 26, 0.03);
        }
        .lp-settings-modern .lp-group-title {
            margin: 0;
            padding: 16px 20px;
            font-weight: 600;
            color: var(--color-ink);
            font-size: 15px;
            border-bottom: 1px solid var(--color-line-soft);
        }
        .lp-settings-modern .lp-group-body {
            padding: 20px;
            display: grid;
            gap: 24px;
        }
        .lp-settings-modern .lp-section { scroll-margin-top: 24px; }

        /* ── Section cards (formerly <fieldset>) ─────────────── */
        .lp-settings-modern .lp-fieldset {
            border: none;
            padding: 0;
            margin: 0;
            background: transparent;
        }
        .lp-settings-modern .lp-fieldset > legend {
            display: block;
            width: 100%;
            padding: 0 0 10px;
            margin: 0 0 16px;
            font-family: var(--font-body, inherit);
            font-size: 13.5px;
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
            color: var(--color-ink);
            border-bottom: 1px solid var(--color-line-soft);
        }

        /* ── Labels ─────────────────────────────────────────── */
        .lp-settings-modern .lp-form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
            color: var(--color-ink);
            margin-bottom: 6px;
        }
        .lp-settings-modern .lp-form-help {
            font-size: 12.5px;
            color: var(--color-ink-mute);
            line-height: 1.55;
            margin-top: 6px;
        }
        .lp-settings-modern .lp-form-error {
            font-size: 12px;
            color: #B85C42;
            margin-top: 6px;
        }

        /* ── Inputs, textareas, selects ─────────────────────── */
        .lp-settings-modern .lp-input,
        .lp-settings-modern select.lp-input,
        .lp-settings-modern textarea.lp-input {
            border: 1px solid var(--color-line);
            background: #FFFEFB;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 14px;
            color: var(--color-ink);
            transition: border-color 140ms, box-shadow 140ms, background 140ms;
            width: 100%;
            box-sizing: border-box;
        }
        .lp-settings-modern .lp-input::placeholder {
            color: var(--color-ink-mute); opacity: 0.55;
        }
        .lp-settings-modern .lp-input:hover:not(:focus) {
            border-color: rgba(20, 23, 26, 0.18);
        }
        .lp-settings-modern .lp-input:focus,
        .lp-settings-modern select.lp-input:focus,
        .lp-settings-modern textarea.lp-input:focus {
            border-color: var(--color-accent-deep);
            outline: 0;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(138, 174, 58, 0.15);
        }
        .lp-settings-modern textarea.lp-input { min-height: 92px; resize: vertical; }

        /* ── Radio-row cards (Inventory model, Early check-out) ── */
        .lp-settings-modern .lp-radio-row {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 14px;
            border: 1px solid var(--color-line);
            border-radius: 10px;
            background: #FFFEFB;
            font-size: 13.5px;
            cursor: pointer;
            transition: border-color 140ms, background 140ms;
        }
        .lp-settings-modern .lp-radio-row:hover {
            border-color: rgba(20, 23, 26, 0.20);
        }
        .lp-settings-modern .lp-radio-row:has(input[type="radio"]:checked) {
            border-color: var(--color-accent-deep);
            background: var(--color-accent-soft);
        }
        .lp-settings-modern .lp-radio-row input[type="radio"] {
            margin-top: 2px;
            accent-color: var(--color-accent-deep);
            width: 16px; height: 16px;
            flex-shrink: 0;
        }
        .lp-settings-modern .lp-radio-row strong { font-weight: 600; }

        /* ── Chip / preset cards (fence presets) ─────────────── */
        .lp-settings-modern .lp-chip {
            display: block;
            padding: 12px 14px;
            text-align: left;
            border: 1px solid var(--color-line);
            border-radius: 10px;
            background: #FFFEFB;
            color: var(--color-ink);
            cursor: pointer;
            transition: border-color 140ms, background 140ms;
            font-family: inherit;
        }
        .lp-settings-modern .lp-chip:hover:not(.lp-chip--on) {
            border-color: rgba(20, 23, 26, 0.20);
        }
        .lp-settings-modern .lp-chip.lp-chip--on {
            border-color: var(--color-accent-deep);
            background: var(--color-accent-soft);
        }

        /* ── Section save button (sticky floating) ───────────── */
        .lp-settings-modern .lp-form-actions-sticky {
            position: sticky;
            bottom: 12px;
            z-index: 4;
            margin-top: 8px;
            padding: 12px 0;
            background: linear-gradient(to top,
                rgba(252, 250, 245, 0.98) 20%,
                rgba(252, 250, 245, 0.85) 65%,
                rgba(252, 250, 245, 0) 100%);
            border-top: none;
        }

        /* ── Input-with-suffix (percent, hours) ──────────────── */
        .lp-settings-modern .lp-input-suffix {
            position: relative;
        }
        .lp-settings-modern .lp-input-suffix > span {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            font-size: 13px; color: var(--color-ink-mute); pointer-events: none;
        }
    </style>

    {{-- Alpine factory for the "Use my current location" button.
         Defined here (not inline on x-data) because Alpine's expression
         parser flattens the attribute onto one line, so a `//` comment
         inside would swallow the rest of the code. Also easier to read. --}}
    <script>
        window.propertyCoordCapture = function (wire) {
            return {
                status: '',
                busy: false,
                useCurrent() {
                    if (! ('geolocation' in navigator)) {
                        this.status = "error:Your browser doesn't support geolocation.";
                        return;
                    }
                    this.busy = true;
                    this.status = 'wait:Waiting for your browser to share your location…';
                    var self = this;
                    navigator.geolocation.getCurrentPosition(
                        function (pos) {
                            var round = function (n) { return Math.round(n * 1e6) / 1e6; };
                            wire.set('latitude',  String(round(pos.coords.latitude)));
                            wire.set('longitude', String(round(pos.coords.longitude)));
                            self.busy = false;
                            self.status = 'ok:Pinned to your current location. Save to apply.';
                        },
                        function (err) {
                            self.busy = false;
                            var msg = err && err.code === 1
                                ? 'You blocked location access - check your browser permissions and try again.'
                                : "Couldn't get your location. Try again, or paste from Google Maps.";
                            self.status = 'error:' + msg;
                        },
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
                    );
                },
            };
        };
    </script>

    <form wire:submit="save" class="lp-settings-modern lp-settings-layout"
          data-testid="property-settings-form">
        <div class="lp-settings-form-body">

        <section id="group-general" class="lp-group">
            <h2 class="lp-group-title">General</h2>
            <div class="lp-group-body">
        {{-- Publish switch, flips the public tenant subdomain between
             the auto-generated landing and the "Coming soon" placeholder
             served by PublicLandingController. Kept at the top so it's
             the first thing operators see. When off, the whole card
             turns warning-red so nobody misses that their site is
             hidden from guests. --}}
        @php
            $publishAccent   = $isLive ? 'var(--color-accent-deep)' : '#B85C42';
            $publishBorderColor = $isLive ? 'var(--color-line)' : 'rgba(184, 92, 66, 0.35)';
            $publishTint     = $isLive
                ? 'linear-gradient(135deg, rgba(173, 203, 82, 0.08), rgba(173, 203, 82, 0.02))'
                : 'linear-gradient(135deg, rgba(224, 122, 95, 0.08), rgba(224, 122, 95, 0.02))';
            $publishStatus   = $isLive ? 'Live' : 'Not visible';
            $publishHeadline = $isLive
                ? 'Your booking site is live - guests can browse and book.'
                : 'Your booking site is hidden. Guests see a "coming soon" placeholder.';
            $publishAction   = $isLive ? 'Turn site off' : 'Publish site';
        @endphp
        <section id="section-site" class="lp-section" data-testid="publish-fieldset"
                 style="border: 1px solid {{ $publishBorderColor }}; background: {{ $publishTint }};
                        border-radius: 14px; padding: 20px 22px;">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div style="flex: 1; min-width: 240px;">
                    <div class="flex items-center gap-2 mb-2" data-testid="publish-status-row">
                        <span data-testid="publish-status"
                              style="display:inline-flex; align-items:center; gap:6px;
                                     padding: 3px 10px; border-radius: 999px; font-size: 11px;
                                     letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;
                                     background: {{ $isLive ? 'rgba(138, 174, 58, 0.14)' : 'rgba(184, 92, 66, 0.14)' }};
                                     color: {{ $publishAccent }};">
                            <span style="width:6px; height:6px; border-radius:50%; background: {{ $publishAccent }}; display:inline-block;"></span>
                            {{ $publishStatus }}
                        </span>
                        <span style="font-size: 13px; font-weight: 600; color: var(--color-ink);">
                            Public booking site
                        </span>
                    </div>
                    <p style="font-size: 13.5px; color: var(--color-ink); line-height: 1.5; margin: 0;">
                        {{ $publishHeadline }}
                    </p>
                    @if ($publicUrl)
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
                           class="lp-mono"
                           style="display: inline-flex; align-items: center; gap: 4px;
                                  font-size: 12px; color: var(--color-ink-soft);
                                  text-decoration: none; margin-top: 8px;
                                  padding: 4px 8px; border-radius: 6px;
                                  background: rgba(20, 23, 26, 0.04); transition: background 120ms;">
                            {{ $publicUrl }}
                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
                                <path d="M4.5 2h5.5v5.5M10 2L4.5 7.5M8 6.5V10H2V4h3.5"/>
                            </svg>
                        </a>
                    @endif
                </div>
                <button type="button"
                        role="switch"
                        aria-checked="{{ $isLive ? 'true' : 'false' }}"
                        aria-label="Publish booking site"
                        data-testid="property-is-live"
                        wire:click="$toggle('isLive')"
                        style="position: relative; width: 52px; height: 30px; border-radius: 999px;
                               border: 1px solid {{ $isLive ? 'var(--color-accent-deep)' : 'rgba(184, 92, 66, 0.5)' }};
                               background: {{ $isLive ? 'var(--color-accent-deep)' : 'rgba(184, 92, 66, 0.18)' }};
                               cursor: pointer; transition: background 160ms ease, border-color 160ms ease;
                               padding: 0; flex-shrink: 0;">
                    <span aria-hidden="true"
                          style="position: absolute; top: 3px; left: {{ $isLive ? '25px' : '3px' }};
                                 width: 22px; height: 22px; border-radius: 50%;
                                 background: #fff;
                                 box-shadow: 0 1px 3px rgba(0,0,0,0.20);
                                 transition: left 160ms ease;"></span>
                </button>
            </div>

            <p style="font-size: 12px; color: var(--color-ink-mute); line-height: 1.55; margin: 12px 0 0;">
                Keep it off while you're still finalising rooms, photos and rates.
                <span data-testid="publish-action-hint"
                      style="color: {{ $publishAccent }}; font-weight: 600;">
                    {{ $publishAction }} · changes apply after you save.
                </span>
            </p>
        </section>

        {{-- Keverna's "Stays marketplace" listing toggle and public slug
             lived here. Centurion has no marketplace: each business's
             own booking site lists its live buildings (step 6). --}}

        {{-- Basics - property name + type + inventory model + description.
             Wrapped in a section anchor so the sticky sub-nav can jump
             to it. The individual controls are NOT inside a fieldset
             legend so they don't visually double-nest. --}}
        <div id="section-basics" class="lp-section" style="display: grid; gap: 22px;">
        <div>
            <label for="prop-name" class="lp-form-label">Property name</label>
            <input id="prop-name" type="text" wire:model="name" required maxlength="120"
                   data-testid="property-name-input" class="lp-input">
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="prop-type" class="lp-form-label">Property type</label>
            <select id="prop-type" wire:model.live="type" class="lp-input" data-testid="property-type">
                @foreach ($propertyTypes as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>
            <p class="lp-form-help mt-2">
                Switches which modules show in the sidebar.
                Workspace types unlock co-working tools; Residential unlocks lease tools.
            </p>
            @error('type') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Currency the property renders amounts in. Grouped by
             region so operators can find their local currency fast.
             Display-only for now (no FX conversion), but every ₦
             hardcoded elsewhere in the app resolves through this
             pick at render time. --}}
        <div>
            <label for="prop-currency" class="lp-form-label">Currency</label>
            <select id="prop-currency" wire:model="currencyCode"
                    class="lp-input" data-testid="property-currency">
                @foreach ($currenciesByRegion as $region => $currencies)
                    <optgroup label="{{ $region }}">
                        @foreach ($currencies as $c)
                            <option value="{{ $c->value }}">
                                {{ $c->symbol() }} {{ $c->value }} · {{ $c->label() }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <p class="lp-form-help mt-2">
                Every amount on this branch (invoices, receipts, POS, dashboard)
                renders in this currency. Historical amounts stay stored as
                whole units; changing the currency changes the symbol, not the
                value. No exchange conversion between branches.
            </p>
            @error('currencyCode') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Inventory model, picks whether a central store fulfils
             every department or each department owns its own stock. --}}
        <fieldset class="lp-fieldset" data-testid="stock-model-picker">
            <legend>Inventory model</legend>
            <div class="grid gap-2 mt-2">
                @foreach ($stockModels as $m)
                    <label class="lp-radio-row">
                        <input type="radio"
                               wire:model.live="stockModel"
                               value="{{ $m->value }}"
                               data-testid="stock-model-{{ $m->value }}">
                        <span>
                            <strong>{{ $m->label() }}</strong>
                            <span style="color: var(--color-ink-mute);">- {{ $m->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="lp-form-help mt-2">
                Per-department mode hides the central Store from the sidebar and
                each department receives + counts its own inventory instead.
            </p>
            @error('stockModel') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Rich-text description. Contenteditable + tiny toolbar, no
             npm dependency. Output is HTML with a small allow-list
             (b/i/u/a/p/br/ul/ol/li); everything else gets stripped by
             HtmlSanitizer on save. wire:model is bound to a hidden
             textarea because Livewire's model binding doesn't hook
             contenteditable directly. --}}
        {{-- The editor lives outside Livewire's DOM management: after
             save Livewire morphs the component and would blank the
             contenteditable back to its Blade default. wire:ignore.self
             on the editor node itself preserves the innerHTML across
             re-renders while keeping the toolbar and helper text
             morphable. --}}
        <div x-data="propertyDescriptionEditor()"
             x-init="$nextTick(() => init($refs.editor, $refs.textarea))"
             data-testid="property-description-editor">
            <label for="prop-description" class="lp-form-label">Description</label>
            <div class="lp-rte-toolbar"
                 style="display: flex; gap: 4px; flex-wrap: wrap;
                        border: 1px solid var(--color-line);
                        border-bottom: 0;
                        border-radius: 8px 8px 0 0;
                        padding: 6px;
                        background: var(--color-card-deep);"
                 role="toolbar" aria-label="Formatting">
                <button type="button"
                        x-on:click="exec('bold')"
                        aria-label="Bold" title="Bold"
                        data-testid="rte-bold"
                        class="lp-btn lp-btn--compact"
                        style="font-weight: 700; padding: 4px 10px;">B</button>
                <button type="button"
                        x-on:click="exec('italic')"
                        aria-label="Italic" title="Italic"
                        data-testid="rte-italic"
                        class="lp-btn lp-btn--compact"
                        style="font-style: italic; padding: 4px 10px;">I</button>
                <button type="button"
                        x-on:click="exec('underline')"
                        aria-label="Underline" title="Underline"
                        data-testid="rte-underline"
                        class="lp-btn lp-btn--compact"
                        style="text-decoration: underline; padding: 4px 10px;">U</button>
                <span aria-hidden="true"
                      style="width: 1px; background: var(--color-line); margin: 0 4px;"></span>
                <button type="button"
                        x-on:click="exec('insertUnorderedList')"
                        aria-label="Bullet list" title="Bullet list"
                        data-testid="rte-ul"
                        class="lp-btn lp-btn--compact"
                        style="padding: 4px 10px;">&bull; List</button>
                <button type="button"
                        x-on:click="exec('insertOrderedList')"
                        aria-label="Numbered list" title="Numbered list"
                        data-testid="rte-ol"
                        class="lp-btn lp-btn--compact"
                        style="padding: 4px 10px;">1. List</button>
                <span aria-hidden="true"
                      style="width: 1px; background: var(--color-line); margin: 0 4px;"></span>
                <button type="button"
                        x-on:click="linkPrompt()"
                        aria-label="Link" title="Insert link"
                        data-testid="rte-link"
                        class="lp-btn lp-btn--compact"
                        style="padding: 4px 10px;">Link</button>
                <button type="button"
                        x-on:click="exec('removeFormat')"
                        aria-label="Clear formatting" title="Clear formatting"
                        data-testid="rte-clear"
                        class="lp-btn lp-btn--compact"
                        style="padding: 4px 10px; margin-left: auto;">Clear</button>
            </div>
            {{-- wire:ignore.self stops Livewire from morphing this
                 element's innerHTML on re-render. The initial value
                 is server-seeded from $description below (sanitised
                 HTML), so refresh + first render land the right
                 markup; after that Alpine owns the DOM. --}}
            <div x-ref="editor"
                 wire:ignore.self
                 contenteditable="true"
                 role="textbox"
                 aria-multiline="true"
                 aria-labelledby="prop-description-label"
                 data-testid="property-description"
                 x-on:input="sync"
                 x-on:blur="sync"
                 x-on:paste="onPaste($event)"
                 class="lp-input"
                 style="min-height: 140px;
                        border-radius: 0 0 8px 8px;
                        border-top: 0;
                        line-height: 1.55;
                        padding: 10px 12px;">{!! $description !!}</div>
            <textarea x-ref="textarea"
                      id="prop-description"
                      wire:model="description"
                      style="position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0;"
                      tabindex="-1" aria-hidden="true"></textarea>
            <p class="lp-form-help mt-2">
                Formatting: bold, italic, underline, lists, links. Everything else
                gets stripped when you save.
            </p>
            @error('description') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        @once
            <script>
                // Lightweight WYSIWYG for the property description.
                // execCommand is deprecated but still ships in every
                // evergreen browser and is 200 lines short of any
                // library alternative. Server-side allow-list
                // sanitisation happens in HtmlSanitizer on save, so the
                // output HTML is safe regardless of what the operator
                // types or pastes here.
                // Facilities chip editor — Livewire owns the array,
                // Alpine mirrors it for optimistic click feedback and
                // syncs on every change via $wire.set. `defer` so we
                // don't force a server round-trip per toggle.
                window.propertyAmenitiesEditor = function (initial) {
                    return {
                        items: Array.isArray(initial) ? initial.slice() : [],
                        draft: '',
                        isActive(preset) {
                            const p = preset.toLowerCase();
                            return this.items.some((x) => x.toLowerCase() === p);
                        },
                        toggle(preset) {
                            const i = this.items.findIndex(
                                (x) => x.toLowerCase() === preset.toLowerCase(),
                            );
                            if (i >= 0) {
                                this.items.splice(i, 1);
                            } else if (this.items.length < 60) {
                                this.items.push(preset);
                            }
                            this.sync();
                        },
                        addCustom() {
                            const clean = (this.draft || '').trim();
                            if (!clean || this.items.length >= 60) return;
                            if (this.isActive(clean)) {
                                this.draft = '';
                                return;
                            }
                            this.items.push(clean.slice(0, 100));
                            this.draft = '';
                            this.sync();
                        },
                        remove(idx) {
                            this.items.splice(idx, 1);
                            this.sync();
                        },
                        sync() {
                            // defer=true → sync locally on the wire
                            // component without triggering an immediate
                            // server render; the next Save picks it up.
                            this.$wire.set('amenities', this.items.slice(), false);
                        },
                    };
                };

                window.propertyDescriptionEditor = function () {
                    return {
                        editor: null,
                        textarea: null,
                        // Alpine convention: an `init` method on the
                        // x-data object is auto-called at setup with no
                        // args. Our real setup needs refs and runs via
                        // x-init in $nextTick, so no-op the arg-less
                        // auto call to avoid a TypeError.
                        init(editor = null, textarea = null) {
                            if (editor == null || textarea == null) return;
                            this.editor = editor;
                            this.textarea = textarea;
                            // Editor content was seeded by Blade via
                            // {!! $description !!}. Push it up to the
                            // hidden textarea so Livewire sees it on
                            // first mount without needing a keystroke.
                            this.sync();
                        },
                        exec(cmd, value = null) {
                            this.editor?.focus();
                            document.execCommand(cmd, false, value);
                            this.sync();
                        },
                        linkPrompt() {
                            const url = window.prompt(
                                'Link URL (https://…, mailto:, tel:)',
                                'https://',
                            );
                            if (!url) return;
                            this.exec('createLink', url);
                        },
                        onPaste(event) {
                            // Force plain-text paste. HtmlSanitizer would
                            // strip most inline-style HTML on save
                            // anyway, but pre-stripping keeps the visible
                            // editor state honest: what you see is what
                            // will get saved.
                            event.preventDefault();
                            const text = (event.clipboardData || window.clipboardData)?.getData('text/plain') ?? '';
                            document.execCommand('insertText', false, text);
                            this.sync();
                        },
                        sync() {
                            if (!this.editor || !this.textarea) return;
                            const html = this.editor.innerHTML.trim();
                            // "Empty" contenteditables leave <br> behind — normalise
                            // to an empty string so validators see no content.
                            this.textarea.value = html === '<br>' ? '' : html;
                            this.textarea.dispatchEvent(new Event('input', { bubbles: true }));
                        },
                    };
                };
            </script>
        @endonce

        {{-- Facilities picker. Curated presets grouped by category so
             common amenities are a one-tap add; anything not on the
             list is free-text via the "Add custom" input. Alpine-driven
             chip toggles + entangled with the Livewire property so
             saves round-trip once, not per-click. --}}
        @php
            $facilityGroups = [
                'Essentials' => [
                    'Free Wi-Fi', '24/7 backup power', 'Air conditioning',
                    'Hot water (24/7)', 'Secure parking', 'CCTV',
                ],
                'Comfort' => [
                    'Daily housekeeping', 'Smart TV', 'Netflix', 'In-room safe',
                    'Work desk', 'Iron & board', 'Wardrobe',
                ],
                'Kitchen' => [
                    'Kitchenette', 'Full kitchen', 'Refrigerator',
                    'Microwave', 'Toaster', 'Coffee machine',
                ],
                'Bath' => [
                    'Toiletries', 'Hair dryer', 'Bathtub', 'Rain shower',
                ],
                'Food & drink' => [
                    'Breakfast available', 'Restaurant on-site', 'Bar on-site',
                    'Room service', 'Complimentary water',
                ],
                'Wellness & leisure' => [
                    'Gym', 'Swimming pool', 'Spa', 'Sauna',
                    'Massage on request', 'Rooftop lounge',
                ],
                'Family & accessibility' => [
                    'Family-friendly', 'Crib on request', 'Wheelchair accessible',
                    'Pet friendly',
                ],
                'Business & transport' => [
                    'Business centre', 'Meeting room', 'Airport pickup',
                    'Car hire desk', 'Laundry service',
                ],
            ];
        @endphp
        <div x-data="propertyAmenitiesEditor(@js($amenities))"
             data-testid="property-amenities-editor">
            <label class="lp-form-label">Facilities</label>
            <p class="lp-form-help mb-2">
                Tap to add common amenities; add anything else as custom text.
                Up to 60 items. Ordering matches how they appear on your stays page.
            </p>

            {{-- Selected chips + drag-to-reorder-optional; simple order
                 buttons are enough for MVP. --}}
            <div class="flex flex-wrap gap-2"
                 style="min-height: 40px; padding: 8px; border: 1px dashed var(--color-line); border-radius: 8px; background: var(--color-card-deep);"
                 data-testid="property-amenities-selected">
                <template x-for="(item, idx) in items" :key="item">
                    <span class="lp-mono text-[11.5px]"
                          style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: var(--color-ink); color: var(--color-card);">
                        <span x-text="item"></span>
                        <button type="button"
                                x-on:click="remove(idx)"
                                aria-label="Remove"
                                style="background: none; border: 0; color: inherit; cursor: pointer; opacity: 0.7; padding: 0; font-size: 14px; line-height: 1;">
                            ×
                        </button>
                    </span>
                </template>
                <span x-show="items.length === 0"
                      class="text-[12px]" style="color: var(--color-ink-mute);">
                    No facilities yet, tap a chip below to start.
                </span>
            </div>

            <div class="mt-3" style="display: grid; gap: 12px;">
                @foreach ($facilityGroups as $groupName => $groupItems)
                    <div>
                        <div class="lp-mono text-[10.5px] mb-1"
                             style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                            {{ $groupName }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($groupItems as $preset)
                                <button type="button"
                                        x-on:click="toggle(@js($preset))"
                                        :class="isActive(@js($preset)) ? 'is-active' : ''"
                                        class="lp-btn lp-btn--compact"
                                        style="font-size: 11.5px; padding: 4px 10px;"
                                        data-testid="property-amenity-preset-{{ \Illuminate\Support\Str::slug($preset) }}">
                                    <span x-show="isActive(@js($preset))" aria-hidden="true">✓ </span>{{ $preset }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 flex items-center gap-2">
                <input type="text" x-model="draft"
                       x-on:keydown.enter.prevent="addCustom()"
                       placeholder="Add a custom facility (press Enter)…"
                       maxlength="100"
                       class="lp-input"
                       style="flex: 1;"
                       data-testid="property-amenity-custom-input">
                <button type="button"
                        x-on:click="addCustom()"
                        class="lp-btn"
                        data-testid="property-amenity-custom-add">
                    Add
                </button>
            </div>

            <p class="text-[11px] mt-2" style="color: var(--color-ink-mute);"
               x-show="items.length >= 60" x-cloak>
                60-item cap reached. Remove one to add another.
            </p>

            @error('amenities') <p class="lp-form-error">{{ $message }}</p> @enderror
            @error('amenities.*') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="prop-video-url" class="lp-form-label">Video tour</label>
            <input id="prop-video-url" type="url" wire:model.live.debounce.500ms="videoUrl" maxlength="400"
                   data-testid="property-video-url" class="lp-input"
                   placeholder="YouTube, Vimeo, or a direct .mp4 URL">
            <p class="lp-form-help mt-2">
                Paste a YouTube or Vimeo link, or a direct video file URL (.mp4, .webm, .mov). Guests see a play button on your hero photo, click it to watch the tour without leaving the page.
            </p>
            @error('videoUrl') <p class="lp-form-error">{{ $message }}</p> @enderror

            @php $videoPreview = $this->videoPreview; @endphp
            @if ($videoPreview)
                <div class="mt-3" data-testid="property-video-preview">
                    <span class="lp-mono" style="display: block; font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase; margin-bottom: 6px;">
                        Preview
                        <span style="letter-spacing: 0; text-transform: none; color: var(--color-ink-soft); margin-left: 6px;">
                            (detected: {{ $videoPreview['kind'] }})
                        </span>
                    </span>
                    <div style="position: relative; padding-top: 56.25%; background: #000; border-radius: 10px; overflow: hidden; max-width: 480px; border: 1px solid var(--color-line);">
                        @if ($videoPreview['kind'] === 'file')
                            <video src="{{ $videoPreview['src'] }}"
                                   controls preload="metadata"
                                   style="position: absolute; inset: 0; width: 100%; height: 100%;"></video>
                        @else
                            <iframe src="{{ $videoPreview['embed_url'] }}"
                                    title="Video tour preview"
                                    allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                    style="position: absolute; inset: 0; width: 100%; height: 100%; border: 0;"></iframe>
                        @endif
                    </div>
                </div>
            @endif
        </div>
        </div> {{-- /section-basics --}}
            </div> {{-- /group-body General --}}
        </section>

        <section id="group-location" class="lp-group">
            <h2 class="lp-group-title">Location</h2>
            <div class="lp-group-body">

        <fieldset id="section-address" class="lp-fieldset lp-section">
            <legend>Address</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div class="sm:col-span-2">
                    <label for="prop-address" class="lp-form-label">Street</label>
                    <input id="prop-address" type="text" wire:model="address" required maxlength="240"
                           data-testid="property-address" class="lp-input">
                    @error('address') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="prop-city" class="lp-form-label">City</label>
                    <input id="prop-city" type="text" wire:model="city" maxlength="120"
                           data-testid="property-city" class="lp-input">
                </div>
                <div>
                    <label for="prop-state" class="lp-form-label">State</label>
                    <input id="prop-state" type="text" wire:model="state" maxlength="120"
                           data-testid="property-state" class="lp-input">
                </div>
            </div>

            <div class="mt-5 pt-4"
                 style="border-top: 1px dashed var(--color-line);"
                 x-data="propertyCoordCapture($wire)">
                <div>
                    <strong class="text-[13.5px]" style="color: var(--color-ink); font-weight: 600;">
                        Coordinates
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </strong>
                    <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.55;">
                        Powers the "Where we are" map on the guest portal. Tap
                        <strong>Use my current location</strong> if you're standing at the property, or
                        paste from Google Maps (right-click the pin → click the coordinates to copy).
                    </p>
                </div>

                <div class="mt-3">
                    <button type="button" @click="useCurrent()"
                            :disabled="busy"
                            class="lp-btn lp-btn--ghost lp-btn--sm"
                            data-testid="property-use-current-location"
                            style="display: inline-flex; align-items: center; gap: 8px;">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"
                             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true">
                            <circle cx="10" cy="10" r="2.5"/>
                            <circle cx="10" cy="10" r="7"/>
                            <path d="M10 1v2M10 17v2M1 10h2M17 10h2"/>
                        </svg>
                        <span x-show="!busy">Use my current location</span>
                        <span x-show="busy">Locating…</span>
                    </button>
                    {{-- Inline status: 'ok:', 'error:', 'wait:' prefixes
                         decide colour - keeps the alpine state simple
                         while still being readable. --}}
                    <template x-if="status">
                        <p class="text-[12px] mt-2"
                           :style="status.startsWith('error:') ? 'color: #B85C42;'
                                : status.startsWith('ok:')    ? 'color: var(--color-accent-deep);'
                                                              : 'color: var(--color-ink-mute);'"
                           x-text="status.replace(/^(ok|error|wait):/, '')"
                           data-testid="property-location-status"></p>
                    </template>
                </div>

                <div class="grid gap-5 sm:grid-cols-2 mt-4">
                    <div>
                        <label for="prop-lat" class="lp-form-label">Latitude</label>
                        <input id="prop-lat" type="text" inputmode="decimal"
                               wire:model="latitude"
                               placeholder="e.g. 6.5244"
                               data-testid="property-latitude"
                               class="lp-input lp-mono">
                        @error('latitude') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="prop-lng" class="lp-form-label">Longitude</label>
                        <input id="prop-lng" type="text" inputmode="decimal"
                               wire:model="longitude"
                               placeholder="e.g. 3.3792"
                               data-testid="property-longitude"
                               class="lp-input lp-mono">
                        @error('longitude') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Geo-fence gate for the guest self-service portal.
                     Below-threshold guests get their sensitive actions
                     (extend / order / reserve / checkout / requests)
                     queued for front-desk approval instead of executing
                     directly, so an out-of-property phone can't be used
                     to run up charges on a booking. Off (0m fence) keeps
                     the pre-gate behaviour. --}}
                <div class="mt-5 pt-4"
                     style="border-top: 1px dashed var(--color-line);"
                     x-data="{
                         open: {{ $geoFenceRadiusMeters > 0 || $guestCoordMinScore > 0 ? 'true' : 'false' }},
                         preset: @entangle('geoFenceRadiusMeters').live,
                         pick(radius, score) {
                             this.preset = radius;
                             @this.set('geoFenceRadiusMeters', radius);
                             @this.set('guestCoordMinScore', score);
                         }
                     }">
                    <strong class="text-[13.5px]" style="color: var(--color-ink); font-weight: 600;">
                        Guest-portal geo-fence
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </strong>
                    <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.55;">
                        When a guest opens their in-room portal we capture their device coordinates and
                        score them 0-100 against your pin. Below the threshold, sensitive actions
                        (extend, order, reserve, checkout, requests) are held for front-desk approval
                        instead of running automatically.
                    </p>

                    <div class="mt-3 grid gap-2 sm:grid-cols-4" role="radiogroup"
                         aria-label="Fence preset">
                        <button type="button" role="radio"
                                @click="pick(0, 0)"
                                :aria-checked="{{ 0 }} === Number($wire.geoFenceRadiusMeters) ? 'true' : 'false'"
                                :class="Number($wire.geoFenceRadiusMeters) === 0 ? 'lp-chip lp-chip--on' : 'lp-chip'"
                                data-testid="fence-preset-off">
                            <span class="block text-[12.5px]" style="font-weight: 600;">Off</span>
                            <span class="block text-[11.5px]" style="color: var(--color-ink-mute);">
                                No fence - all actions run directly
                            </span>
                        </button>
                        <button type="button" role="radio"
                                @click="pick(50, 70)"
                                :aria-checked="{{ 50 }} === Number($wire.geoFenceRadiusMeters) ? 'true' : 'false'"
                                :class="Number($wire.geoFenceRadiusMeters) === 50 ? 'lp-chip lp-chip--on' : 'lp-chip'"
                                data-testid="fence-preset-tight">
                            <span class="block text-[12.5px]" style="font-weight: 600;">Tight · 50 m</span>
                            <span class="block text-[11.5px]" style="color: var(--color-ink-mute);">
                                Guest must be on-site (min 70)
                            </span>
                        </button>
                        <button type="button" role="radio"
                                @click="pick(200, 60)"
                                :aria-checked="{{ 200 }} === Number($wire.geoFenceRadiusMeters) ? 'true' : 'false'"
                                :class="Number($wire.geoFenceRadiusMeters) === 200 ? 'lp-chip lp-chip--on' : 'lp-chip'"
                                data-testid="fence-preset-standard">
                            <span class="block text-[12.5px]" style="font-weight: 600;">Standard · 200 m</span>
                            <span class="block text-[11.5px]" style="color: var(--color-ink-mute);">
                                Within the compound (min 60)
                            </span>
                        </button>
                        <button type="button" role="radio"
                                @click="pick(1000, 40)"
                                :aria-checked="{{ 1000 }} === Number($wire.geoFenceRadiusMeters) ? 'true' : 'false'"
                                :class="Number($wire.geoFenceRadiusMeters) === 1000 ? 'lp-chip lp-chip--on' : 'lp-chip'"
                                data-testid="fence-preset-loose">
                            <span class="block text-[12.5px]" style="font-weight: 600;">Loose · 1 km</span>
                            <span class="block text-[11.5px]" style="color: var(--color-ink-mute);">
                                Nearby is fine (min 40)
                            </span>
                        </button>
                    </div>

                    <button type="button" class="mt-3 text-[12.5px]"
                            @click="open = !open"
                            style="color: var(--color-accent-deep); font-weight: 600; background: none; border: 0; padding: 0; cursor: pointer;"
                            data-testid="fence-advanced-toggle">
                        <span x-show="!open">Advanced - set exact metres / threshold ↓</span>
                        <span x-show="open">Hide advanced ↑</span>
                    </button>

                    <div x-show="open" x-cloak class="grid gap-5 sm:grid-cols-2 mt-3">
                        <div>
                            <label for="prop-fence" class="lp-form-label">Fence radius (metres)</label>
                            <input id="prop-fence" type="number" min="0" max="100000" step="10"
                                   wire:model="geoFenceRadiusMeters"
                                   data-testid="property-fence-radius"
                                   class="lp-input lp-mono">
                            <p class="lp-form-help">0 disables the gate. Typical: 50 m tight, 200 m standard, 1000 m loose.</p>
                            @error('geoFenceRadiusMeters') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="prop-min-score" class="lp-form-label">Minimum trust score (0-100)</label>
                            <input id="prop-min-score" type="number" min="0" max="100" step="5"
                                   wire:model="guestCoordMinScore"
                                   data-testid="property-min-score"
                                   class="lp-input lp-mono">
                            <p class="lp-form-help">100 = must be exactly at the pin. 0 = every action goes to the approval queue.</p>
                            @error('guestCoordMinScore') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
        </fieldset>

        <fieldset id="section-nearby" class="lp-fieldset lp-section" data-testid="nearby-fieldset">
            <legend>Places nearby</legend>
            <p class="lp-form-help" style="margin-top: 4px;">
                Shown on the public landing instead of the map. Add up to ~10 useful destinations
                (airport, market, hospital, attraction), each with a distance and how to get there.
            </p>

            <div class="grid gap-2 mt-3" data-testid="nearby-list">
                @foreach ($nearbyPlaces as $i => $place)
                    <div class="grid gap-2 sm:grid-cols-[1.4fr_0.7fr_0.7fr_auto] items-end"
                         data-testid="nearby-row-{{ $i }}">
                        <div>
                            <label class="lp-form-label" for="np-label-{{ $i }}">Place</label>
                            <input id="np-label-{{ $i }}" type="text"
                                   wire:model.live="nearbyPlaces.{{ $i }}.label"
                                   class="lp-input" maxlength="80"
                                   placeholder="e.g. Nnamdi Azikiwe Airport"
                                   data-testid="np-label-{{ $i }}">
                        </div>
                        <div>
                            <label class="lp-form-label" for="np-dist-{{ $i }}">Distance / time</label>
                            <input id="np-dist-{{ $i }}" type="text"
                                   wire:model.live="nearbyPlaces.{{ $i }}.distance"
                                   class="lp-input" maxlength="40"
                                   placeholder="e.g. 25 min"
                                   data-testid="np-distance-{{ $i }}">
                        </div>
                        <div>
                            <label class="lp-form-label" for="np-mode-{{ $i }}">Mode</label>
                            <select id="np-mode-{{ $i }}"
                                    wire:model.live="nearbyPlaces.{{ $i }}.mode"
                                    class="lp-input"
                                    data-testid="np-mode-{{ $i }}">
                                <option value="walk">Walk</option>
                                <option value="drive">Drive</option>
                                <option value="transit">Transit</option>
                            </select>
                        </div>
                        <div>
                            <button type="button" wire:click="removeNearbyPlace({{ $i }})"
                                    data-testid="np-remove-{{ $i }}"
                                    style="background: none; border: 0; cursor: pointer;
                                           color: var(--color-danger, #b00); font-size: 12px; padding: 8px 0;">
                                Remove
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="addNearbyPlace"
                    data-testid="np-add"
                    style="margin-top: 12px; background: rgba(138, 174, 58, 0.06);
                           border: 1px dashed rgba(138, 174, 58, 0.35);
                           border-radius: 10px; cursor: pointer; padding: 12px 16px;
                           color: var(--color-accent-deep); font-size: 13px; font-weight: 500;
                           width: 100%; transition: background 140ms, border-color 140ms;"
                    onmouseover="this.style.background='rgba(138,174,58,0.10)';this.style.borderColor='var(--color-accent-deep)';"
                    onmouseout="this.style.background='rgba(138,174,58,0.06)';this.style.borderColor='rgba(138,174,58,0.35)';">
                + Add a place
            </button>
        </fieldset>

            </div> {{-- /group-body Location --}}
        </section>

        <section id="group-policies" class="lp-group">
            <h2 class="lp-group-title">Policies</h2>
            <div class="lp-group-body">

        <fieldset id="section-checkout" class="lp-fieldset lp-section" data-testid="checkout-policy-fieldset">
            <legend>Check-in &amp; check-out</legend>
            <p class="lp-form-help" style="margin-top: 4px;">
                The check-in and check-out windows below are the times shown to guests
                on your public landing. The late-checkout fee below is applied automatically
                at check-out when a guest is still on-site past the end of your
                check-out window.
            </p>

            <div class="grid gap-5 sm:grid-cols-2 mt-3">
                <div>
                    <span class="lp-form-label">Check-in window</span>
                    <div class="grid grid-cols-2 gap-2 mt-1">
                        <input type="time" wire:model="houseRules.check_in_from"
                               data-testid="rules-checkin-from" class="lp-input lp-mono">
                        <input type="time" wire:model="houseRules.check_in_to"
                               data-testid="rules-checkin-to" class="lp-input lp-mono">
                    </div>
                    @error('houseRules.check_in_from') <p class="lp-form-error">{{ $message }}</p> @enderror
                    @error('houseRules.check_in_to') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="lp-form-label">Check-out window</span>
                    <div class="grid grid-cols-2 gap-2 mt-1">
                        <input type="time" wire:model="houseRules.check_out_from"
                               data-testid="rules-checkout-from" class="lp-input lp-mono">
                        <input type="time" wire:model="houseRules.check_out_to"
                               data-testid="rules-checkout-to" class="lp-input lp-mono">
                    </div>
                    @error('houseRules.check_out_from') <p class="lp-form-error">{{ $message }}</p> @enderror
                    @error('houseRules.check_out_to') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Late-checkout billing sub-block. Kept in the same
                 fieldset as the times because the "when" and the "what
                 it costs" are decided together. --}}
            <div class="mt-5 pt-4"
                 style="border-top: 1px dashed var(--color-line);">
                <label class="flex items-start gap-3 text-[13.5px]"
                       style="color: var(--color-ink);">
                    <input type="checkbox" wire:model.live="checkoutPolicy.late_checkout_enabled"
                           data-testid="late-checkout-enabled"
                           style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                    <span>
                        <strong style="font-weight: 600;">Charge a late check-out fee</strong>
                        <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.55;">
                            When on, a guest who checks out same-day past the end of your check-out window
                            (plus the grace period below) gets a "Late check-out" line added to their folio,
                            billed per started hour. If they stay long enough to trip the full-day threshold,
                            the room's base rate is charged instead.
                        </p>
                    </span>
                </label>

                <div class="grid gap-5 sm:grid-cols-3 mt-4">
                    <div>
                        <label for="lc-grace" class="lp-form-label">Grace (minutes)</label>
                        <input id="lc-grace" type="number" min="0" max="720" step="5"
                               wire:model="checkoutPolicy.late_checkout_grace_minutes"
                               data-testid="late-checkout-grace"
                               class="lp-input lp-mono"
                               @disabled(! $checkoutPolicy['late_checkout_enabled'])>
                        <p class="lp-form-help mt-1">Wall-clock buffer past your check-out time before the meter starts.</p>
                        @error('checkoutPolicy.late_checkout_grace_minutes') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="lc-hourly" class="lp-form-label">Hourly fee (₦)</label>
                        <input id="lc-hourly" type="number" min="0" max="10000000" step="500"
                               wire:model="checkoutPolicy.late_checkout_hourly_fee"
                               data-testid="late-checkout-hourly-fee"
                               class="lp-input lp-mono"
                               @disabled(! $checkoutPolicy['late_checkout_enabled'])>
                        <p class="lp-form-help mt-1">Charged per started hour. e.g. ₦5,000/hr.</p>
                        @error('checkoutPolicy.late_checkout_hourly_fee') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="lc-full-day" class="lp-form-label">Full-day after (hours)</label>
                        <input id="lc-full-day" type="number" min="0" max="24" step="1"
                               wire:model="checkoutPolicy.late_checkout_full_day_after_hours"
                               data-testid="late-checkout-full-day-after"
                               class="lp-input lp-mono"
                               @disabled(! $checkoutPolicy['late_checkout_enabled'])>
                        <p class="lp-form-help mt-1">Past this many hours late, charge the room's full base rate instead. 0 = never - always bill hourly.</p>
                        @error('checkoutPolicy.late_checkout_full_day_after_hours') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Early check-out billing sub-block - how the property
                 handles unused paid nights when a guest leaves before
                 their booked checkout. --}}
            <div class="mt-5 pt-4"
                 style="border-top: 1px dashed var(--color-line);">
                <div>
                    <strong class="text-[13.5px]" style="color: var(--color-ink); font-weight: 600;">
                        Early check-out (unused paid nights)
                    </strong>
                    <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.55;">
                        When a guest leaves before their booked checkout with paid nights left over,
                        this is what happens to the money. "Ask each time" pauses at checkout so the
                        front desk decides per guest; anything else auto-applies silently.
                    </p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2 mt-4">
                    <div>
                        <label for="ec-mode" class="lp-form-label">Default action</label>
                        <select id="ec-mode"
                                wire:model="checkoutPolicy.early_checkout_default_mode"
                                data-testid="early-checkout-default-mode"
                                class="lp-input">
                            <option value="ask">Ask front desk each time</option>
                            <option value="keep">Keep as revenue (non-refundable)</option>
                            <option value="wallet">Credit guest wallet</option>
                            <option value="cash">Refund cash at the desk</option>
                        </select>
                        <p class="lp-form-help mt-1">Applied automatically when the guest checks out early with unused paid nights.</p>
                        @error('checkoutPolicy.early_checkout_default_mode') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ec-percent" class="lp-form-label">Refund percent</label>
                        <div style="position: relative;">
                            <input id="ec-percent" type="number" min="0" max="100" step="5"
                                   wire:model="checkoutPolicy.early_checkout_refund_percent"
                                   data-testid="early-checkout-refund-percent"
                                   class="lp-input lp-mono"
                                   style="padding-right: 26px;">
                            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
                                         font-size: 12px; color: var(--color-ink-mute);">%</span>
                        </div>
                        <p class="lp-form-help mt-1">
                            Fraction of unused-night value to refund. 100 = full refund,
                            50 = half (cancellation-fee style), 0 = never refund.
                        </p>
                        @error('checkoutPolicy.early_checkout_refund_percent') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset id="section-house-rules" class="lp-fieldset lp-section" data-testid="house-rules-fieldset">
            <legend>House rules</legend>
            <p class="lp-form-help" style="margin-top: 4px;">
                Free-text policies shown on your public landing and inside every category detail page,
                so guests know what to expect before they book.
            </p>

            <div class="grid gap-5 sm:grid-cols-2 mt-3">
                <div class="sm:col-span-2">
                    <label for="rules-cancellation" class="lp-form-label">Cancellation policy</label>
                    <textarea id="rules-cancellation" wire:model="houseRules.cancellation_policy"
                              rows="2" maxlength="1000"
                              placeholder="e.g. Free cancellation up to 24 hours before check-in."
                              data-testid="rules-cancellation" class="lp-input"></textarea>
                </div>
                <div>
                    <label for="rules-early" class="lp-form-label">Early check-out</label>
                    <textarea id="rules-early" wire:model="houseRules.early_checkout"
                              rows="2" maxlength="1000"
                              placeholder="e.g. Allowed any time before noon, no fee."
                              data-testid="rules-early-checkout" class="lp-input"></textarea>
                </div>
                <div>
                    <label for="rules-children" class="lp-form-label">Children policy</label>
                    <textarea id="rules-children" wire:model="houseRules.children_policy"
                              rows="2" maxlength="1000"
                              placeholder="e.g. Children of all ages welcome. Extra mattress on request."
                              data-testid="rules-children" class="lp-input"></textarea>
                </div>
                <div>
                    <label for="rules-pets" class="lp-form-label">Pets</label>
                    <textarea id="rules-pets" wire:model="houseRules.pets_policy"
                              rows="2" maxlength="1000"
                              placeholder="e.g. No pets allowed."
                              data-testid="rules-pets" class="lp-input"></textarea>
                </div>
                <div>
                    <label for="rules-smoking" class="lp-form-label">Smoking</label>
                    <textarea id="rules-smoking" wire:model="houseRules.smoking_policy"
                              rows="2" maxlength="1000"
                              placeholder="e.g. Non-smoking property; designated outdoor areas only."
                              data-testid="rules-smoking" class="lp-input"></textarea>
                </div>
                <div>
                    <label for="rules-quiet" class="lp-form-label">Quiet hours</label>
                    <input id="rules-quiet" type="text" wire:model="houseRules.quiet_hours"
                           maxlength="80"
                           placeholder="e.g. 10:00 PM – 7:00 AM"
                           data-testid="rules-quiet" class="lp-input">
                </div>
            </div>
        </fieldset>

            </div> {{-- /group-body Policies --}}
        </section>

        <section id="group-business" class="lp-group">
            <h2 class="lp-group-title">Business</h2>
            <div class="lp-group-body">

        <fieldset id="section-corporates" class="lp-fieldset lp-section">
            <legend>Corporate partnerships</legend>
            <div class="mt-2">
                <label class="flex items-start gap-3 text-[13.5px]" style="color: var(--color-ink);">
                    <input type="checkbox" wire:model.live="acceptsCorporates"
                           data-testid="accepts-corporates"
                           style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                    <span>
                        <strong style="font-weight: 600;">Accept corporate bookings</strong>
                        <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.55;">
                            When on, staff of your corporate clients can book this building
                            using their corporate wallet at their negotiated rates. You'll see those
                            bookings flagged "Corporate · {company}" on the reservations list.
                            Toggle off if you don't want to participate.
                        </p>
                    </span>
                </label>
            </div>
        </fieldset>

        <fieldset id="section-contact" class="lp-fieldset lp-section">
            <legend>Guest contact</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label for="prop-contact-email" class="lp-form-label">Contact email</label>
                    <input id="prop-contact-email" type="email" wire:model="contactEmail" maxlength="180"
                           data-testid="property-contact-email" class="lp-input">
                    @error('contactEmail') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="prop-contact-phone" class="lp-form-label">Contact phone</label>
                    <input id="prop-contact-phone" type="tel" wire:model="contactPhone" maxlength="32"
                           data-testid="property-contact-phone" class="lp-input">
                    @error('contactPhone') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="lp-form-help mt-2">
                Shown on the public landing page and in confirmation emails.
            </p>
        </fieldset>

        <fieldset id="section-payouts" class="lp-fieldset lp-section" data-testid="payout-fieldset">
            <legend>Payouts, bank account</legend>
            <p class="lp-form-help" style="margin-top: 4px;">
                Guest bookings are collected through {{ config('brand.name') }}'s payment partner, Paystack, and
                settle <strong style="color: var(--color-ink);">directly to your bank account</strong> -
                {{ config('brand.name') }} never holds your money. We deduct a
                {{ number_format((float) config('services.paystack.platform_percent_charge', 2.0), 1) }}%
                platform fee per transaction; you receive the rest.
            </p>

            @if ($hasSubaccount)
                <div class="mt-3 lp-pill-soft" data-testid="payout-current"
                     style="display: inline-flex; flex-direction: column; gap: 4px; padding: 12px 14px;
                            background: var(--color-card-deep); border-radius: 10px;">
                    <span class="lp-mono text-[11px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                        Payouts going to
                    </span>
                    <span style="color: var(--color-ink); font-weight: 600;">
                        {{ $currentBankName ?: 'Verified bank' }} · <span class="lp-mono">{{ $currentAccountMask }}</span>
                    </span>
                    @if ($currentAccountName !== '')
                        <span style="color: var(--color-ink-soft); font-size: 13px;">
                            Account holder: {{ $currentAccountName }}
                        </span>
                    @endif
                </div>
            @endif

            @if ($banksLoadFailed)
                <p class="lp-form-error mt-3" data-testid="banks-load-failed">
                    Couldn't reach Paystack to load the bank list. Refresh the page to retry,
                    or contact support if this persists.
                </p>
            @endif

            <div class="grid gap-5 sm:grid-cols-[1.4fr_1fr_auto] items-end mt-4">
                <div>
                    <label for="payout-bank" class="lp-form-label">
                        Bank {{ $hasSubaccount ? '(change)' : '' }}
                    </label>
                    <select id="payout-bank" wire:model="payoutBankCode"
                            data-testid="payout-bank-select" class="lp-input"
                            @if ($banksLoadFailed) disabled @endif>
                        <option value="">Select your bank…</option>
                        @foreach ($banks as $bank)
                            <option value="{{ $bank['code'] }}">{{ $bank['name'] }}</option>
                        @endforeach
                    </select>
                    @error('payoutBankCode') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="payout-account" class="lp-form-label">Account number (10 digits)</label>
                    <input id="payout-account" type="text" inputmode="numeric" maxlength="10"
                           wire:model="payoutAccountNumber"
                           placeholder="{{ $hasSubaccount ? '••••••••••' : '0123456789' }}"
                           data-testid="payout-account-input"
                           class="lp-input lp-mono">
                    @error('payoutAccountNumber') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <button type="button" wire:click="savePayoutAccount"
                            data-testid="payout-save"
                            class="lp-pill"
                            @if ($banksLoadFailed) disabled @endif>
                        <span wire:loading.remove wire:target="savePayoutAccount">
                            {{ $hasSubaccount ? 'Update payouts' : 'Verify & save' }}
                        </span>
                        <span wire:loading wire:target="savePayoutAccount">Verifying…</span>
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </div>

            @if ($payoutError)
                <p class="lp-form-error mt-3" data-testid="payout-error">{{ $payoutError }}</p>
            @endif

            <p class="lp-form-help mt-3" style="font-size: 12px;">
                We verify the account holder name with your bank before saving, make sure
                the account number matches the property's business or owner name.
            </p>
        </fieldset>

            </div> {{-- /group-body Business --}}
        </section>

        <div class="lp-form-actions-sticky flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
            <button type="submit" data-testid="property-save" class="lp-pill">
                <span wire:loading.remove wire:target="save">Save settings</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>

        </div> {{-- /lp-settings-form-body --}}

        {{-- Right sidebar: sticky section list. Hidden below md via
             the .lp-settings-nav media query, so mobile users just see
             the accordions stacked. Anchor clicks open the parent
             group + scroll the target into view. --}}
        <aside class="lp-settings-nav" aria-label="Property settings sections"
               data-testid="property-settings-nav">
            @foreach ($groups as $g)
                <div class="lp-settings-nav-group">
                    <p class="lp-settings-nav-title">{{ $g['label'] }}</p>
                    @foreach ($g['sections'] as $s)
                        <a href="#{{ $s['id'] }}"
                           class="lp-settings-nav-link"
                           data-jump="{{ $s['id'] }}"
                           data-group="{{ $g['id'] }}">
                            {{ $s['label'] }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </aside>
    </form>

    <script>
        (function () {
            const nav = document.querySelector('[data-testid="property-settings-nav"]');
            if (! nav) return;
            nav.addEventListener('click', function (e) {
                const link = e.target.closest('a[data-jump]');
                if (! link) return;
                const target = document.getElementById(link.dataset.jump);
                if (! target) return;
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                history.replaceState(null, '', '#' + link.dataset.jump);
            });
        })();
    </script>
</div>
