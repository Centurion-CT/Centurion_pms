@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Access</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Platform admins
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $admins->total() }} admin{{ $admins->total() === 1 ? '' : 's' }} on the platform.
                Super admins can invite, deactivate or remove others.
            </p>
        </div>

        @if ($isSuper)
            <a href="{{ route('admin.admins.create') }}" wire:navigate
               class="lp-pill" data-testid="create-admin">
                Invite admin <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </a>
        @endif
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="a-q">Search</label>
                <input id="a-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Name or email" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="a-r">Role</label>
                <select id="a-r" wire:model.live="roleFilter" class="lp-input" data-testid="role-filter">
                    <option value="">Any role</option>
                    <option value="super">Super</option>
                    <option value="support">Support</option>
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="a-s">Status</label>
                <select id="a-s" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>
    </section>

    @if ($admins->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="empty" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                No admins match the filter.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="admins-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Last login</th>
                            <th>Status</th>
                            @if ($isSuper)
                                <th style="text-align: right;">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @php $currentId = auth('admin')->user()?->getKey(); @endphp
                        @foreach ($admins as $a)
                            <tr data-testid="admin-row-{{ $a->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $a->name }}
                                    @if ($a->id === $currentId)
                                        <span class="lp-mono"
                                              style="color: var(--color-ink-mute); font-size: 10.5px; margin-left: 6px;">
                                            (you)
                                        </span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft);">{{ $a->email }}</td>
                                <td>
                                    <span class="lp-tag {{ $a->role === 'super' ? 'lp-tag--confirmed' : 'lp-tag--checked-in' }}">
                                        {{ $a->role }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $a->last_login_at?->diffForHumans() ?? '-' }}
                                </td>
                                <td>
                                    <span class="lp-tag {{ $a->is_active ? 'lp-tag--confirmed' : 'lp-tag--checked-out' }}">
                                        {{ $a->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                @if ($isSuper)
                                    <td style="text-align: right;">
                                        <div class="inline-flex items-center gap-3 text-[12.5px]"
                                             style="color: var(--color-ink-soft);">
                                            <a href="{{ route('admin.admins.edit', $a) }}" wire:navigate
                                               data-testid="edit-{{ $a->id }}"
                                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                                                Edit
                                            </a>
                                            <button type="button"
                                                    wire:click="toggleActive({{ $a->id }})"
                                                    data-testid="toggle-{{ $a->id }}"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                                {{ $a->is_active ? 'Deactivate' : 'Reactivate' }}
                                            </button>
                                            <button type="button"
                                                    wire:click="delete({{ $a->id }})"
                                                    wire:confirm="Remove this admin? This can't be undone."
                                                    data-testid="delete-{{ $a->id }}"
                                                    style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">
                                                Remove
                                            </button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $admins->links() }}</div>
        </section>
    @endif
</div>
