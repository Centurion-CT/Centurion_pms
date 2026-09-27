<div data-testid="notifications-bell" class="relative">
    <button type="button" wire:click="toggle"
            aria-haspopup="true" aria-expanded="{{ $open ? 'true' : 'false' }}"
            data-testid="bell-toggle" class="lp-bell-btn">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 2v1.2M3.5 7.5a4.5 4.5 0 1 1 9 0v2.4l1 1.6h-11l1-1.6V7.5z"/>
            <path d="M6.5 13a1.5 1.5 0 0 0 3 0"/>
        </svg>
        @if ($unreadCount > 0)
            <span data-testid="bell-unread-count"
                  aria-label="{{ $unreadCount }} unread"
                  class="lp-bell-badge">{{ $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div role="menu" data-testid="bell-dropdown" class="lp-bell-pop">
            @if ($items->isEmpty())
                <p data-testid="bell-empty"
                   class="text-center text-[13px]"
                   style="color: var(--color-ink-mute); padding: 20px 8px;">
                    No notifications yet.
                </p>
            @else
                <ul class="list-none p-0 m-0">
                    @foreach ($items as $item)
                        @php $data = $item->data ?? []; @endphp
                        <li data-testid="bell-item"
                            data-read="{{ $item->read_at !== null ? 'true' : 'false' }}"
                            class="lp-bell-item">
                            <span class="title">{{ $data['title'] ?? 'Notification' }}</span>
                            <p class="msg">{{ $data['message'] ?? '' }}</p>
                            <span class="ts">{{ $item->created_at?->diffForHumans() }}</span>

                            <div class="flex items-center gap-4 text-[12px] pt-1">
                                @if ($item->read_at === null)
                                    <button type="button"
                                            wire:click="markRead('{{ $item->id }}')"
                                            data-testid="mark-read"
                                            style="color: var(--color-ink-soft); background: transparent; border: 0; cursor: pointer; font-family: inherit; text-decoration: underline; text-underline-offset: 3px;">
                                        Mark as read
                                    </button>
                                @endif

                                @isset($data['booking_ref'])
                                    <a href="{{ route('tenant.bookings.show', $data['booking_ref']) }}"
                                       wire:navigate
                                       style="color: var(--color-ink); font-weight: 500; text-decoration: underline; text-underline-offset: 3px;">
                                        Open booking →
                                    </a>
                                @endisset
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($unreadCount > 0)
                    <button type="button" wire:click="markAllRead"
                            data-testid="mark-all-read"
                            class="lp-btn lp-btn--block"
                            style="margin-top: 8px;">
                        Mark all as read
                    </button>
                @endif

                <a href="{{ route('tenant.notifications.index') }}" wire:navigate
                   data-testid="bell-see-all"
                   style="display: block; text-align: center; padding: 10px 8px 6px; font-size: 12px; color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 3px;">
                    See all notifications →
                </a>
            @endif
        </div>
    @endif
</div>
