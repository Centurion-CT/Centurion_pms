@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Settings</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Your profile
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Update your name, email and password.
        </p>
    </div>

    @include('livewire.dashboard.settings._tabs', ['active' => 'profile'])

    <form wire:submit="updateProfile" class="lp-dash-card" style="display: grid; gap: 18px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Personal details</span>
            @php $myRole = auth('web')->user()?->role ?? \App\Enums\OperatorRole::Manager; @endphp
            <span class="lp-tag {{ match($myRole->value) { 'manager' => 'lp-tag--checked-in', 'reservation_officer' => 'lp-tag--confirmed', 'frontdesk' => 'lp-tag--pending', default => 'lp-tag--on-hold' } }}"
                  data-testid="profile-role">{{ $myRole->label() }}</span>
        </div>

        <div>
            <label for="prof-name" class="lp-form-label">Full name</label>
            <input id="prof-name" type="text" wire:model="name" required maxlength="120"
                   data-testid="profile-name" class="lp-input">
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="prof-email" class="lp-form-label">Email</label>
            <input id="prof-email" type="email" wire:model="email" required maxlength="180"
                   data-testid="profile-email" class="lp-input">
            @error('email') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end">
            <button type="submit" data-testid="profile-save" class="lp-pill">
                <span wire:loading.remove wire:target="updateProfile">Save changes</span>
                <span wire:loading wire:target="updateProfile">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>

    {{-- Sidebar style — same preference the sidebar-foot toggle
         writes. Lives client-side (localStorage per browser) since it
         is a display choice, not a server-persisted account setting.
         Applying it here flips the current tab immediately AND
         persists so every subsequent load picks the same variant. --}}
    <section class="lp-dash-card"
             x-data="{
                 variant: document.documentElement.getAttribute('data-sidebar-variant') || 'tiles',
                 async pick(v) {
                     if (v === this.variant) return;
                     const prev = this.variant;
                     this.variant = v;
                     document.documentElement.setAttribute('data-sidebar-variant', v);
                     try {
                         // Go through Livewire's own channel — same
                         // auth session + CSRF wrap as every other
                         // wire:click, so no fetch-side surprises.
                         await $wire.saveSidebarVariant(v);
                         // Best-effort IndexedDB cache write once the
                         // operator-prefs.js bundle is on the page.
                         window.KevernaOperatorPrefs?.set('sidebar_variant', v);
                         this.$dispatch('notify', {
                             title: 'Sidebar updated',
                             message: v === 'list' ? 'Switched to the compact list view.' : 'Switched to the tile grid view.',
                             kind: 'ok',
                         });
                     } catch (e) {
                         this.variant = prev;
                         document.documentElement.setAttribute('data-sidebar-variant', prev);
                         this.$dispatch('notify', {
                             title: 'Could not save',
                             message: 'The sidebar preference did not persist. Try again.',
                             kind: 'err',
                         });
                     }
                 }
             }"
             data-testid="settings-sidebar-variant"
             style="display: grid; gap: 14px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Sidebar style</span>
            <span class="lp-dash-card-meta">
                <span x-show="variant === 'list'" x-cloak>Compact list · saved</span>
                <span x-show="variant === 'tiles'" x-cloak>Tile grid · saved</span>
            </span>
        </div>

        {{-- Two pickable radio cards. Preview panels share a fixed
             aspect ratio + row grid so both cards align regardless of
             which one is active, and the preview reads as a
             miniature of the real sidebar (dark surface, section
             eyebrow, then the module rows / tiles). --}}
        <div class="lp-sidebar-picker" role="radiogroup" aria-label="Sidebar style">

            {{-- Tile grid option --}}
            <button type="button" x-on:click.prevent="pick('tiles')"
                    data-testid="sidebar-variant-tiles"
                    role="radio"
                    :aria-checked="variant === 'tiles' ? 'true' : 'false'"
                    :class="variant === 'tiles' ? 'lp-sidebar-pick is-active' : 'lp-sidebar-pick'">
                <div class="lp-sidebar-pick-head">
                    <span class="lp-sidebar-pick-title">Tile grid</span>
                    <span x-show="variant === 'tiles'" x-cloak class="lp-sidebar-pick-badge">
                        <span aria-hidden></span>Active
                    </span>
                </div>
                {{-- Mini sidebar mock: brand + section eyebrow + 3×2
                     coloured tile grid. Same palette the real sidebar
                     uses so the preview is honest. --}}
                <div aria-hidden class="lp-sidebar-pick-canvas">
                    <div class="lp-sidebar-pick-brandbar">
                        <span class="lp-sidebar-pick-brand"></span>
                        <span class="lp-sidebar-pick-eyebrow"></span>
                    </div>
                    <div class="lp-sidebar-pick-tiles">
                        @foreach(['#4B7BB0', '#7A6EA7', '#B85C42', '#5D842C', '#a36318', '#4B7BB0'] as $c)
                            <div class="lp-sidebar-pick-tile" style="--tile: {{ $c }};"></div>
                        @endforeach
                    </div>
                </div>
                <p class="lp-sidebar-pick-desc">
                    Colour-coded 3-column grid. Fastest visual recognition.
                </p>
            </button>

            {{-- Compact list option --}}
            <button type="button" x-on:click.prevent="pick('list')"
                    data-testid="sidebar-variant-list"
                    role="radio"
                    :aria-checked="variant === 'list' ? 'true' : 'false'"
                    :class="variant === 'list' ? 'lp-sidebar-pick is-active' : 'lp-sidebar-pick'">
                <div class="lp-sidebar-pick-head">
                    <span class="lp-sidebar-pick-title">Compact list</span>
                    <span x-show="variant === 'list'" x-cloak class="lp-sidebar-pick-badge">
                        <span aria-hidden></span>Active
                    </span>
                </div>
                <div aria-hidden class="lp-sidebar-pick-canvas">
                    <div class="lp-sidebar-pick-brandbar">
                        <span class="lp-sidebar-pick-brand"></span>
                        <span class="lp-sidebar-pick-eyebrow"></span>
                    </div>
                    <ul class="lp-sidebar-pick-rows">
                        @foreach([false, true, false, false, false, false, false] as $isActive)
                            <li class="lp-sidebar-pick-row @if ($isActive) is-active @endif">
                                <span class="dot"></span>
                                <span class="bar"></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <p class="lp-sidebar-pick-desc">
                    Dense single-column list. Fits more, faster to scan.
                </p>
            </button>
        </div>
    </section>

    <form wire:submit="updatePassword" class="lp-dash-card" style="display: grid; gap: 18px;">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Change password</span></div>

        <div>
            <label for="pw-current" class="lp-form-label">Current password</label>
            <input id="pw-current" type="password" wire:model="current_password" autocomplete="current-password"
                   data-testid="profile-current-password" class="lp-input">
            @error('current_password') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="pw-new" class="lp-form-label">New password</label>
                <input id="pw-new" type="password" wire:model="password" autocomplete="new-password"
                       data-testid="profile-new-password" class="lp-input">
                @error('password') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="pw-confirm" class="lp-form-label">Confirm new password</label>
                <input id="pw-confirm" type="password" wire:model="password_confirmation" autocomplete="new-password"
                       data-testid="profile-confirm-password" class="lp-input">
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" data-testid="password-save" class="lp-pill">
                <span wire:loading.remove wire:target="updatePassword">Change password</span>
                <span wire:loading wire:target="updatePassword">Updating…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
