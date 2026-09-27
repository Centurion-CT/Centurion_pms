<div class="lp-dash"
     x-data="notifInbox()"
     x-init="init()"
     x-on:keydown.window="onKey($event)">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Notifications</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Activity inbox
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Every booking confirmation, cancellation, and system event addressed to you.
            </p>
        </div>

        @if ($counts['unread'] > 0)
            <button type="button" wire:click="markAllRead"
                    wire:confirm="Mark all unread as read?"
                    data-testid="notif-mark-all-read" class="lp-btn">
                Mark all as read ({{ $counts['unread'] }})
            </button>
        @endif
    </div>

    <div class="lp-chips" data-testid="notif-tabs">
        <button type="button" wire:click="$set('filter', 'all')"
                class="lp-chip @if ($filter === 'all') is-active @endif"
                data-testid="notif-tab-all">
            All <span class="count">{{ $counts['all'] }}</span>
        </button>
        <button type="button" wire:click="$set('filter', 'unread')"
                class="lp-chip @if ($filter === 'unread') is-active @endif"
                data-testid="notif-tab-unread">
            Unread <span class="count">{{ $counts['unread'] }}</span>
        </button>
        <button type="button" wire:click="$set('filter', 'read')"
                class="lp-chip @if ($filter === 'read') is-active @endif"
                data-testid="notif-tab-read">
            Read
        </button>
    </div>

    {{-- Sender-type lane pills. Superhuman/Linear "channel" pattern —
         a notification is either about a booking, a payment, or the
         platform itself. Each pill filters the list without leaving
         the page + keeps a live count that never zeroes to hidden. --}}
    <div class="lp-chips" data-testid="notif-lanes" style="margin-top: 4px;">
        @foreach ([
            ['all',      'All lanes'],
            ['bookings', 'Bookings'],
            ['payments', 'Payments'],
            ['system',   'System'],
        ] as [$key, $label])
            <button type="button" wire:click="$set('laneFilter', '{{ $key }}')"
                    class="lp-chip @if ($laneFilter === $key) is-active @endif"
                    data-testid="notif-lane-{{ $key }}">
                {{ $label }}
                <span class="count">{{ $laneCounts[$key] ?? 0 }}</span>
            </button>
        @endforeach

        <span class="ml-auto" style="margin-left: auto; display: inline-flex; gap: 6px; align-items: center; color: var(--color-ink-mute); font-family: ui-monospace, SFMono-Regular, monospace; font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase;">
            <kbd style="padding: 1px 6px; border-radius: 4px; background: color-mix(in srgb, var(--color-ink) 6%, transparent);">J</kbd>
            <kbd style="padding: 1px 6px; border-radius: 4px; background: color-mix(in srgb, var(--color-ink) 6%, transparent);">K</kbd>
            <span>nav</span>
            <kbd style="padding: 1px 6px; border-radius: 4px; background: color-mix(in srgb, var(--color-ink) 6%, transparent);">E</kbd>
            <span>archive</span>
            <kbd style="padding: 1px 6px; border-radius: 4px; background: color-mix(in srgb, var(--color-ink) 6%, transparent);">↵</kbd>
            <span>open</span>
        </span>
    </div>

    @if ($notifications->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-notifications" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                @if ($filter === 'unread')
                    All caught up.
                @elseif ($filter === 'read')
                    No read notifications yet.
                @else
                    No notifications yet.
                @endif
            </p>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                Booking events, balance reminders and system alerts will land here.
            </p>
        </section>
    @else
        @php
            $pageIds = collect($notifications->items())->pluck('id')->all();
            $pageState = $this->selectionStateFor($pageIds);
        @endphp
        <section class="lp-dash-card" style="padding: 0;">
            {{-- Header strip with the page-scoped "select all" checkbox
                 so operators can bulk-manage the current page in one tap. --}}
            <div style="padding: 12px 20px; border-bottom: 1px solid var(--color-line-soft); display: flex; align-items: center; gap: 12px;">
                <input type="checkbox"
                       class="lp-bulk-checkbox"
                       data-testid="notif-bulk-toggle-all"
                       aria-label="{{ $pageState['all'] ? 'Deselect all notifications' : 'Select all notifications' }}"
                       @checked($pageState['all'])
                       x-init="$el.indeterminate = @js($pageState['some'])"
                       wire:click.stop="{{ $pageState['all']
                            ? 'deselectPage('.json_encode($pageIds).')'
                            : 'selectAll('.json_encode($pageIds).')' }}">
                <span class="lp-mono" style="font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Select page
                </span>
            </div>
            <ul class="divide-y list-none m-0 p-0" style="border-color: var(--color-line-soft);">
                @foreach ($notifications as $item)
                    @php
                        $data = $item->data ?? [];
                        $isUnread = $item->read_at === null;
                    @endphp
                    <li data-testid="notif-row" data-read="{{ $isUnread ? 'false' : 'true' }}"
                        data-notif-row
                        data-notif-id="{{ $item->id }}"
                        data-notif-booking-ref="{{ $data['booking_ref'] ?? '' }}"
                        :class="{ 'is-focused': focusedId === '{{ $item->id }}' }"
                        style="padding: 16px 20px; @if ($isUnread) background: var(--color-accent-soft); @endif">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 flex items-start gap-3">
                                <input type="checkbox"
                                       class="lp-bulk-checkbox"
                                       data-testid="notif-bulk-toggle-{{ $item->id }}"
                                       style="margin-top: 4px;"
                                       aria-label="Select notification"
                                       @checked(in_array((string) $item->id, $selectedIds, true))
                                       wire:click="toggleSelected('{{ $item->id }}')">
                                <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    @if ($isUnread)
                                        <span aria-hidden style="display: inline-block; width: 7px; height: 7px; background: var(--color-accent-deep); border-radius: 50%;"></span>
                                    @endif
                                    <span style="font-family: var(--font-display); font-weight: 600; font-size: 15px; color: var(--color-ink); letter-spacing: -0.01em;">
                                        {{ $data['title'] ?? 'Notification' }}
                                    </span>
                                </div>
                                <p class="text-[13.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.5;">
                                    {{ $data['message'] ?? '' }}
                                </p>
                                <div class="flex flex-wrap items-center gap-4 mt-2 text-[11.5px]"
                                     style="color: var(--color-ink-mute);">
                                    <span>{{ $item->created_at?->diffForHumans() }}</span>
                                    @isset($data['booking_ref'])
                                        <a href="{{ route('tenant.bookings.show', $data['booking_ref']) }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                            Open booking {{ $data['booking_ref'] }} →
                                        </a>
                                    @endisset
                                </div>
                                </div>{{-- /min-w-0 flex-1 (inner content) --}}
                            </div>{{-- /min-w-0 flex-1 (LHS row: checkbox + content) --}}
                            <div class="flex items-center gap-2 flex-shrink-0">
                                @if ($isUnread)
                                    <button type="button" wire:click="markRead('{{ $item->id }}')"
                                            data-testid="notif-mark-read-{{ $item->id }}"
                                            style="background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: 12px; color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 3px;">
                                        Mark read
                                    </button>
                                @endif
                                <button type="button" wire:click="delete('{{ $item->id }}')"
                                        wire:confirm="Delete this notification?"
                                        data-testid="notif-delete-{{ $item->id }}"
                                        style="background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: 12px; color: #B85C42;"
                                        aria-label="Delete">
                                    Delete
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="text-[13px]" style="color: var(--color-ink-soft);">
            {{ $notifications->links() }}
        </div>
    @endif

    @if ($this->hasSelection())
        <x-dash-bulk-bar :count="count($selectedIds)" noun="notification" testid="notif-bulk-bar">
            <x-slot:actions>
                <button type="button"
                        wire:click="markSelectedRead"
                        wire:loading.attr="disabled"
                        class="lp-dash-bulk-action"
                        data-testid="notif-bulk-mark-read">
                    Mark read
                </button>
                <button type="button"
                        wire:click="deleteSelected"
                        wire:confirm="Delete the selected notifications?"
                        wire:loading.attr="disabled"
                        class="lp-dash-bulk-action"
                        data-testid="notif-bulk-delete"
                        style="color: #FFB49E;">
                    Delete
                </button>
            </x-slot:actions>
        </x-dash-bulk-bar>
    @endif

    <style>
        .lp-page [data-notif-row].is-focused {
            box-shadow: inset 3px 0 0 0 var(--color-accent-deep, #C99441);
        }
    </style>

    <script>
        /* Notifications inbox keyboard nav. Superhuman/Linear pattern:
             j / ↓  next
             k / ↑  previous
             ↵      open (booking link when present, or nothing)
             e      archive (delete the currently focused row)
             r      mark focused as read
           Alpine data function scoped to this page so the shortcuts
           don't leak to /dashboard/* siblings. */
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('notifInbox', () => ({
                focusedId: null,
                init() {
                    // Focus the first visible row so j/k work immediately
                    // without the operator having to click into the list.
                    this.$nextTick(() => this.selectFirst());
                },
                rows() {
                    return Array.from(this.$root.querySelectorAll('[data-notif-row]'));
                },
                selectFirst() {
                    const first = this.rows()[0];
                    if (first) this.focusedId = first.dataset.notifId;
                },
                move(delta) {
                    const rows = this.rows();
                    if (rows.length === 0) return;
                    const idx = rows.findIndex(r => r.dataset.notifId === this.focusedId);
                    let next = (idx === -1 ? 0 : idx + delta);
                    if (next < 0) next = 0;
                    if (next >= rows.length) next = rows.length - 1;
                    this.focusedId = rows[next].dataset.notifId;
                    rows[next].scrollIntoView({ block: 'nearest' });
                },
                openFocused() {
                    const row = this.rows().find(r => r.dataset.notifId === this.focusedId);
                    if (!row) return;
                    const ref = row.dataset.notifBookingRef;
                    if (!ref) return; // Nothing to open (system notification).
                    const anchor = row.querySelector('a[href*="/dashboard/bookings/"]');
                    if (anchor) anchor.click();
                },
                archiveFocused() {
                    if (!this.focusedId) return;
                    const wireEl = this.$root.closest('[wire\\:id]');
                    if (!wireEl) return;
                    const comp = window.Livewire?.find(wireEl.getAttribute('wire:id'));
                    // move forward BEFORE the row disappears from the DOM
                    const nextIdx = this.rows().findIndex(r => r.dataset.notifId === this.focusedId) + 1;
                    const nextId = this.rows()[nextIdx]?.dataset.notifId ?? null;
                    comp?.$call('delete', this.focusedId);
                    this.focusedId = nextId;
                },
                markFocusedRead() {
                    if (!this.focusedId) return;
                    const wireEl = this.$root.closest('[wire\\:id]');
                    if (!wireEl) return;
                    const comp = window.Livewire?.find(wireEl.getAttribute('wire:id'));
                    comp?.$call('markRead', this.focusedId);
                },
                onKey(e) {
                    const tag = (e.target?.tagName || '').toUpperCase();
                    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
                    if (e.metaKey || e.ctrlKey || e.altKey) return;
                    switch (e.key) {
                        case 'j': case 'ArrowDown': e.preventDefault(); this.move(1); return;
                        case 'k': case 'ArrowUp':   e.preventDefault(); this.move(-1); return;
                        case 'Enter':               e.preventDefault(); this.openFocused(); return;
                        case 'e':                   e.preventDefault(); this.archiveFocused(); return;
                        case 'r':                   e.preventDefault(); this.markFocusedRead(); return;
                    }
                },
            }));
        });
    </script>
</div>
