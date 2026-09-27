<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Accounts</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Tenants
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $tenants->total() ?? $tenants->count() }}
                {{ ($tenants->total() ?? $tenants->count()) === 1 ? 'tenant' : 'tenants' }} matching the current filter.
            </p>
        </div>
    </div>

    {{-- ── Filters ───────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>

        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="admin-tenants-search" class="lp-form-label">Search</label>
                <input id="admin-tenants-search" type="search"
                       wire:model.live.debounce.400ms="search"
                       placeholder="Name, slug or email"
                       data-testid="admin-tenants-search" class="lp-input">
            </div>

            <div>
                <label for="admin-tenants-status" class="lp-form-label">Status</label>
                <select id="admin-tenants-status" wire:model.live="statusFilter"
                        data-testid="admin-tenants-status" class="lp-input">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    {{-- ── Table ─────────────────────────────────────────────────── --}}
    @if ($tenants->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-tenants" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                No tenants match the filter.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            @php $isSuper = auth('admin')->user()?->role === 'super'; @endphp

            @if ($isSuper && count(array_filter($selected)) > 0)
                <div class="flex items-center justify-between gap-3"
                     style="padding: 12px 16px; background: rgba(184, 92, 66, 0.08); border-bottom: 1px solid rgba(184, 92, 66, 0.2);"
                     data-testid="bulk-actions-bar">
                    <span style="font-size: 13.5px; color: var(--color-ink); font-weight: 500;">
                        {{ count(array_filter($selected)) }} selected
                        <span style="color: var(--color-ink-mute); font-weight: 400;">
                            (max {{ $bulkMax }} per batch)
                        </span>
                    </span>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" wire:click="$set('selected', [])"
                                class="lp-btn lp-btn--sm lp-btn--ghost">
                            Clear
                        </button>
                        <button type="button" wire:click="openBulkConfirm"
                                class="lp-btn lp-btn--sm"
                                style="border-color: #B85C42; color: #B85C42;"
                                data-testid="bulk-delete-open">
                            Delete selected…
                        </button>
                    </div>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table data-testid="tenants-table" class="lp-table">
                    <thead>
                        <tr>
                            @if ($isSuper)
                                <th style="width: 32px;"><span class="sr-only">Select</span></th>
                            @endif
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tenants as $tenant)
                            @php
                                $statusClass = match ($tenant->status->value) {
                                    'active', 'trial' => 'lp-tag--confirmed',
                                    'grace'           => 'lp-tag--checked-in',
                                    'locked'          => 'lp-tag--checked-out',
                                    'archived'        => 'lp-tag--checked-out',
                                    default           => 'lp-tag--checked-in',
                                };
                            @endphp
                            <tr data-testid="tenant-row">
                                @if ($isSuper)
                                    <td style="width: 32px;">
                                        <input type="checkbox"
                                               wire:model.live="selected.{{ $tenant->id }}"
                                               aria-label="Select {{ $tenant->name }}"
                                               data-testid="bulk-select-{{ $tenant->id }}">
                                    </td>
                                @endif
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $tenant->name }}
                                </td>
                                <td>
                                    <code class="lp-mono"
                                          style="color: var(--color-ink-soft); font-size: 12px;">
                                        {{ $tenant->slug }}
                                    </code>
                                </td>
                                <td>
                                    <span class="lp-tag {{ $statusClass }}">
                                        {{ $tenant->status->value }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $tenant->created_at?->toFormattedDateString() }}
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('admin.tenants.show', $tenant) }}" wire:navigate
                                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px; font-weight: 500;">
                                        View →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $tenants->links() }}</div>
        </section>
    @endif

    {{-- ── Bulk-destroy confirmation panel ────────────────────────
         Slides in when the "Delete selected" bar is clicked. Same
         four guards as the single-tenant Destroy form: typed phrase
         (with the exact count), reason for audit, fresh TOTP code.
         Recovery codes deliberately do NOT work here. --}}
    @if ($bulkConfirmOpen)
        @php
            $selectedCount = count(array_filter($selected));
            $expectedPhrase = 'DELETE '.$selectedCount.' TENANT'.($selectedCount === 1 ? '' : 'S');
            $selectedIds = array_map('intval', array_keys(array_filter($selected)));
            $selectedTenants = \App\Models\Tenant::query()->whereIn('id', $selectedIds)->get();
        @endphp
        <div style="position: fixed; inset: 0; background: rgba(15, 18, 21, 0.55); backdrop-filter: blur(4px);
                    display: flex; align-items: center; justify-content: center; z-index: 100; padding: 24px;"
             wire:click.self="closeBulkConfirm">
            <div style="max-width: 560px; width: 100%; background: var(--color-card);
                        border-radius: 16px; padding: 28px 26px;
                        border: 1px solid rgba(184, 92, 66, 0.35);
                        box-shadow: 0 60px 120px -40px rgba(0, 0, 0, 0.55);"
                 data-testid="bulk-delete-panel">
                <span class="lp-eyebrow-strip">Danger zone</span>
                <h2 class="lp-display" style="margin-top: 12px; font-size: 24px; font-weight: 600; color: #B85C42; line-height: 1.05;">
                    Delete {{ $selectedCount }} tenant{{ $selectedCount === 1 ? '' : 's' }}
                </h2>
                <p style="margin-top: 8px; font-size: 13.5px; color: var(--color-ink); max-width: 46ch;">
                    Every row belonging to the tenants below - properties, rooms,
                    bookings, payments, staff, guests, requisitions - is wiped
                    permanently. There is no undo.
                </p>

                <div style="margin-top: 16px; padding: 12px 14px; background: var(--color-card-deep); border-radius: 10px; font-size: 12.5px; max-height: 140px; overflow-y: auto;">
                    <ul style="list-style: none; margin: 0; padding: 0;">
                        @foreach ($selectedTenants as $t)
                            <li style="display: flex; justify-content: space-between; padding: 3px 0;">
                                <span style="color: var(--color-ink); font-weight: 500;">{{ $t->name }}</span>
                                <code class="lp-mono" style="color: var(--color-ink-mute); font-size: 11.5px;">{{ $t->slug }}</code>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <form wire:submit.prevent="bulkDestroy" style="margin-top: 20px;">
                    <div>
                        <label for="bulk-phrase" class="lp-form-label">
                            Type <code>{{ $expectedPhrase }}</code>
                        </label>
                        <input id="bulk-phrase" type="text" wire:model="bulkPhrase" required autocomplete="off"
                               class="lp-input lp-mono" placeholder="{{ $expectedPhrase }}"
                               data-testid="bulk-phrase">
                        @error('bulkPhrase') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div style="margin-top: 14px;">
                        <label for="bulk-reason" class="lp-form-label">Reason (for the audit log)</label>
                        <textarea id="bulk-reason" wire:model="bulkReason" required rows="2"
                                  class="lp-input"
                                  placeholder="Bulk cleanup of test accounts / GDPR erasure batch / ..."
                                  data-testid="bulk-reason"></textarea>
                        @error('bulkReason') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div style="margin-top: 14px;">
                        <label for="bulk-totp" class="lp-form-label">Current 2FA code</label>
                        <input id="bulk-totp" type="text" inputmode="numeric" maxlength="6"
                               wire:model="bulkTotpCode" required autocomplete="one-time-code"
                               class="lp-input lp-mono" style="letter-spacing: 0.2em; font-size: 18px; width: 180px;"
                               placeholder="000 000"
                               data-testid="bulk-totp">
                        <p class="lp-form-help" style="margin-top: 6px;">
                            Recovery codes do NOT work here.
                        </p>
                        @error('bulkTotpCode') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div style="margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" class="lp-pill"
                                style="background: #B85C42; color: #fff;"
                                wire:loading.attr="disabled" wire:target="bulkDestroy"
                                data-testid="bulk-destroy-submit">
                            <span wire:loading.remove wire:target="bulkDestroy">
                                Delete {{ $selectedCount }} permanently
                            </span>
                            <span wire:loading wire:target="bulkDestroy">Wiping…</span>
                            <span class="lp-pill-dot" style="background: rgba(255,255,255,0.25); color: #fff;">
                                <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h8M4 3V2h4v1M4.5 6v3M7.5 6v3M3 3l0.5 8h5L9 3"/></svg>
                            </span>
                        </button>
                        <button type="button" wire:click="closeBulkConfirm" class="lp-btn lp-btn--ghost">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
