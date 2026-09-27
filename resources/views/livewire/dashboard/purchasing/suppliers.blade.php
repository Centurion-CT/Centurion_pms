<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="flex flex-wrap items-start justify-between gap-3 mt-4">
            <div>
                <span class="lp-eyebrow-strip">Purchasing</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Suppliers
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    Vendors you buy stock from. Every purchase order attaches to one.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('tenant.purchasing.orders.index') }}" wire:navigate class="lp-btn">Purchase orders</a>
                <button type="button" wire:click="create" class="lp-btn lp-btn--primary" data-testid="supplier-new">
                    + New supplier
                </button>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="lp-flash-success" data-testid="supplier-flash">{{ session('status') }}</div>
    @endif

    {{-- ── Search ─────────────────────────────────────────────── --}}
    <div class="flex items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Search suppliers by name, contact, phone, email"
               class="lp-input" style="flex: 1; max-width: 480px;"
               data-testid="supplier-search">
    </div>

    {{-- ── Drawer form ────────────────────────────────────────── --}}
    @if ($showForm)
        <form wire:submit="save" class="lp-dash-card" data-testid="supplier-form" style="display: grid; gap: 14px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">{{ $editingId ? 'Edit supplier' : 'New supplier' }}</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="s-name" class="lp-form-label">Name *</label>
                    <input id="s-name" type="text" wire:model="name" maxlength="160"
                           class="lp-input" data-testid="supplier-name" required>
                    @error('name') <span class="lp-form-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="s-contact" class="lp-form-label">Contact person</label>
                    <input id="s-contact" type="text" wire:model="contactName" maxlength="120"
                           class="lp-input" data-testid="supplier-contact">
                </div>
                <div>
                    <label for="s-phone" class="lp-form-label">Phone</label>
                    <input id="s-phone" type="text" wire:model="phone" maxlength="40"
                           class="lp-input" data-testid="supplier-phone">
                </div>
                <div>
                    <label for="s-email" class="lp-form-label">Email</label>
                    <input id="s-email" type="email" wire:model="email" maxlength="160"
                           class="lp-input" data-testid="supplier-email">
                </div>
                <div class="sm:col-span-2">
                    <label for="s-address" class="lp-form-label">Address</label>
                    <input id="s-address" type="text" wire:model="address" maxlength="240"
                           class="lp-input" data-testid="supplier-address">
                </div>
                <div class="sm:col-span-2">
                    <label for="s-notes" class="lp-form-label">Notes</label>
                    <textarea id="s-notes" wire:model="notes" rows="3" maxlength="2000"
                              class="lp-input" data-testid="supplier-notes"></textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-[13.5px]" style="color: var(--color-ink-soft);">
                        <input type="checkbox" wire:model="active" data-testid="supplier-active">
                        Active, appears in the supplier picker when composing POs.
                    </label>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
                 style="border-top: 1px solid var(--color-line-soft);">
                <button type="button" wire:click="cancel" class="lp-btn">Cancel</button>
                <button type="submit" class="lp-btn lp-btn--primary" data-testid="supplier-save">
                    {{ $editingId ? 'Save changes' : 'Add supplier' }}
                </button>
            </div>
        </form>
    @endif

    {{-- ── Table ──────────────────────────────────────────────── --}}
    @if ($suppliers->isEmpty())
        <section class="lp-dash-card" data-testid="suppliers-empty" style="text-align: center; padding: 48px 24px;">
            <h2 class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                No suppliers yet.
            </h2>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute); line-height: 1.5;">
                Add your first vendor to start recording purchase orders.
            </p>
            <button type="button" wire:click="create" class="lp-btn lp-btn--primary mt-4">
                Add supplier
            </button>
        </section>
    @else
        <section class="lp-dash-card">
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact</th>
                            <th>Phone / email</th>
                            <th style="text-align: right;">Open POs</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suppliers as $s)
                            <tr data-testid="supplier-row-{{ $s->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $s->name }}
                                    @if (! $s->active)
                                        <span class="lp-mono text-[10.5px]"
                                              style="margin-left: 6px; padding: 2px 8px; border-radius: 999px; background: rgba(0,0,0,0.06); color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
                                            Inactive
                                        </span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft);">{{ $s->contact_name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">
                                    @if ($s->phone) <div>{{ $s->phone }}</div> @endif
                                    @if ($s->email) <div class="lp-mono text-[11.5px]">{{ $s->email }}</div> @endif
                                </td>
                                <td style="text-align: right;" class="lp-mono">
                                    {{ number_format($s->open_po_count) }}
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" wire:click="edit({{ $s->id }})"
                                            class="lp-btn" style="font-size: 12px; padding: 4px 12px;"
                                            data-testid="supplier-edit-{{ $s->id }}">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
