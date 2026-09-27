@php
    $statusClass = match($corporate->status->value) {
        'active'     => 'lp-tag--confirmed',
        'onboarding' => 'lp-tag--checked-in',
        default      => 'lp-tag--checked-out',
    };
    $kycClass = match($corporate->kyc_status->value) {
        'verified' => 'lp-tag--confirmed',
        'rejected' => 'lp-tag--checked-out',
        default    => 'lp-tag--checked-in',
    };
@endphp

<div class="lp-dash">

    @include('_partials._back-button')

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Corporate</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $corporate->name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Primary contact: {{ $corporate->primary_email }}
                @if ($corporate->primary_phone)
                    <span style="color: var(--color-ink-mute);">·</span> {{ $corporate->primary_phone }}
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="lp-tag {{ $statusClass }}">{{ $corporate->status->label() }}</span>
            <span class="lp-tag {{ $kycClass }}">KYC · {{ $corporate->kyc_status->label() }}</span>
        </div>
    </div>

    {{-- ── Accepting-reservations toggle (owned corps) ────────────── --}}
    @if ($isOwned)
        <section class="lp-dash-card"
                 data-testid="accepts-reservations-card"
                 style="padding: 16px 20px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
                <div class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                     style="color: var(--color-ink-mute);">
                    Reservation acceptance
                </div>
                <div style="font-size: 13.5px; margin-top: 4px; color: var(--color-ink);">
                    @if ($corporate->accepts_reservations)
                        <strong style="font-weight: 600;">Open</strong>
                        <span style="color: var(--color-ink-soft);">
                            (new corporate reservations can be created for this corp).
                        </span>
                    @else
                        <strong style="font-weight: 600; color: #B85C42;">Paused</strong>
                        <span style="color: var(--color-ink-soft);">
                            (the "New reservation" form below will refuse until you re-open).
                        </span>
                    @endif
                </div>
            </div>
            <button type="button"
                    wire:click="toggleAcceptsReservations"
                    data-testid="toggle-accepts-reservations"
                    class="lp-btn" style="font-size: 12.5px;">
                {{ $corporate->accepts_reservations ? 'Pause acceptance' : 'Resume acceptance' }}
            </button>
        </section>
    @endif

    {{-- ── Counters ──────────────────────────────────────────────── --}}
    @php
        // Everything the corp currently owes: unpaid Issued invoices
        // (ledgerOutstanding) + stays that closed with a balance but
        // haven't been billed yet (uninvoicedOutstanding). Both live
        // on the ledger surface below.
        $totalOutstanding = (int) $ledgerOutstanding + (int) $uninvoicedOutstanding;
    @endphp
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="corp-stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Bookings here</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['count']) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">GMV from this corporate</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                {{ money($stats['gmv']) }}
            </p>
        </div>
        <div class="lp-dash-card" data-testid="corp-outstanding-card"
             style="padding: 18px 22px;
                    {{ $totalOutstanding > 0 ? 'background: rgba(180, 83, 9, 0.05); border-color: rgba(180, 83, 9, 0.25);' : '' }}">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Outstanding</span>
            <p class="lp-display mt-2 text-[28px]"
               style="color: {{ $totalOutstanding > 0 ? '#a36318' : 'var(--color-ink)' }}; font-weight: 600;">
                {{ money($totalOutstanding) }}
            </p>
            @if ($totalOutstanding > 0)
                <p class="text-[11.5px] mt-1" style="color: var(--color-ink-soft);">
                    @if ($ledgerOutstanding > 0 && $uninvoicedOutstanding > 0)
                        {{ money($ledgerOutstanding) }} on unpaid invoices,
                        {{ money($uninvoicedOutstanding) }} not yet invoiced
                    @elseif ($ledgerOutstanding > 0)
                        @php
                            $unpaidCount = $ledgerInvoices->whereIn('status',
                                [\App\Enums\InvoiceStatus::Issued, \App\Enums\InvoiceStatus::Partial])->count();
                        @endphp
                        On {{ $unpaidCount }} unpaid invoice{{ $unpaidCount === 1 ? '' : 's' }}
                    @else
                        {{ $uninvoicedStays->count() }} stay{{ $uninvoicedStays->count() === 1 ? '' : 's' }} ready to invoice
                    @endif
                </p>
            @endif
        </div>
        {{-- Wallet balance: available = balance − held. Clickable for
             the top-up drawer. Green tinted when there's a positive
             available balance so the operator sees at a glance the
             corp can pay from wallet. --}}
        @if ($isOwned)
            <button type="button" wire:click="openTopupForm"
                    data-testid="corp-wallet-card"
                    class="lp-dash-card"
                    style="padding: 18px 22px; text-align: left; cursor: pointer;
                           {{ $walletAvailable > 0 ? 'background: rgba(122, 168, 116, 0.06); border-color: rgba(122, 168, 116, 0.28);' : '' }}">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                    <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                          style="color: var(--color-ink-mute);">Wallet</span>
                    <span class="lp-mono text-[10px] tracking-[0.14em] uppercase"
                          style="color: {{ $walletAvailable > 0 ? '#3b6a35' : 'var(--color-ink-mute)' }};">
                        Top up →
                    </span>
                </div>
                <p class="lp-display mt-2 text-[28px]"
                   style="color: {{ $walletAvailable > 0 ? '#3b6a35' : 'var(--color-ink)' }}; font-weight: 600;">
                    {{ money($walletAvailable) }}
                </p>
                @if ($walletHeld > 0)
                    <p class="text-[11.5px] mt-1" style="color: var(--color-ink-soft);">
                        {{ money($walletHeld) }} held on open bookings
                    </p>
                @endif
            </button>
        @endif
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Last activity</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                @if ($stats['last_activity'])
                    {{ \Illuminate\Support\Carbon::parse($stats['last_activity'])->diffForHumans(['parts' => 1]) }}
                @else
                    -
                @endif
            </p>
        </div>
    </div>

    {{-- ── Edit corporate CTA (owned corps only) ─────────────────── --}}
    @if ($isOwned)
        <div class="flex gap-2 justify-end">
            <a href="{{ route('tenant.corporates.edit', ['corporate' => $corporate]) }}"
               wire:navigate class="lp-btn" style="font-size: 12.5px;"
               data-testid="edit-corporate">
                Edit corporate
            </a>
        </div>
    @endif

    {{-- ── Quick actions strip (owned corps) ─────────────────────
         Four config surfaces (staff, rates, LoS discounts, reservations)
         used to render as always-expanded sections stacked down the
         page — too much scroll for corps with real rosters. Now
         they're pills at the top; clicking one swaps the panel
         underneath. --}}
    @if ($isOwned)
        @php
            /* Each panel gets its own color so the four surfaces stay
               distinguishable at a glance even when the operator isn't
               reading the label. `dark` fills the pill when active
               (with white text); `soft` tints the inactive pill's
               left border so the color story stays alive even before
               anyone's picked one. Chosen from the same palette the
               sidebar tiles use — staff/emerald, rates/amber,
               LoS/violet, reservations/sky. */
            $panelPills = [
                ['key' => 'staff',
                 'label' => 'Profiled staff',
                 'count' => $members->count(),
                 'hint' => 'Add + edit corp travellers',
                 'testid' => 'panel-pill-staff',
                 'dark' => '#047857',
                 'soft' => 'rgba(4, 120, 87, 0.35)'],
                ['key' => 'rates',
                 'label' => 'Category rates',
                 'count' => $categoryRates->count(),
                 'hint' => 'Negotiated per-category rates',
                 'testid' => 'panel-pill-rates',
                 'dark' => '#B45309',
                 'soft' => 'rgba(180, 83, 9, 0.35)'],
                ['key' => 'los',
                 'label' => 'Length-of-stay',
                 'count' => $losDiscounts->count(),
                 'hint' => 'Reward long stays with % off',
                 'testid' => 'panel-pill-los',
                 'dark' => '#6D28D9',
                 'soft' => 'rgba(109, 40, 217, 0.35)'],
                ['key' => 'reservations',
                 'label' => 'Reservations',
                 'count' => $reservations->count(),
                 'hint' => 'Windows + expected staff',
                 'testid' => 'panel-pill-reservations',
                 'dark' => '#0369A1',
                 'soft' => 'rgba(3, 105, 161, 0.35)'],
            ];
        @endphp
        <div data-testid="config-quick-actions"
             style="display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
            @foreach ($panelPills as $pill)
                @php
                    $isActive = $activePanel === $pill['key'];
                    $accent = $pill['dark'];
                    $bg = $isActive ? $accent : 'var(--color-card)';
                    $fg = $isActive ? '#FFFFFF' : 'var(--color-ink)';
                    $subFg = $isActive ? 'rgba(255,255,255,0.75)' : 'var(--color-ink-soft)';
                    $labelFg = $isActive ? 'rgba(255,255,255,0.8)' : 'var(--color-ink-mute)';
                    $borderColor = $isActive ? $accent : 'var(--color-line)';
                    $leftAccent = $isActive ? 'transparent' : $accent;
                @endphp
                <button type="button"
                        wire:click="$set('activePanel', '{{ $pill['key'] }}')"
                        data-testid="{{ $pill['testid'] }}"
                        @class([
                            'lp-dash-card' => true,
                            'is-active' => $isActive,
                        ])
                        style="padding: 14px 16px; display: flex; flex-direction: column;
                               align-items: flex-start; gap: 4px; text-align: left;
                               cursor: pointer; font-family: inherit; position: relative;
                               border: 1px solid {{ $borderColor }};
                               border-left: 4px solid {{ $leftAccent === 'transparent' ? $accent : $leftAccent }};
                               background: {{ $bg }};
                               color: {{ $fg }};
                               transition: border-color .12s ease, background .12s ease, color .12s ease;">
                    <div style="display: flex; align-items: center; justify-content: space-between;
                                gap: 8px; width: 100%;">
                        <span class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                              style="color: {{ $labelFg }};">
                            {{ $pill['label'] }}
                        </span>
                        <span class="lp-num" style="font-size: 12px;
                                                    color: {{ $isActive ? '#FFFFFF' : $accent }};
                                                    font-weight: 600;">
                            {{ $pill['count'] }}
                        </span>
                    </div>
                    <span style="font-size: 12.5px; color: {{ $subFg }};">
                        {{ $pill['hint'] }}
                    </span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- ── Members directory ─────────────────────────────────────── --}}
    @if ((! $isOwned && $members->isNotEmpty()) || ($isOwned && $activePanel === 'staff'))
        <section class="lp-dash-card" style="padding: 0;" data-testid="members-section">
            <div class="lp-dash-card-head"
                 style="padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                <div>
                    <span class="lp-dash-card-title">
                        {{ $isOwned ? 'Profiled staff' : 'Members who\'ve booked here' }}
                    </span>
                    <div class="lp-dash-card-meta" style="margin-top: 4px;">
                        {{ $members->count() }} {{ $members->count() === 1 ? 'person' : 'people' }}
                        @if ($isOwned)
                            <span style="color: var(--color-ink-mute); margin: 0 4px;">·</span>
                            <span style="color: var(--color-ink-mute); font-size: 11.5px;">
                                Active members can be checked in without payment collection.
                            </span>
                        @endif
                    </div>
                </div>
                @if ($isOwned)
                    <button type="button" wire:click="newMember"
                            data-testid="new-member"
                            class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                        Add staff
                    </button>
                @endif
            </div>

            {{-- Add / edit staff form — right-side slide-out panel,
                 teleported to <body> so the drawer sits on top of the
                 layout instead of clipping into the members card. --}}
            @if ($memberFormOpen && $isOwned)
                <template x-teleport="body">
                <div wire:key="corp-member-panel-root" class="lp-page">
                    <div x-transition.opacity.duration.150ms
                         wire:click="closeMemberForm"
                         style="position: fixed; inset: 0; z-index: 199;
                                background: rgba(20, 17, 12, 0.28);"></div>
                    <aside data-testid="member-form"
                           role="dialog" aria-modal="true"
                           x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="translate-x-full"
                           x-transition:enter-end="translate-x-0"
                           x-on:keydown.escape.window="$wire.closeMemberForm()"
                           style="position: fixed; top: 0; right: 0; bottom: 0;
                                  width: min(520px, 100vw); z-index: 200;
                                  background: var(--color-card);
                                  border-left: 1px solid var(--color-line);
                                  box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                                  display: flex; flex-direction: column;">
                        <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                    display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                            <div>
                                <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                                   style="color: var(--color-ink-mute);">
                                    Corporate
                                </p>
                                <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                    {{ $editingMemberId ? 'Edit staff member' : 'Add staff' }}
                                </h2>
                                <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                    Active members can be checked in without payment collection,
                                    with the balance billed to the corp on the next invoice.
                                </p>
                            </div>
                            <button type="button" wire:click="closeMemberForm"
                                    class="lp-btn" style="font-size: 12px;"
                                    aria-label="Close panel">Close</button>
                        </div>
                        <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <label class="lp-form-label" style="font-size: 10.5px;">Name</label>
                                    <input type="text" wire:model="memberName"
                                           data-testid="member-name" class="lp-input" style="font-size: 13px;">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="lp-form-label" style="font-size: 10.5px;">Email</label>
                                    <input type="email" wire:model="memberEmail"
                                           data-testid="member-email" class="lp-input" style="font-size: 13px;">
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Phone <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                    </label>
                                    <input type="text" wire:model="memberPhone"
                                           data-testid="member-phone" class="lp-input" style="font-size: 13px;">
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Employee ID <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                    </label>
                                    <input type="text" wire:model="memberEmployeeId"
                                           data-testid="member-employee-id" class="lp-input" style="font-size: 13px;">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="lp-form-label" style="font-size: 10.5px;">Role</label>
                                    <select wire:model="memberRole" data-testid="member-role"
                                            class="lp-input" style="font-size: 13px;">
                                        <option value="member">Member (staff traveller)</option>
                                        <option value="admin">Admin (approves + adds others)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                    display: flex; justify-content: flex-end; gap: 8px;">
                            <button type="button" wire:click="closeMemberForm"
                                    class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                            <button type="button" wire:click="saveMember"
                                    data-testid="member-save"
                                    class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                                <span wire:loading.remove wire:target="saveMember">
                                    {{ $editingMemberId ? 'Save changes' : 'Add staff' }}
                                </span>
                                <span wire:loading wire:target="saveMember">Saving...</span>
                            </button>
                        </div>
                    </aside>
                </div>
                </template>
            @endif

            @if ($members->isEmpty())
                <p class="text-[13.5px] text-center" style="padding: 40px 20px; color: var(--color-ink-mute);"
                   data-testid="no-members">
                    @if ($isOwned)
                        No staff profiled yet. Click "Add staff" to register who can stay at your property under this corp.
                    @else
                        No members have booked here yet.
                    @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="members-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Employee ID</th>
                                @if ($isOwned)
                                    <th style="text-align: right;">Actions</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($members as $m)
                                <tr data-testid="member-row-{{ $m->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">{{ $m->name }}</td>
                                    <td style="color: var(--color-ink-soft);">{{ $m->email }}</td>
                                    <td>
                                        <span class="lp-tag {{ $m->role?->value === 'admin' ? 'lp-tag--confirmed' : 'lp-tag--checked-in' }}">
                                            {{ $m->role?->label() ?? '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($m->status === \App\Enums\CorporateMemberStatus::Active)
                                            <span class="lp-tag lp-tag--confirmed">Active</span>
                                        @elseif ($m->status === \App\Enums\CorporateMemberStatus::Invited)
                                            <span class="lp-tag lp-tag--checked-in">Invited</span>
                                        @else
                                            <span class="lp-tag lp-tag--checked-out">Offboarded</span>
                                        @endif
                                    </td>
                                    <td class="lp-mono" style="color: var(--color-ink-mute); font-size: 11.5px;">
                                        {{ $m->employee_id ?? '-' }}
                                    </td>
                                    @if ($isOwned)
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; gap: 6px;">
                                                <button type="button" wire:click="editMember({{ $m->id }})"
                                                        data-testid="edit-member-{{ $m->id }}"
                                                        class="lp-btn" style="font-size: 12px;">
                                                    Edit
                                                </button>
                                                <button type="button" wire:click="toggleMemberStatus({{ $m->id }})"
                                                        wire:confirm="{{ $m->status === \App\Enums\CorporateMemberStatus::Active ? 'Offboard '.$m->name.'? They will no longer bypass approval on check-in.' : 'Reactivate '.$m->name.'?' }}"
                                                        data-testid="toggle-member-{{ $m->id }}"
                                                        class="lp-btn" style="font-size: 12px;">
                                                    {{ $m->status === \App\Enums\CorporateMemberStatus::Active ? 'Offboard' : 'Reactivate' }}
                                                </button>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Rate configuration (owned corps only) ─────────────────── --}}
    @if ($isOwned && $activePanel === 'rates')
        {{-- Category rates. Pick a room category on any branch,
             set % off / flat off / flat price, optionally guard by
             min nights. Rate resolution at booking time picks the
             most specific active rate. Currency renders per the
             property's picked currency. --}}
        <section class="lp-dash-card" style="padding: 0;" data-testid="category-rates-section">
            <div class="lp-dash-card-head"
                 style="padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                <div>
                    <span class="lp-dash-card-title">Category rates</span>
                    <div class="lp-dash-card-meta" style="margin-top: 4px; color: var(--color-ink-mute); font-size: 11.5px;">
                        Negotiated rates per room category. Applies whenever a booking is made
                        for this corporate at that category, across all branches.
                    </div>
                </div>
                <button type="button" wire:click="newCategoryRate"
                        data-testid="new-category-rate"
                        class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                    Add rate
                </button>
            </div>

            @if ($categoryRateFormOpen)
                <template x-teleport="body">
                <div wire:key="corp-rate-panel-root" class="lp-page">
                    <div x-transition.opacity.duration.150ms
                         wire:click="closeCategoryRateForm"
                         style="position: fixed; inset: 0; z-index: 199;
                                background: rgba(20, 17, 12, 0.28);"></div>
                    <aside data-testid="category-rate-form"
                           role="dialog" aria-modal="true"
                           x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="translate-x-full"
                           x-transition:enter-end="translate-x-0"
                           x-on:keydown.escape.window="$wire.closeCategoryRateForm()"
                           style="position: fixed; top: 0; right: 0; bottom: 0;
                                  width: min(520px, 100vw); z-index: 200;
                                  background: var(--color-card);
                                  border-left: 1px solid var(--color-line);
                                  box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                                  display: flex; flex-direction: column;">
                        <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                    display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                            <div>
                                <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                                   style="color: var(--color-ink-mute);">
                                    Corporate
                                </p>
                                <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                    Add rate
                                </h2>
                                <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                    Applies whenever this corp books at the picked category, on
                                    any branch. Overrides the public price at booking time.
                                </p>
                            </div>
                            <button type="button" wire:click="closeCategoryRateForm"
                                    class="lp-btn" style="font-size: 12px;"
                                    aria-label="Close panel">Close</button>
                        </div>
                        <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <label class="lp-form-label" style="font-size: 10.5px;">Room category</label>
                                    <select wire:model="categoryRateCategoryId"
                                            data-testid="rate-category" class="lp-input" style="font-size: 13px;">
                                        <option value="">Pick a category</option>
                                        @foreach ($categoryOptions as $cat)
                                            <option value="{{ $cat->id }}">
                                                {{ $cat->property?->name ? $cat->property->name.' · ' : '' }}{{ $cat->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">Rate type</label>
                                    <select wire:model="categoryRateType"
                                            data-testid="rate-type" class="lp-input" style="font-size: 13px;">
                                        <option value="percent_off">% off</option>
                                        <option value="fixed_off">{{ currency_symbol() }} off per night</option>
                                        <option value="fixed_price">Flat {{ currency_symbol() }} price per night</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">Value</label>
                                    <input type="number" min="1" step="1" wire:model="categoryRateValue"
                                           data-testid="rate-value" class="lp-input" style="font-size: 13px;">
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Min nights <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                    </label>
                                    <input type="number" min="1" step="1" wire:model="categoryRateMinNights"
                                           data-testid="rate-min-nights" class="lp-input"
                                           placeholder="e.g. 3" style="font-size: 13px;">
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Notes <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                    </label>
                                    <input type="text" wire:model="categoryRateNotes"
                                           data-testid="rate-notes" class="lp-input" style="font-size: 13px;">
                                </div>
                            </div>
                        </div>
                        <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                    display: flex; justify-content: flex-end; gap: 8px;">
                            <button type="button" wire:click="closeCategoryRateForm"
                                    class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                            <button type="button" wire:click="saveCategoryRate"
                                    data-testid="rate-save"
                                    class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                                <span wire:loading.remove wire:target="saveCategoryRate">Save rate</span>
                                <span wire:loading wire:target="saveCategoryRate">Saving...</span>
                            </button>
                        </div>
                    </aside>
                </div>
                </template>
            @endif

            @if ($categoryRates->isEmpty())
                <p class="text-[13.5px] text-center" style="padding: 40px 20px; color: var(--color-ink-mute);"
                   data-testid="no-category-rates">
                    No negotiated category rates yet. Add one to override the public price for this corp.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="category-rates-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Discount</th>
                                <th>Min nights</th>
                                <th>Notes</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($categoryRates as $r)
                                @php
                                    $cat = $r->getRelation('room_category_resolved') ?? null;
                                    $label = match ($r->rate_type->value) {
                                        'percent_off'  => $r->rate_value.'% off',
                                        'fixed_off'    => money($r->rate_value).' off',
                                        'fixed_price'  => money($r->rate_value).' flat',
                                    };
                                @endphp
                                <tr data-testid="category-rate-row-{{ $r->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        {{ $cat?->name ?? 'Category #'.$r->scope_id }}
                                        @if ($cat?->property)
                                            <div class="text-[11px] mt-0.5" style="color: var(--color-ink-mute);">
                                                {{ $cat->property->name }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="lp-num">{!! $label !!}</td>
                                    <td>
                                        @if ($r->min_nights)
                                            {{ $r->min_nights }}+
                                        @else
                                            <span style="color: var(--color-ink-mute);">-</span>
                                        @endif
                                    </td>
                                    <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                        {{ $r->notes ?? '-' }}
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <button type="button" wire:click="editCategoryRate({{ $r->id }})"
                                                    data-testid="edit-category-rate-{{ $r->id }}"
                                                    class="lp-btn" style="font-size: 12px;">Edit</button>
                                            <button type="button" wire:click="deleteCategoryRate({{ $r->id }})"
                                                    wire:confirm="Remove this category rate?"
                                                    data-testid="delete-category-rate-{{ $r->id }}"
                                                    class="lp-btn" style="font-size: 12px; color: #B85C42;">Remove</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- Length-of-stay discounts. Corporate-wide, so the same
         discount tier applies at any branch. Modelled as Global-
         scoped % off with min_nights so the resolution walk
         stacks it under any category rate transparently. --}}
    @if ($isOwned && $activePanel === 'los')
        <section class="lp-dash-card" style="padding: 0;" data-testid="los-section">
            <div class="lp-dash-card-head"
                 style="padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                <div>
                    <span class="lp-dash-card-title">Length-of-stay discounts</span>
                    <div class="lp-dash-card-meta" style="margin-top: 4px; color: var(--color-ink-mute); font-size: 11.5px;">
                        Reward longer stays with an extra % off. Applies on top of any category rate.
                    </div>
                </div>
                <button type="button" wire:click="newLos"
                        data-testid="new-los"
                        class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                    Add tier
                </button>
            </div>

            @if ($losFormOpen)
                <template x-teleport="body">
                <div wire:key="corp-los-panel-root" class="lp-page">
                    <div x-transition.opacity.duration.150ms
                         wire:click="closeLosForm"
                         style="position: fixed; inset: 0; z-index: 199;
                                background: rgba(20, 17, 12, 0.28);"></div>
                    <aside data-testid="los-form"
                           role="dialog" aria-modal="true"
                           x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="translate-x-full"
                           x-transition:enter-end="translate-x-0"
                           x-on:keydown.escape.window="$wire.closeLosForm()"
                           style="position: fixed; top: 0; right: 0; bottom: 0;
                                  width: min(520px, 100vw); z-index: 200;
                                  background: var(--color-card);
                                  border-left: 1px solid var(--color-line);
                                  box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                                  display: flex; flex-direction: column;">
                        <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                    display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                            <div>
                                <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                                   style="color: var(--color-ink-mute);">
                                    Corporate
                                </p>
                                <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                    Add length-of-stay tier
                                </h2>
                                <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                    Reward longer stays with an extra % off. Stacks on top of any
                                    negotiated category rate.
                                </p>
                            </div>
                            <button type="button" wire:click="closeLosForm"
                                    class="lp-btn" style="font-size: 12px;"
                                    aria-label="Close panel">Close</button>
                        </div>
                        <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">Minimum nights</label>
                                    <input type="number" min="2" step="1" wire:model="losMinNights"
                                           data-testid="los-min-nights" class="lp-input"
                                           placeholder="e.g. 7" style="font-size: 13px;">
                                </div>
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">Discount (%)</label>
                                    <input type="number" min="1" max="100" step="1" wire:model="losPercentOff"
                                           data-testid="los-percent" class="lp-input"
                                           placeholder="e.g. 10" style="font-size: 13px;">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Notes <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                    </label>
                                    <input type="text" wire:model="losNotes"
                                           data-testid="los-notes" class="lp-input" style="font-size: 13px;">
                                </div>
                            </div>
                        </div>
                        <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                    display: flex; justify-content: flex-end; gap: 8px;">
                            <button type="button" wire:click="closeLosForm"
                                    class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                            <button type="button" wire:click="saveLos"
                                    data-testid="los-save"
                                    class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                                <span wire:loading.remove wire:target="saveLos">Save tier</span>
                                <span wire:loading wire:target="saveLos">Saving...</span>
                            </button>
                        </div>
                    </aside>
                </div>
                </template>
            @endif

            @if ($losDiscounts->isEmpty())
                <p class="text-[13.5px] text-center" style="padding: 40px 20px; color: var(--color-ink-mute);"
                   data-testid="no-los">
                    No length-of-stay discounts configured.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="los-table">
                        <thead>
                            <tr>
                                <th>Minimum nights</th>
                                <th>Discount</th>
                                <th>Notes</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($losDiscounts as $r)
                                <tr data-testid="los-row-{{ $r->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        {{ $r->min_nights }}+ nights
                                    </td>
                                    <td class="lp-num">{{ $r->rate_value }}% off</td>
                                    <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                        {{ $r->notes ?? '-' }}
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <button type="button" wire:click="editLos({{ $r->id }})"
                                                    data-testid="edit-los-{{ $r->id }}"
                                                    class="lp-btn" style="font-size: 12px;">Edit</button>
                                            <button type="button" wire:click="deleteLos({{ $r->id }})"
                                                    wire:confirm="Remove this length-of-stay discount?"
                                                    data-testid="delete-los-{{ $r->id }}"
                                                    class="lp-btn" style="font-size: 12px; color: #B85C42;">Remove</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Corporate reservations panel (owned corps only) ───────── --}}
    @if ($isOwned && $activePanel === 'reservations')
        <section class="lp-dash-card" style="padding: 0;" data-testid="reservations-section">
            <div class="lp-dash-card-head"
                 style="padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                <div>
                    <span class="lp-dash-card-title">Corporate reservations</span>
                    <div class="lp-dash-card-meta" style="margin-top: 4px; color: var(--color-ink-mute); font-size: 11.5px;">
                        A stay window at a specific branch for expected staff, with a per-category room count.
                    </div>
                </div>
                <button type="button" wire:click="newReservation"
                        data-testid="new-reservation"
                        @disabled(! $corporate->accepts_reservations)
                        class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                    New reservation
                </button>
            </div>

            @if ($reservationFormOpen)
                <template x-teleport="body">
                <div wire:key="corp-reservation-panel-root" class="lp-page">
                    <div x-transition.opacity.duration.150ms
                         wire:click="closeReservationForm"
                         style="position: fixed; inset: 0; z-index: 199;
                                background: rgba(20, 17, 12, 0.28);"></div>
                    <aside data-testid="reservation-form"
                           role="dialog" aria-modal="true"
                           x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="translate-x-full"
                           x-transition:enter-end="translate-x-0"
                           x-on:keydown.escape.window="$wire.closeReservationForm()"
                           style="position: fixed; top: 0; right: 0; bottom: 0;
                                  width: min(640px, 100vw); z-index: 200;
                                  background: var(--color-card);
                                  border-left: 1px solid var(--color-line);
                                  box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                                  display: flex; flex-direction: column;">
                        <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                    display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                            <div>
                                <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                                   style="color: var(--color-ink-mute);">
                                    Corporate
                                </p>
                                <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                    {{ $editingReservationId ? 'Edit reservation' : 'New reservation' }}
                                </h2>
                                <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                    A stay window at a specific branch with a per-category room
                                    count and the expected staff who will show up.
                                </p>
                            </div>
                            <button type="button" wire:click="closeReservationForm"
                                    class="lp-btn" style="font-size: 12px;"
                                    aria-label="Close panel">Close</button>
                        </div>
                        <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="lp-form-label" style="font-size: 10.5px;">Property (branch)</label>
                            <select wire:model.live="reservationPropertyId"
                                    data-testid="res-property" class="lp-input" style="font-size: 13px;">
                                <option value="">Pick a branch</option>
                                @foreach ($properties as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lp-form-label" style="font-size: 10.5px;">Start date</label>
                            <input type="date" wire:model="reservationStartsAt"
                                   data-testid="res-starts" class="lp-input" style="font-size: 13px;">
                        </div>
                        <div>
                            <label class="lp-form-label" style="font-size: 10.5px;">End date</label>
                            <input type="date" wire:model="reservationEndsAt"
                                   data-testid="res-ends" class="lp-input" style="font-size: 13px;">
                        </div>
                    </div>

                    {{-- Category rows. Category dropdown filters to
                         the picked property's categories client-side
                         via @js so switching branch doesn't clear
                         previously-typed counts. --}}
                    @php
                        $propCats = $reservationPropertyId
                            ? ($categoriesByProperty->get((int) $reservationPropertyId) ?? collect())
                            : collect();
                    @endphp
                    <div style="margin-top: 14px;">
                        <div class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                            Room allocation
                        </div>
                        @foreach ($reservationCategoryRows as $idx => $row)
                            <div class="grid gap-2 md:grid-cols-[1fr_120px_auto]"
                                 style="align-items: end; margin-top: 8px;"
                                 data-testid="res-cat-row-{{ $idx }}">
                                <select wire:model="reservationCategoryRows.{{ $idx }}.room_category_id"
                                        class="lp-input" style="font-size: 13px;">
                                    <option value="">Pick a category</option>
                                    @foreach ($propCats as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                <input type="number" min="1" step="1"
                                       wire:model="reservationCategoryRows.{{ $idx }}.rooms_count"
                                       placeholder="Rooms"
                                       class="lp-input" style="font-size: 13px;">
                                <button type="button"
                                        wire:click="removeReservationCategoryRow({{ $idx }})"
                                        @disabled(count($reservationCategoryRows) === 1)
                                        class="lp-btn" style="font-size: 12px; color: #B85C42;">
                                    Remove
                                </button>
                            </div>
                        @endforeach
                        <div style="margin-top: 8px;">
                            <button type="button" wire:click="addReservationCategoryRow"
                                    class="lp-btn" style="font-size: 12px;">
                                + Another category
                            </button>
                        </div>
                    </div>

                    {{-- Expected members — checkbox grid. --}}
                    <div style="margin-top: 14px;">
                        <div class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                            Expected staff
                        </div>
                        @if ($activeMembers->isEmpty())
                            <p class="text-[12.5px]" style="color: var(--color-ink-mute); margin-top: 6px;">
                                No Active staff profiled yet. Add one in the Profiled staff section above first.
                            </p>
                        @else
                            <div class="grid gap-2 md:grid-cols-2" style="margin-top: 8px;">
                                @foreach ($activeMembers as $m)
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;
                                                  padding: 8px 10px; background: var(--color-card);
                                                  border: 1px solid var(--color-line-soft); border-radius: 8px;">
                                        <input type="checkbox"
                                               wire:click="toggleReservationMember({{ $m->id }})"
                                               @checked(in_array($m->id, $reservationMemberIds, true))
                                               data-testid="res-member-{{ $m->id }}">
                                        <span>
                                            <span style="display: block; font-size: 13px; color: var(--color-ink); font-weight: 500;">
                                                {{ $m->name }}
                                            </span>
                                            <span class="text-[11px]" style="color: var(--color-ink-mute);">
                                                {{ $m->email }}
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div style="margin-top: 14px;">
                        <label class="lp-form-label" style="font-size: 10.5px;">
                            Notes <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                        </label>
                        <textarea wire:model="reservationNotes" rows="2"
                                  data-testid="res-notes" class="lp-input" style="font-size: 13px;"></textarea>
                    </div>

                        </div>
                        <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                    display: flex; justify-content: flex-end; gap: 8px;">
                            <button type="button" wire:click="closeReservationForm"
                                    class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                            <button type="button" wire:click="saveReservation"
                                    data-testid="res-save"
                                    class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                                <span wire:loading.remove wire:target="saveReservation">
                                    {{ $editingReservationId ? 'Save changes' : 'Save reservation' }}
                                </span>
                                <span wire:loading wire:target="saveReservation">Saving...</span>
                            </button>
                        </div>
                    </aside>
                </div>
                </template>
            @endif

            @if ($reservations->isEmpty())
                <p class="text-[13.5px] text-center" style="padding: 40px 20px; color: var(--color-ink-mute);"
                   data-testid="no-reservations">
                    No corporate reservations yet.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="reservations-table">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th>Window</th>
                                <th>Allocation</th>
                                <th>Expected</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reservations as $r)
                                <tr data-testid="res-row-{{ $r->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        {{ $r->property?->name ?? '-' }}
                                    </td>
                                    <td class="text-[12.5px]" style="color: var(--color-ink);">
                                        {{ $r->starts_at?->toFormattedDateString() }}
                                        <span style="color: var(--color-ink-mute);">→</span>
                                        {{ $r->ends_at?->toFormattedDateString() }}
                                        <div class="text-[11px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                            {{ $r->nights() }} night{{ $r->nights() === 1 ? '' : 's' }}
                                        </div>
                                    </td>
                                    <td class="text-[12.5px]">
                                        @foreach ($r->categories as $ca)
                                            <div>
                                                {{ $ca->rooms_count }}× {{ $ca->roomCategory?->name ?? 'Category #'.$ca->room_category_id }}
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="text-[12.5px]">
                                        {{ $r->members->count() }} staff
                                    </td>
                                    <td>
                                        @if ($r->status === \App\Enums\CorporateReservationStatus::Active)
                                            <span class="lp-tag lp-tag--confirmed">Active</span>
                                        @elseif ($r->status === \App\Enums\CorporateReservationStatus::Cancelled)
                                            <span class="lp-tag lp-tag--checked-out">Cancelled</span>
                                        @elseif ($r->status === \App\Enums\CorporateReservationStatus::Closed)
                                            <span class="lp-tag lp-tag--checked-out">Closed</span>
                                        @else
                                            <span class="lp-tag lp-tag--checked-in">Pending</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right;">
                                        @if ($r->status !== \App\Enums\CorporateReservationStatus::Cancelled
                                             && $r->status !== \App\Enums\CorporateReservationStatus::Closed)
                                            <div style="display: inline-flex; gap: 6px;">
                                                <button type="button"
                                                        wire:click="editReservation({{ $r->id }})"
                                                        data-testid="edit-res-{{ $r->id }}"
                                                        class="lp-btn" style="font-size: 12px;">
                                                    Edit
                                                </button>
                                                <button type="button"
                                                        wire:click="cancelReservation({{ $r->id }})"
                                                        wire:confirm="Cancel this reservation? Expected staff can no longer be assigned."
                                                        data-testid="cancel-res-{{ $r->id }}"
                                                        class="lp-btn" style="font-size: 12px; color: #B85C42;">
                                                    Cancel
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Corporate ledger ──────────────────────────────────────
         All corp invoices in one place, with an outstanding total +
         Record payment form. Recording a payment fires the
         RecordCorporatePayment action which walks unpaid invoices
         oldest-first and auto-allocates until exhausted. --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="ledger-section">
        <div class="lp-dash-card-head"
             style="padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
            <div>
                <span class="lp-dash-card-title">Ledger</span>
                <div class="lp-dash-card-meta" style="margin-top: 4px; color: var(--color-ink-mute); font-size: 11.5px;">
                    Every invoice raised against this corp + all payments received. Recording a
                    payment automatically offsets the oldest unpaid invoices first.
                </div>
            </div>
            <div style="text-align: right;">
                <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute);">
                    Outstanding
                </div>
                <div class="lp-num" data-testid="ledger-outstanding"
                     style="font-size: 20px; color: {{ $ledgerOutstanding > 0 ? '#a36318' : 'var(--color-ink)' }};
                            font-weight: 600; margin-top: 2px;">
                    {{ money($ledgerOutstanding) }}
                </div>
                <div style="display: inline-flex; gap: 6px; margin-top: 8px;">
                    <button type="button" wire:click="openConsolidatedForm"
                            data-testid="generate-consolidated-invoice"
                            class="lp-btn" style="font-size: 12.5px;">
                        Generate invoice
                    </button>
                    <button type="button" wire:click="openPaymentForm"
                            data-testid="record-payment"
                            @disabled($ledgerOutstanding <= 0)
                            class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                        Record payment
                    </button>
                </div>
            </div>
        </div>

        {{-- Consolidated invoice form + Record payment form both open
             as right-side slide-out panels (teleported to <body> so
             position: fixed is relative to the viewport, not the ledger
             card's containing block). Keeping them out of the ledger
             card keeps that surface clean — the card only ever shows
             the outstanding number + the ledger rows. --}}
        @if ($consolidatedFormOpen)
            <template x-teleport="body">
            <div wire:key="corp-consolidated-panel-root" class="lp-page">
                <div x-transition.opacity.duration.150ms
                     wire:click="closeConsolidatedForm"
                     style="position: fixed; inset: 0; z-index: 199;
                            background: rgba(20, 17, 12, 0.28);"></div>
                <aside data-testid="consolidated-form"
                       role="dialog" aria-modal="true"
                       x-transition:enter="transition ease-out duration-200"
                       x-transition:enter-start="translate-x-full"
                       x-transition:enter-end="translate-x-0"
                       x-on:keydown.escape.window="$wire.closeConsolidatedForm()"
                       style="position: fixed; top: 0; right: 0; bottom: 0;
                              width: min(520px, 100vw); z-index: 200;
                              background: var(--color-card);
                              border-left: 1px solid var(--color-line);
                              box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                              display: flex; flex-direction: column;">
                    <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                        <div>
                            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                               style="color: var(--color-ink-mute);">
                                Corporate
                            </p>
                            <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                Generate invoice
                            </h2>
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                Bundles every uninvoiced stay whose checkout falls in the picked
                                window. Rooms, unsettled charges, and the corp rate discount all
                                land as separate lines on one invoice.
                            </p>
                        </div>
                        <button type="button" wire:click="closeConsolidatedForm"
                                class="lp-btn" style="font-size: 12px;"
                                aria-label="Close panel">Close</button>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">From (checkout on/after)</label>
                                <input type="date" wire:model="consolidatedFrom"
                                       data-testid="consolidated-from" class="lp-input" style="font-size: 13px;">
                            </div>
                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">To (checkout on/before)</label>
                                <input type="date" wire:model="consolidatedTo"
                                       data-testid="consolidated-to" class="lp-input" style="font-size: 13px;">
                            </div>
                            <div class="md:col-span-2">
                                <label class="lp-form-label" style="font-size: 10.5px;">
                                    Notes <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                </label>
                                <input type="text" wire:model="consolidatedNotes"
                                       placeholder="e.g. September stays for Acme"
                                       data-testid="consolidated-notes" class="lp-input" style="font-size: 13px;">
                            </div>
                        </div>
                    </div>
                    <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" wire:click="closeConsolidatedForm"
                                class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                        <button type="button" wire:click="issueConsolidatedInvoice"
                                data-testid="consolidated-submit"
                                class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                            <span wire:loading.remove wire:target="issueConsolidatedInvoice">Generate + issue</span>
                            <span wire:loading wire:target="issueConsolidatedInvoice">Generating...</span>
                        </button>
                    </div>
                </aside>
            </div>
            </template>
        @endif

        @if ($paymentFormOpen)
            <template x-teleport="body">
            <div wire:key="corp-payment-panel-root" class="lp-page">
                <div x-transition.opacity.duration.150ms
                     wire:click="closePaymentForm"
                     style="position: fixed; inset: 0; z-index: 199;
                            background: rgba(20, 17, 12, 0.28);"></div>
                <aside data-testid="payment-form"
                       role="dialog" aria-modal="true"
                       x-transition:enter="transition ease-out duration-200"
                       x-transition:enter-start="translate-x-full"
                       x-transition:enter-end="translate-x-0"
                       x-on:keydown.escape.window="$wire.closePaymentForm()"
                       style="position: fixed; top: 0; right: 0; bottom: 0;
                              width: min(520px, 100vw); z-index: 200;
                              background: var(--color-card);
                              border-left: 1px solid var(--color-line);
                              box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                              display: flex; flex-direction: column;">
                    <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                        <div>
                            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                               style="color: var(--color-ink-mute);">
                                Corporate
                            </p>
                            @php
                                $targetInv = $paymentTargetInvoiceId
                                    ? $ledgerInvoices->firstWhere('id', $paymentTargetInvoiceId)
                                    : null;
                            @endphp
                            <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                @if ($targetInv)
                                    Pay {{ $targetInv->number ?? 'invoice' }}
                                @else
                                    Record payment
                                @endif
                            </h2>
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                @if ($targetInv)
                                    Settles this invoice first. Anything over its balance falls
                                    to the next oldest unpaid invoice, then parks as unallocated
                                    credit for the next invoice.
                                @else
                                    Auto-offsets the oldest unpaid invoices first. Anything over the
                                    outstanding total parks as unallocated credit for the next invoice.
                                @endif
                            </p>
                        </div>
                        <button type="button" wire:click="closePaymentForm"
                                class="lp-btn" style="font-size: 12px;"
                                aria-label="Close panel">Close</button>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                        @php
                            // Whichever balance the drawer is anchored to:
                            // a picked invoice, or the whole ledger.
                            $anchorLabel = $targetInv ? 'Invoice balance' : 'Total owed';
                            $anchorAmount = $targetInv
                                ? max(0, (int) $targetInv->total - (int) $targetInv->amount_paid)
                                : (int) $ledgerOutstanding;
                            $partAmount = max(0, $anchorAmount - (int) $paymentAmount);
                            $isPartial = (int) $paymentAmount > 0
                                && (int) $paymentAmount < $anchorAmount;
                            $isOver = (int) $paymentAmount > $anchorAmount;
                        @endphp
                        @if ($anchorAmount > 0)
                            <div style="padding: 10px 14px; margin-bottom: 14px;
                                        background: rgba(180, 83, 9, 0.05);
                                        border: 1px solid rgba(180, 83, 9, 0.2);
                                        border-radius: 10px;
                                        display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                                <div>
                                    <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                                         style="color: #a36318;">
                                        {{ $anchorLabel }}
                                    </div>
                                    <div class="lp-num" data-testid="pay-owed"
                                         style="font-size: 18px; font-weight: 600; color: #a36318; margin-top: 2px;">
                                        {{ money($anchorAmount) }}
                                    </div>
                                </div>
                                @if ((int) $paymentAmount !== $anchorAmount)
                                    <button type="button"
                                            wire:click="$set('paymentAmount', {{ $anchorAmount }})"
                                            data-testid="pay-fill-full"
                                            class="lp-btn" style="font-size: 12px;">
                                        Full balance
                                    </button>
                                @endif
                            </div>
                            @if ($isPartial)
                                <div data-testid="pay-part-hint"
                                     style="padding: 8px 12px; margin-bottom: 14px;
                                            background: var(--color-card-deep);
                                            border-radius: 8px; font-size: 12.5px;
                                            color: var(--color-ink-soft);">
                                    Part payment. This leaves
                                    <strong style="color: #a36318;">{{ money($partAmount) }}</strong>
                                    owed on {{ $targetInv ? 'this invoice' : 'the ledger' }} after settlement.
                                    The invoice stays Issued until the balance clears.
                                </div>
                            @elseif ($isOver)
                                <div data-testid="pay-over-hint"
                                     style="padding: 8px 12px; margin-bottom: 14px;
                                            background: rgba(122, 168, 116, 0.08);
                                            border: 1px solid rgba(122, 168, 116, 0.25);
                                            border-radius: 8px; font-size: 12.5px;
                                            color: var(--color-ink);">
                                    Overpayment of
                                    <strong>{{ money($paymentAmount - $anchorAmount) }}</strong>
                                    {{ $targetInv
                                        ? 'cascades to the next oldest unpaid invoice, then parks as credit.'
                                        : 'parks as unallocated credit for the next invoice.' }}
                                </div>
                            @endif
                        @endif
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">Amount ({{ currency_symbol() }})</label>
                                <input type="number" min="1" step="1" wire:model="paymentAmount"
                                       data-testid="pay-amount" class="lp-input" style="font-size: 13px;">
                            </div>
                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">Method</label>
                                <select wire:model="paymentMethod" data-testid="pay-method"
                                        class="lp-input" style="font-size: 13px;">
                                    <option value="transfer">Bank transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="pos">POS terminal</option>
                                    <option value="wallet">Wallet ({{ money($walletAvailable) }} available)</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">Paid on</label>
                                <input type="date" wire:model="paymentPaidAt"
                                       data-testid="pay-paid-at" class="lp-input" style="font-size: 13px;">
                            </div>
                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">
                                    Reference <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                </label>
                                <input type="text" wire:model="paymentReference"
                                       placeholder="e.g. GTB txn #948320"
                                       data-testid="pay-reference" class="lp-input" style="font-size: 13px;">
                            </div>
                            <div class="md:col-span-2">
                                <label class="lp-form-label" style="font-size: 10.5px;">
                                    Note <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                </label>
                                <input type="text" wire:model="paymentNote"
                                       data-testid="pay-note" class="lp-input" style="font-size: 13px;">
                            </div>
                            <div class="md:col-span-2">
                                <label class="lp-form-label" style="font-size: 10.5px;">
                                    Receipt <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                </label>
                                <input type="file" wire:model="paymentReceipt" accept="image/*,.pdf"
                                       data-testid="pay-receipt" class="lp-input" style="font-size: 12.5px;">
                                @if ($paymentReceipt)
                                    <p class="text-[11px] mt-1" style="color: var(--color-ink-soft);">
                                        Attached: {{ $paymentReceipt->getClientOriginalName() }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" wire:click="closePaymentForm"
                                class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                        <button type="button" wire:click="recordPayment"
                                data-testid="pay-save"
                                class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                            <span wire:loading.remove wire:target="recordPayment">Record payment</span>
                            <span wire:loading wire:target="recordPayment">Recording...</span>
                        </button>
                    </div>
                </aside>
            </div>
            </template>
        @endif

        {{-- ── Top-up wallet drawer ────────────────────────────
             Two sources: Paystack (hands back a hosted URL) and
             manual (operator confirms off-platform receipt and the
             wallet credits immediately). Same right-drawer pattern
             as the Record payment form. --}}
        @if ($topupFormOpen)
            <template x-teleport="body">
            <div wire:key="corp-topup-panel-root" class="lp-page">
                <div x-transition.opacity.duration.150ms
                     wire:click="closeTopupForm"
                     style="position: fixed; inset: 0; z-index: 199;
                            background: rgba(20, 17, 12, 0.28);"></div>
                <aside data-testid="topup-form"
                       role="dialog" aria-modal="true"
                       x-transition:enter="transition ease-out duration-200"
                       x-transition:enter-start="translate-x-full"
                       x-transition:enter-end="translate-x-0"
                       x-on:keydown.escape.window="$wire.closeTopupForm()"
                       style="position: fixed; top: 0; right: 0; bottom: 0;
                              width: min(520px, 100vw); z-index: 200;
                              background: var(--color-card);
                              border-left: 1px solid var(--color-line);
                              box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                              display: flex; flex-direction: column;">
                    <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                                display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                        <div>
                            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                                Corporate wallet
                            </p>
                            <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                                Top up
                            </h2>
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                Current balance: <strong style="color: var(--color-ink);">{{ money($walletAvailable) }}</strong>
                                available @if ($walletHeld > 0)· {{ money($walletHeld) }} held on open bookings @endif.
                            </p>
                        </div>
                        <button type="button" wire:click="closeTopupForm"
                                class="lp-btn" style="font-size: 12px;" aria-label="Close panel">Close</button>
                    </div>

                    <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="lp-form-label" style="font-size: 10.5px;">Source</label>
                                <div style="display: grid; gap: 8px;">
                                    <label class="flex items-start gap-3"
                                           style="padding: 12px 14px; border: 1px solid var(--color-line);
                                                  border-radius: 10px; cursor: pointer;
                                                  {{ $topupSource === 'paystack' ? 'background: rgba(122, 168, 116, 0.05); border-color: rgba(122, 168, 116, 0.35);' : '' }}">
                                        <input type="radio" wire:model.live="topupSource" value="paystack"
                                               data-testid="topup-source-paystack" style="margin-top: 3px;">
                                        <span>
                                            <span style="font-weight: 500; color: var(--color-ink);">Paystack (card / transfer)</span>
                                            <span style="display: block; font-size: 12px; color: var(--color-ink-mute); margin-top: 2px;">
                                                We generate a payment link. Wallet credits when the corp pays.
                                            </span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3"
                                           style="padding: 12px 14px; border: 1px solid var(--color-line);
                                                  border-radius: 10px; cursor: pointer;
                                                  {{ $topupSource === 'manual' ? 'background: rgba(122, 168, 116, 0.05); border-color: rgba(122, 168, 116, 0.35);' : '' }}">
                                        <input type="radio" wire:model.live="topupSource" value="manual"
                                               data-testid="topup-source-manual" style="margin-top: 3px;">
                                        <span>
                                            <span style="font-weight: 500; color: var(--color-ink);">Manual (cash / bank transfer received)</span>
                                            <span style="display: block; font-size: 12px; color: var(--color-ink-mute); margin-top: 2px;">
                                                Confirm you received the funds off-platform. Wallet credits immediately.
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <label class="lp-form-label" style="font-size: 10.5px;">Amount ({{ currency_symbol() }})</label>
                                <input type="number" min="1" step="1" wire:model="topupAmount"
                                       data-testid="topup-amount" class="lp-input" style="font-size: 13px;">
                            </div>

                            @if ($topupSource === 'manual')
                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">Method</label>
                                    <select wire:model="topupMethod" data-testid="topup-method"
                                            class="lp-input" style="font-size: 13px;">
                                        <option value="transfer">Bank transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="pos">POS terminal</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Reference <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                    </label>
                                    <input type="text" wire:model="topupReference"
                                           placeholder="e.g. GTB txn #948320"
                                           data-testid="topup-reference" class="lp-input" style="font-size: 13px;">
                                </div>
                            @endif

                            <div class="md:col-span-2">
                                <label class="lp-form-label" style="font-size: 10.5px;">
                                    Note <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                </label>
                                <input type="text" wire:model="topupNote" data-testid="topup-note"
                                       class="lp-input" style="font-size: 13px;">
                            </div>
                        </div>
                    </div>

                    <div style="padding: 14px 22px; border-top: 1px solid var(--color-line-soft);
                                display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" wire:click="closeTopupForm"
                                class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                        <button type="button" wire:click="recordTopup"
                                data-testid="topup-save"
                                class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                            <span wire:loading.remove wire:target="recordTopup">
                                @if ($topupSource === 'paystack')
                                    Generate Paystack link
                                @else
                                    Credit wallet
                                @endif
                            </span>
                            <span wire:loading wire:target="recordTopup">Working...</span>
                        </button>
                    </div>
                </aside>
            </div>
            </template>
        @endif

        {{-- Uninvoiced stays — corp bookings with balance owed that
             don't have an Issued or Paid invoice yet. These would
             otherwise disappear from view after checkout even though
             the corp still owes for them. --}}
        @if ($uninvoicedStays->isNotEmpty())
            <div style="padding: 16px 24px; border-bottom: 1px solid var(--color-line-soft);
                        background: rgba(180, 83, 9, 0.04);"
                 data-testid="uninvoiced-stays">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <div>
                        <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                             style="color: #B45309;">
                            Ready to invoice
                        </div>
                        <div style="font-size: 12.5px; color: var(--color-ink-soft); margin-top: 3px;">
                            {{ $uninvoicedStays->count() }} stay{{ $uninvoicedStays->count() === 1 ? '' : 's' }}
                            with balance owed, total
                            {{ money($uninvoicedOutstanding) }}
                        </div>
                    </div>
                    <button type="button" wire:click="openConsolidatedForm"
                            data-testid="uninvoiced-generate"
                            class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                        Generate invoice
                    </button>
                </div>
                <div style="margin-top: 12px; display: grid; gap: 6px;">
                    @foreach ($uninvoicedStays as $stay)
                        <div style="padding: 8px 12px; border: 1px solid var(--color-line-soft);
                                    border-radius: 8px; background: var(--color-card);
                                    display: flex; align-items: baseline; justify-content: space-between; gap: 12px;"
                             data-testid="uninvoiced-row-{{ $stay->id }}">
                            <div style="min-width: 0; flex: 1;">
                                <a href="{{ route('tenant.reservations.show', $stay->booking_ref) }}"
                                   wire:navigate
                                   style="color: var(--color-ink); font-weight: 500; font-size: 13px;
                                          text-decoration: none;">
                                    {{ $stay->guest_name }}
                                </a>
                                <div class="text-[11px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                    <span class="lp-mono">{{ $stay->booking_ref }}</span>
                                    @if ($stay->room?->room_number)
                                        <span style="margin: 0 4px;">·</span>
                                        Rm {{ $stay->room->room_number }}
                                    @endif
                                    <span style="margin: 0 4px;">·</span>
                                    {{ $stay->checkin_date?->format('j M') }} → {{ $stay->checkout_date?->format('j M') }}
                                    <span style="margin: 0 4px;">·</span>
                                    <span style="color: {{ $stay->booking_status === \App\Enums\BookingStatus::CheckedOut ? 'var(--color-ink-soft)' : '#B45309' }};">
                                        {{ $stay->booking_status->label() }}
                                    </span>
                                </div>
                            </div>
                            <div class="lp-num" style="color: #B45309; font-size: 14px; font-weight: 600;">
                                {{ money($stay->balance_due) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($ledgerInvoices->isEmpty() && $uninvoicedStays->isEmpty())
            <p class="text-[13.5px] text-center" style="padding: 40px 20px; color: var(--color-ink-mute);"
               data-testid="no-ledger-invoices">
                No invoices raised against this corp yet.
            </p>
        @elseif ($ledgerInvoices->isEmpty())
            {{-- Uninvoiced-stays block above still applies. --}}
        @else
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="ledger-invoices-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Stay</th>
                            <th>Issued</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th style="text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledgerInvoices as $inv)
                            @php
                                $bal = max(0, (int) $inv->total - (int) $inv->amount_paid);
                            @endphp
                            <tr data-testid="ledger-invoice-{{ $inv->id }}">
                                <td style="font-weight: 500;">
                                    <a href="{{ route('tenant.invoices.show', ['invoice' => $inv->id]) }}"
                                       wire:navigate
                                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                        {{ $inv->number ?? 'Draft #'.$inv->id }}
                                    </a>
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    @if ($inv->booking)
                                        {{ $inv->booking->guest_name }}
                                        <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute);">
                                            {{ $inv->booking->booking_ref }}
                                        </div>
                                    @else
                                        <span style="color: var(--color-ink-mute);">-</span>
                                    @endif
                                </td>
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">
                                    {{ $inv->issued_at?->toFormattedDateString() ?? 'Draft' }}
                                </td>
                                <td class="lp-num">{{ money($inv->total) }}</td>
                                <td class="lp-num" style="color: var(--color-accent-deep);">
                                    {{ money($inv->amount_paid) }}
                                </td>
                                <td class="lp-num" style="color: {{ $bal > 0 ? '#a36318' : 'var(--color-ink)' }}; font-weight: 500;">
                                    {{ money($bal) }}
                                </td>
                                <td>
                                    @switch($inv->status->value)
                                        @case('paid')
                                            <span class="lp-tag lp-tag--confirmed">Paid</span>
                                            @break
                                        @case('partial')
                                            <span class="lp-tag lp-tag--on-hold" style="background: rgba(180, 83, 9, 0.12); color: #8b4d0f;">Partial</span>
                                            @break
                                        @case('issued')
                                            <span class="lp-tag lp-tag--on-hold">Issued</span>
                                            @break
                                        @case('void')
                                            <span class="lp-tag lp-tag--checked-out">Void</span>
                                            @break
                                        @default
                                            <span class="lp-tag lp-tag--pending">Draft</span>
                                    @endswitch
                                </td>
                                <td style="text-align: right;">
                                    @if (in_array($inv->status, [\App\Enums\InvoiceStatus::Issued, \App\Enums\InvoiceStatus::Partial], true) && $bal > 0)
                                        <button type="button"
                                                wire:click="openPaymentForInvoice({{ $inv->id }})"
                                                data-testid="pay-invoice-{{ $inv->id }}"
                                                class="lp-btn lp-btn--primary" style="font-size: 12px;">
                                            Pay
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Statement — chronological view of every AR event with a
             running balance. Debits = invoices issued, credits =
             payments received. Balance column tracks what the corp
             owed AFTER each event. Newest at top so today's activity
             is the first thing an operator sees. --}}
        @if ($statementEvents->isNotEmpty())
            <div style="padding: 16px 24px; border-top: 1px solid var(--color-line-soft);"
                 data-testid="statement-section">
                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px;">
                    <div>
                        <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                             style="color: var(--color-ink-mute);">
                            Statement
                        </div>
                        <div class="text-[11.5px] mt-1" style="color: var(--color-ink-soft);">
                            Every invoice + payment against this corp, newest first,
                            with balance owed after each event.
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute);">
                            Current balance
                        </div>
                        <div class="lp-num" data-testid="statement-current-balance"
                             style="font-size: 15px; font-weight: 600;
                                    color: {{ $statementEvents->first()->balance_after > 0 ? '#a36318' : 'var(--color-ink)' }};">
                            {{ money($statementEvents->first()->balance_after) }}
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto" style="margin-top: 12px;">
                    <table class="lp-table" data-testid="statement-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th style="text-align: right;">Charge</th>
                                <th style="text-align: right;">Payment</th>
                                <th style="text-align: right;">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statementEvents as $ev)
                                <tr data-testid="statement-row-{{ $ev->kind }}-{{ $ev->record->id }}">
                                    <td class="text-[12px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                                        {{ $ev->when?->toFormattedDateString() ?? '-' }}
                                    </td>
                                    <td>
                                        @if ($ev->kind === 'invoice')
                                            <span class="lp-tag lp-tag--on-hold" style="font-size: 10px;">Invoice</span>
                                        @else
                                            <span class="lp-tag lp-tag--confirmed" style="font-size: 10px;">Payment</span>
                                        @endif
                                    </td>
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        @if ($ev->kind === 'invoice')
                                            <a href="{{ route('tenant.invoices.show', ['invoice' => $ev->invoice_id]) }}"
                                               wire:navigate
                                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                                {{ $ev->reference }}
                                            </a>
                                        @else
                                            <span class="lp-mono">{{ $ev->reference }}</span>
                                        @endif
                                        <div class="text-[11px] mt-0.5" style="color: var(--color-ink-mute);">
                                            {{ $ev->note }}
                                        </div>
                                    </td>
                                    <td class="lp-num" style="text-align: right; color: {{ $ev->debit > 0 ? '#a36318' : 'var(--color-ink-mute)' }};">
                                        @if ($ev->debit > 0)
                                            {{ money($ev->debit) }}
                                        @else
                                            &middot;
                                        @endif
                                    </td>
                                    <td class="lp-num" style="text-align: right; color: {{ $ev->credit > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                                        @if ($ev->credit > 0)
                                            {{ money($ev->credit) }}
                                        @else
                                            &middot;
                                        @endif
                                    </td>
                                    <td class="lp-num" style="text-align: right; font-weight: 500;
                                                              color: {{ (int) $ev->balance_after > 0 ? '#a36318' : 'var(--color-ink)' }};">
                                        {{ money($ev->balance_after) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── Wallet transactions (last 20) ─────────────────────
             Cream side-strip listing top-ups, holds, spends, refunds,
             adjustments. Each row shows the type, description, signed
             amount, and running balance-after so operators can trace
             ledger drift back to a specific action. --}}
        @if ($isOwned && $walletTx->isNotEmpty())
            <div style="padding: 16px 24px; border-top: 1px solid var(--color-line-soft);"
                 data-testid="wallet-transactions">
                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px;">
                    <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                         style="color: var(--color-ink-mute);">
                        Wallet activity
                    </div>
                    <button type="button" wire:click="openTopupForm"
                            data-testid="wallet-topup-inline"
                            class="lp-btn" style="font-size: 12px;">Top up</button>
                </div>
                <div style="margin-top: 10px; display: grid; gap: 6px;">
                    @foreach ($walletTx as $tx)
                        @php
                            $isCredit = (int) $tx->amount_naira > 0;
                            $label = match ($tx->type) {
                                'topup'      => 'Top up',
                                'hold'       => 'Held',
                                'release'    => 'Released',
                                'spend'      => 'Spent',
                                'refund'     => 'Refund',
                                'adjustment' => 'Adjustment',
                                default      => ucfirst((string) $tx->type),
                            };
                        @endphp
                        <div style="display: flex; align-items: baseline; justify-content: space-between;
                                    gap: 12px; padding: 10px 12px;
                                    background: var(--color-card); border: 1px solid var(--color-line-soft);
                                    border-radius: 8px;">
                            <div style="min-width: 0; flex: 1;">
                                <div style="color: var(--color-ink); font-size: 12.5px;">
                                    <strong>{{ $label }}</strong>
                                    @if ($tx->description)
                                        <span style="color: var(--color-ink-soft); margin-left: 6px;">
                                            {{ $tx->description }}
                                        </span>
                                    @endif
                                </div>
                                <div class="lp-mono text-[10.5px] mt-0.5" style="color: var(--color-ink-mute);">
                                    {{ $tx->created_at?->format('j M · g:i A') }}
                                    · balance after {{ money((int) $tx->balance_after_naira) }}
                                </div>
                            </div>
                            <div class="lp-num" style="font-weight: 600; font-size: 13.5px;
                                                       color: {{ $isCredit ? '#3b6a35' : '#a36318' }}; white-space: nowrap;">
                                {{ $isCredit ? '+' : '−' }}{{ money(abs((int) $tx->amount_naira)) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Payment history — most recent first. Each row shows how
             one payment was split across invoices for the audit trail. --}}
        @if ($ledgerPayments->isNotEmpty())
            <div style="padding: 16px 24px; border-top: 1px solid var(--color-line-soft);">
                <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                     style="color: var(--color-ink-mute);">
                    Payments received
                </div>
                <div style="margin-top: 10px; display: grid; gap: 8px;">
                    @foreach ($ledgerPayments as $pay)
                        @php
                            $allocated = (int) $pay->allocations->sum('amount_naira');
                            $unallocated = max(0, (int) $pay->amount_naira - $allocated);
                        @endphp
                        <div data-testid="payment-row-{{ $pay->id }}"
                             style="padding: 10px 12px; border: 1px solid var(--color-line-soft);
                                    border-radius: 8px; display: flex; align-items: start;
                                    justify-content: space-between; gap: 12px;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="color: var(--color-ink); font-weight: 500;">
                                    {{ money($pay->amount_naira) }}
                                    <span style="color: var(--color-ink-mute); font-weight: 400; font-size: 11.5px;">
                                        · {{ ucfirst($pay->method) }} · {{ $pay->paid_at?->toFormattedDateString() }}
                                    </span>
                                </div>
                                @if ($pay->reference)
                                    <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                        {{ $pay->reference }}
                                    </div>
                                @endif
                                @if ($pay->note)
                                    <div class="text-[11.5px]" style="color: var(--color-ink-soft);">
                                        {{ $pay->note }}
                                    </div>
                                @endif
                                @if ($pay->allocations->isNotEmpty())
                                    <div class="text-[11px]" style="color: var(--color-ink-mute); margin-top: 4px;">
                                        Applied to
                                        @foreach ($pay->allocations as $al)
                                            {{ $al->invoice?->number ?? 'Draft' }} ({{ money($al->amount_naira) }})@if (! $loop->last), @endif
                                        @endforeach
                                    </div>
                                @endif
                                @if ($unallocated > 0)
                                    <div class="text-[11.5px]" style="color: var(--color-accent-deep); margin-top: 4px;">
                                        Credit balance {{ money($unallocated) }}, will offset the next invoice.
                                    </div>
                                @endif
                                @if ($pay->receipt_path)
                                    <div class="text-[11px] mt-1">
                                        <a href="{{ \Illuminate\Support\Facades\Storage::url($pay->receipt_path) }}"
                                           target="_blank" rel="noopener"
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                            View receipt
                                        </a>
                                    </div>
                                @endif
                            </div>
                            <div class="text-[11px]" style="color: var(--color-ink-mute); text-align: right;">
                                by {{ $pay->recordedBy?->name ?? '-' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    {{-- ── Booking history ───────────────────────────────────────── --}}
    @if ($rows->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                No bookings yet.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;" data-testid="bookings-section">
            <div class="lp-dash-card-head" style="padding: 20px 24px;">
                <span class="lp-dash-card-title">Booking history at your property</span>
                <span class="lp-dash-card-meta">Latest first</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="bookings-table">
                    <thead>
                        <tr>
                            <th>Kind</th>
                            <th>Ref</th>
                            <th>When</th>
                            <th>What</th>
                            <th>Member</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><span class="lp-tag lp-tag--checked-in">{{ $row->kind }}</span></td>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px;">{{ $row->ref }}</td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px; white-space: nowrap;">
                                    {{ $row->when?->format('d M Y') }}
                                </td>
                                <td>
                                    <div style="color: var(--color-ink); font-weight: 500;">{{ $row->subject }}</div>
                                    @if ($row->detail)
                                        <div class="text-[11.5px] mt-0.5" style="color: var(--color-ink-mute);">{{ $row->detail }}</div>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $row->member ?? '-' }}</td>
                                <td><span class="lp-table-amount">{{ money($row->amount) }}</span></td>
                                <td>
                                    <span class="lp-tag lp-tag--checked-out">{{ str_replace('_', ' ', $row->status) }}</span>
                                </td>
                                <td style="text-align: right;">
                                    @if ($row->route)
                                        <a href="{{ $row->route }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px; font-size: 12.5px;">
                                            Open
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
