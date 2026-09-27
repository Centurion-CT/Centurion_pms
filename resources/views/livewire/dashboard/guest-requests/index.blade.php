<div class="lp-dash">
    {{-- ── Hero: title + status filter tab-strip ────────────
         Editorial header with the primary filter (Open / Resolved
         / All) baked into the right side so operators see BOTH
         "where they are" + "what's showing" at load. Kind chips
         sit below as their own scrollable row. --}}
    <section class="lp-res-idx-hero" data-testid="gr-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Guest requests</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    In-room portal requests
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    Anything guests raise from the room QR portal (complaints, taxi bookings, room-transfer asks, orders) lands here. Resolve when it's done.
                </p>
            </div>

            <div class="lp-gr-hero-side">
                <div class="lp-tabstrip" data-testid="gr-status-filter">
                    <button type="button" wire:click="$set('statusFilter', 'open')"
                            class="lp-tabstrip-btn @if ($statusFilter === 'open') is-active @endif">Open</button>
                    <button type="button" wire:click="$set('statusFilter', 'resolved')"
                            class="lp-tabstrip-btn @if ($statusFilter === 'resolved') is-active @endif">Resolved</button>
                    <button type="button" wire:click="$set('statusFilter', '')"
                            class="lp-tabstrip-btn @if ($statusFilter === '') is-active @endif">All</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Kind chip row ───────────────────────────────────── --}}
    <div class="lp-add-charge-chips" data-testid="gr-kind-filter" role="radiogroup" aria-label="Filter by kind">
        <button type="button" wire:click="$set('kindFilter', '')"
                class="lp-add-charge-chip @if ($kindFilter === '') is-active @endif">
            <span>Any kind</span>
        </button>
        @foreach ($kinds as $k)
            <button type="button" wire:click="$set('kindFilter', '{{ $k->value }}')"
                    class="lp-add-charge-chip @if ($kindFilter === $k->value) is-active @endif">
                <span>{{ $k->label() }}</span>
            </button>
        @endforeach
    </div>

    @if (session('status'))
        <div class="lp-flash lp-flash--ok">{{ session('status') }}</div>
    @endif

    {{-- ── Card-stack list ─────────────────────────────────── --}}
    @php
        $pageIds = collect($requests->items())->pluck('id')->all();
        $pageState = $this->selectionStateFor($pageIds);
    @endphp
    @if ($requests->isEmpty())
        <section class="lp-dash-card" data-testid="guest-requests-empty">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'clipboard',
                'title' => 'Nothing here yet.',
                'sub'   => 'Guest requests from the in-room QR portal (complaints, taxi, transfer, orders) land here as they come in.',
            ])
        </section>
    @else
        <section class="lp-dash-card" data-testid="guest-requests-panel" style="padding: 14px 16px;">
            {{-- Compact page-toolbar with a "select all" checkbox. --}}
            <div class="lp-gr-toolbar">
                <label class="lp-gr-selectall">
                    <input type="checkbox"
                           class="lp-bulk-checkbox"
                           data-testid="gr-bulk-toggle-all"
                           aria-label="{{ $pageState['all'] ? 'Deselect all on this page' : 'Select all on this page' }}"
                           @checked($pageState['all'])
                           x-init="$el.indeterminate = @js($pageState['some'])"
                           wire:click.stop="{{ $pageState['all']
                                ? 'deselectPage('.json_encode($pageIds).')'
                                : 'selectAll('.json_encode($pageIds).')' }}">
                    <span>{{ $requests->total() }} request{{ $requests->total() === 1 ? '' : 's' }}</span>
                </label>
            </div>

            {{-- Dense table replaces the ledger card-stack — a busy
                 front-desk queue of in-room portal requests scans much
                 faster in a grid than in vertical cards. The row-wide
                 wire:click still opens the detail slide-out, and every
                 test ID (gr-row-{id}, gr-bulk-toggle-*, gr-resolve,
                 gr-reopen, gr-change-room) is preserved. --}}
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="guest-requests-table">
                    <thead>
                        <tr>
                            <th style="width: 32px;"></th>
                            <th>Kind</th>
                            <th>Guest</th>
                            <th>Details</th>
                            <th>Received</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($requests as $req)
                        @php
                            $isOpen = $req->status !== 'resolved';
                            $isUrgent = $req->kind->isUrgent();
                            $kindClass = $isUrgent ? 'lp-tag--cancelled' : 'lp-tag--pending';

                            // Human-friendly body renderer: resolve
                            // target_category_id → category name, skip empty
                            // values, drop raw key names so front desk sees
                            // "Move to Deluxe" not "target_category_id: 1".
                            $bodyLines = [];
                            foreach (($req->body ?? []) as $key => $val) {
                                if ($val === null || $val === '') continue;
                                if ($key === 'target_category_id' && is_numeric($val)) {
                                    $name = $categoryNames[(int) $val] ?? ('Category #'.$val);
                                    $bodyLines[] = 'Wants to move to: '.$name;
                                } elseif ($key === 'when') {
                                    $bodyLines[] = 'When: '.$val;
                                } elseif ($key === 'destination') {
                                    $bodyLines[] = 'Destination: '.$val;
                                } elseif ($key === 'freeform') {
                                    $bodyLines[] = $val;
                                } elseif (is_scalar($val)) {
                                    $bodyLines[] = ucfirst(str_replace('_', ' ', $key)).': '.$val;
                                }
                            }
                        @endphp
                        <tr class="lp-gr-row @if ($isUrgent && $isOpen) lp-gr-row--urgent @endif"
                            data-testid="gr-row-{{ $req->id }}"
                            role="button" tabindex="0"
                            wire:click="openRequest({{ $req->id }})"
                            x-on:keydown.enter.prevent="$wire.openRequest({{ $req->id }})">
                            <td wire:click.stop style="cursor: default;">
                                <label class="lp-gr-item-check" style="margin: 0;">
                                    <input type="checkbox"
                                           class="lp-bulk-checkbox"
                                           data-testid="gr-bulk-toggle-{{ $req->id }}"
                                           aria-label="Select request {{ $req->id }}"
                                           @checked(in_array((string) $req->id, $selectedIds, true))
                                           wire:click="toggleSelected({{ $req->id }})">
                                </label>
                            </td>
                            <td>
                                <span class="lp-tag {{ $kindClass }}" style="font-size: 10px;">
                                    {{ $req->kind->label() }}
                                </span>
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="margin: 0; font-weight: 500; color: var(--color-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        {{ $req->booking?->guest_name ?? '(no guest)' }}
                                    </p>
                                    <p class="lp-mono" style="margin: 2px 0 0; font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.02em;">
                                        @if ($req->booking?->booking_ref){{ $req->booking->booking_ref }}@endif
                                        @if ($req->room?->room_number)
                                            @if ($req->booking?->booking_ref) · @endif
                                            Rm {{ $req->room->room_number }}
                                        @endif
                                    </p>
                                </div>
                            </td>
                            <td style="max-width: 340px;">
                                @if ($req->notes || $bodyLines !== [])
                                    <div style="min-width: 0; font-size: 12.5px; color: var(--color-ink-soft); line-height: 1.4;">
                                        @if ($req->notes)
                                            <p style="margin: 0; color: var(--color-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                {{ $req->notes }}
                                            </p>
                                        @endif
                                        @foreach ($bodyLines as $line)
                                            <p style="margin: 2px 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                {{ $line }}
                                            </p>
                                        @endforeach
                                    </div>
                                @else
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="margin: 0; font-size: 12.5px; color: var(--color-ink);">
                                        {{ $req->created_at?->diffForHumans() }}
                                    </p>
                                    @if ($req->booking?->guest_phone)
                                        <p class="lp-mono" style="margin: 2px 0 0; font-size: 10.5px; color: var(--color-ink-soft);">
                                            {{ $req->booking->guest_phone }}
                                        </p>
                                    @endif
                                    @if (! $isOpen && $req->resolvedBy)
                                        <p style="margin: 2px 0 0; font-size: 10.5px; color: var(--color-ink-mute);">
                                            by {{ $req->resolvedBy->name }}
                                        </p>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if ($isOpen)
                                    <span class="lp-tag lp-tag--on-hold">Open</span>
                                @else
                                    <span class="lp-tag lp-tag--confirmed">Resolved</span>
                                @endif
                            </td>
                            <td style="text-align: right;" wire:click.stop>
                                <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: wrap;">
                                    @if ($isOpen)
                                        @if ($req->kind === \App\Enums\GuestRequestKind::Transfer && $req->booking)
                                            <a href="{{ route('tenant.reservations.show', $req->booking->booking_ref).'?reassign=1' }}"
                                               wire:navigate
                                               data-testid="gr-change-room-{{ $req->id }}"
                                               class="lp-ledger-action">
                                                Change room →
                                            </a>
                                        @endif
                                        <button type="button" wire:click="resolve({{ $req->id }})"
                                                data-testid="gr-resolve-{{ $req->id }}"
                                                class="lp-ledger-action">
                                            Mark resolved
                                        </button>
                                    @else
                                        <button type="button" wire:click="reopen({{ $req->id }})"
                                                data-testid="gr-reopen-{{ $req->id }}"
                                                class="lp-ledger-action">
                                            Reopen
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="mt-3">{{ $requests->links() }}</div>

    @if ($this->hasSelection())
        <x-dash-bulk-bar :count="count($selectedIds)" noun="request" testid="gr-bulk-bar">
            <x-slot:actions>
                <button type="button"
                        wire:click="resolveSelected"
                        wire:loading.attr="disabled"
                        class="lp-dash-bulk-action lp-dash-bulk-action--primary"
                        data-testid="gr-bulk-resolve">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2.5 6.5l2.5 2.5L10 3.5"/>
                    </svg>
                    Mark resolved
                </button>
            </x-slot:actions>
        </x-dash-bulk-bar>
    @endif

    {{-- Centre loader shown ONLY while openRequest is fetching the
         request + messages. Same visual + timing as the layout-
         wide nav spinner (partials/_nav-spinner) so the initial
         state is `display: none` - Livewire then flips it to
         `flex` when the openRequest round-trip fires. Prior version
         started `display: flex`, which made it visible on every
         page load before Livewire could take control of the
         style; the loader would then never dismiss because the
         inline `flex` beat Livewire's hide-by-`display: none`. --}}
    <div wire:loading.delay.flex wire:target="openRequest"
         data-testid="gr-open-loader"
         style="position: fixed; inset: 0; z-index: 95;
                display: none; align-items: center; justify-content: center;
                background: rgba(20, 17, 12, 0.28);
                backdrop-filter: blur(1.5px);
                pointer-events: none;">
        <div style="width: 52px; height: 52px; border-radius: 50%;
                    background: #fff; box-shadow: 0 12px 40px rgba(20, 17, 12, 0.24);
                    display: flex; align-items: center; justify-content: center;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
                 style="animation: gr-open-spin 0.9s linear infinite;">
                <circle cx="12" cy="12" r="9" stroke="rgba(20,17,12,0.12)" stroke-width="2.5"/>
                <path d="M21 12a9 9 0 0 0-9-9" stroke="var(--color-ink, #14171A)"
                      stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
    </div>
    <style>
        @keyframes gr-open-spin { to { transform: rotate(360deg); } }
        /* Urgent + still-open requests get a soft amber wash so a
           busy queue lets the eye jump to them. Matches the in-house
           overdue tint (lp-ih-row--overdue) for consistency. */
        .lp-gr-row--urgent > td { background: rgba(168, 101, 10, 0.06); }
        .lp-gr-row--urgent:hover > td { background: rgba(168, 101, 10, 0.11); }
    </style>

    {{-- Detail panel — opens when a row is clicked. Shows the full
         request, guest contact, timestamps, and a message thread
         that the guest sees on their portal's My-requests tile. --}}
    @if ($selected)
        @php
            $bodyKV = [];
            foreach (($selected->body ?? []) as $key => $val) {
                if ($val === null || $val === '') continue;
                if ($key === 'target_category_id' && is_numeric($val)) {
                    $bodyKV['Wants to move to'] = $categoryNames[(int) $val] ?? ('Category #'.$val);
                } elseif ($key === 'when') {
                    $bodyKV['When'] = (string) $val;
                } elseif ($key === 'destination') {
                    $bodyKV['Destination'] = (string) $val;
                } elseif ($key === 'freeform') {
                    $bodyKV['Details'] = (string) $val;
                } elseif (is_scalar($val)) {
                    $bodyKV[ucfirst(str_replace('_', ' ', $key))] = (string) $val;
                }
            }
        @endphp
        <template x-teleport="body">
            <div x-data="{ open: true }"
                 x-show="open"
                 x-cloak
                 x-init="$nextTick(() => document.body.style.overflow = 'hidden')"
                 x-on:keydown.escape.window="$wire.closeRequest()"
                 data-testid="gr-panel-backdrop"
                 style="position: fixed; inset: 0; z-index: 90;
                        background: rgba(20, 17, 12, 0.42);
                        backdrop-filter: blur(2px);
                        animation: gr-fade 160ms ease;"
                 @click.self="$wire.closeRequest()">
                <aside data-testid="gr-panel"
                       class="lp-page"
                       style="position: absolute; top: 0; right: 0; bottom: 0;
                              width: min(560px, 100vw);
                              background: var(--color-paper); overflow-y: auto;
                              border-left: 1px solid var(--color-line);
                              box-shadow: -22px 0 60px -30px rgba(20,17,12,0.35);
                              animation: gr-slide 220ms cubic-bezier(0.16, 1, 0.3, 1);">
                    <div style="padding: 22px 24px; border-bottom: 1px solid var(--color-line-soft);
                                display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;">
                        <div>
                            <p class="lp-mono text-[10.5px]"
                               style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                                {{ $selected->kind->label() }}
                            </p>
                            <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin-top: 4px; line-height: 1.2;">
                                {{ $selected->booking?->guest_name ?? 'Guest' }}
                            </h2>
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                Room {{ $selected->room?->room_number ?? '?' }}
                                @if ($selected->booking?->booking_ref)
                                    · {{ $selected->booking->booking_ref }}
                                @endif
                                · {{ $selected->created_at?->diffForHumans() }}
                            </p>
                        </div>
                        <button type="button" wire:click="closeRequest"
                                data-testid="gr-panel-close"
                                aria-label="Close"
                                style="width: 32px; height: 32px; border-radius: 999px;
                                       background: var(--color-card); border: 1px solid var(--color-line);
                                       color: var(--color-ink-soft); cursor: pointer;
                                       display: inline-flex; align-items: center; justify-content: center;">
                            ×
                        </button>
                    </div>

                    <div style="padding: 20px 24px; display: grid; gap: 18px;">
                        {{-- Status --}}
                        <div class="flex items-center gap-2">
                            @if ($selected->status === 'resolved')
                                <span class="lp-tag lp-tag--confirmed">Resolved</span>
                                @if ($selected->resolvedBy)
                                    <span class="text-[12px]" style="color: var(--color-ink-mute);">by {{ $selected->resolvedBy->name }}</span>
                                @endif
                            @elseif ($selected->status === 'cancelled')
                                <span class="lp-tag lp-tag--cancelled">Cancelled by guest</span>
                            @else
                                <span class="lp-tag lp-tag--on-hold">Open</span>
                            @endif
                        </div>

                        {{-- Body details --}}
                        @if ($selected->notes || $bodyKV !== [])
                            <div style="background: var(--color-card-deep); border-radius: 10px; padding: 12px 14px;
                                        display: grid; gap: 8px;">
                                <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0;">
                                    Request
                                </p>
                                @if ($selected->notes)
                                    <p style="font-size: 13px; color: var(--color-ink); margin: 0; white-space: pre-line;">
                                        {{ $selected->notes }}
                                    </p>
                                @endif
                                @foreach ($bodyKV as $label => $value)
                                    <div class="flex justify-between gap-3" style="font-size: 12.5px;">
                                        <span style="color: var(--color-ink-soft);">{{ $label }}</span>
                                        <span style="color: var(--color-ink); font-weight: 500;">{{ $value }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Guest contact --}}
                        <div>
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                                Contact
                            </p>
                            <div style="display: grid; gap: 4px; font-size: 13px;">
                                @if ($selected->booking?->guest_phone)
                                    <a href="tel:{{ $selected->booking->guest_phone }}"
                                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                        {{ $selected->booking->guest_phone }}
                                    </a>
                                @endif
                                @if ($selected->booking?->guest_email)
                                    <a href="mailto:{{ $selected->booking->guest_email }}"
                                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                        {{ $selected->booking->guest_email }}
                                    </a>
                                @endif
                                @if ($selected->booking?->checkin_date && $selected->booking?->checkout_date)
                                    <span style="color: var(--color-ink-soft); font-size: 12px;">
                                        {{ $selected->booking->checkin_date->format('D, j M') }}
                                        → {{ $selected->booking->checkout_date->format('D, j M') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Message thread --}}
                        <div>
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                                Messages
                            </p>
                            @if ($messages->isEmpty())
                                <p class="text-[12px]" style="color: var(--color-ink-mute); margin: 0;">
                                    Nothing yet. Send a message below. The guest will see it on their portal.
                                </p>
                            @else
                                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px;">
                                    @foreach ($messages as $m)
                                        <li data-testid="gr-message-{{ $m->id }}"
                                            style="{{ $m->isFromOperator() ? 'align-self: flex-end; max-width: 85%;' : 'align-self: flex-start; max-width: 85%;' }}">
                                            <div style="{{ $m->isFromOperator()
                                                        ? 'background: var(--color-ink); color: var(--color-card);'
                                                        : 'background: var(--color-card-deep); color: var(--color-ink);' }}
                                                        padding: 8px 12px; border-radius: 12px;
                                                        font-size: 13px; line-height: 1.5; white-space: pre-line;">
                                                {{ $m->message }}
                                            </div>
                                            <div style="font-size: 10.5px; color: var(--color-ink-mute); margin-top: 2px;
                                                        {{ $m->isFromOperator() ? 'text-align: right;' : '' }}">
                                                {{ $m->isFromOperator() ? ($m->operator?->name ?? 'Operator') : 'Guest' }}
                                                · {{ $m->created_at?->diffForHumans() }}
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        {{-- Compose --}}
                        <form wire:submit="sendMessage" style="display: grid; gap: 8px;">
                            <label class="lp-form-label" for="gr-message-input">Reply to guest</label>
                            <textarea id="gr-message-input" wire:model="newMessage"
                                      rows="3" maxlength="1000"
                                      placeholder="e.g. Your taxi is downstairs. Please head to the lobby."
                                      data-testid="gr-message-input"
                                      class="lp-input" style="resize: vertical;"></textarea>
                            <div class="flex justify-end">
                                <button type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="sendMessage"
                                        data-testid="gr-message-send"
                                        class="lp-btn lp-btn--primary">
                                    <span wire:loading.remove wire:target="sendMessage">Send</span>
                                    <span wire:loading wire:target="sendMessage">Sending…</span>
                                </button>
                            </div>
                        </form>

                        {{-- Actions --}}
                        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 14px;
                                    display: flex; flex-wrap: wrap; gap: 8px;">
                            @if ($selected->status === 'open'
                                && $selected->kind === \App\Enums\GuestRequestKind::Transfer
                                && $selected->booking)
                                <a href="{{ route('tenant.reservations.show', $selected->booking->booking_ref).'?reassign=1' }}"
                                   wire:navigate
                                   data-testid="gr-panel-change-room"
                                   class="lp-btn">
                                    Change room →
                                </a>
                            @endif
                            @if ($selected->status === 'open')
                                <button type="button" wire:click="resolve({{ $selected->id }})"
                                        class="lp-btn lp-btn--primary" data-testid="gr-panel-resolve">
                                    Mark resolved
                                </button>
                            @else
                                <button type="button" wire:click="reopen({{ $selected->id }})"
                                        class="lp-btn" data-testid="gr-panel-reopen">
                                    Reopen
                                </button>
                            @endif
                            @if ($selected->booking)
                                <a href="{{ route('tenant.reservations.show', $selected->booking->booking_ref) }}"
                                   wire:navigate
                                   style="color: var(--color-ink-soft); text-decoration: underline;
                                          text-underline-offset: 3px; font-size: 12.5px; margin-left: auto;
                                          align-self: center;">
                                    Open reservation
                                </a>
                            @endif
                        </div>
                    </div>
                </aside>
            </div>
        </template>
        <style>
            @keyframes gr-fade { from { opacity: 0; } to { opacity: 1; } }
            @keyframes gr-slide { from { transform: translateX(28px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        </style>
    @endif
</div>
