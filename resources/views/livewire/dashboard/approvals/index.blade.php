<div class="lp-dash">
    {{-- ── Hero: title + status tab-strip ────────────────────
         Same shape as guest requests — segmented control on the
         right so managers see BOTH "where they are" + "what's
         showing" at load. --}}
    <section class="lp-res-idx-hero" data-testid="ap-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Approvals</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Portal action approvals
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    Guests whose device coordinates scored below your fence threshold have their portal taps held here. Ring the guest to confirm intent, then approve to run the action or reject to leave the booking untouched.
                </p>
            </div>
            <div class="lp-gr-hero-side">
                <div class="lp-tabstrip" data-testid="ap-status-filter">
                    <button type="button" wire:click="$set('statusFilter', 'pending')"
                            class="lp-tabstrip-btn @if ($statusFilter === 'pending') is-active @endif">
                        Pending
                        @if (! empty($counts['pending']))
                            <span class="lp-tabstrip-count">{{ $counts['pending'] }}</span>
                        @endif
                    </button>
                    <button type="button" wire:click="$set('statusFilter', 'approved')"
                            class="lp-tabstrip-btn @if ($statusFilter === 'approved') is-active @endif">Approved</button>
                    <button type="button" wire:click="$set('statusFilter', 'rejected')"
                            class="lp-tabstrip-btn @if ($statusFilter === 'rejected') is-active @endif">Rejected</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Card-stack list ─────────────────────────────────── --}}
    @if ($items->isEmpty())
        <section class="lp-dash-card" data-testid="approvals-empty">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'sparkle',
                'title' => 'Nothing to review right now.',
                'sub'   => "Either every guest tap scored above your fence threshold, or you haven't set a geo-fence yet.",
            ])
        </section>
    @else
        <section class="lp-dash-card" data-testid="approvals-panel" style="padding: 14px 16px;">
            <ul class="lp-ledger lp-gr-list" data-testid="approvals-table">
                @foreach ($items as $a)
                    @php
                        $isPending = $a->status === 'pending';
                        $isApproved = $a->status === 'approved';
                        $markerTone = $isPending
                            ? 'warn'
                            : ($isApproved ? 'paid' : 'info');
                        $kindLabel = ucfirst(str_replace('_', ' ', (string) $a->kind));
                    @endphp
                    <li class="lp-ledger-item lp-gr-item"
                        data-testid="ap-row-{{ $a->id }}"
                        role="button" tabindex="0"
                        wire:click="open({{ $a->id }})"
                        x-on:keydown.enter.prevent="$wire.open({{ $a->id }})"
                        style="grid-template-columns: auto minmax(0, 1fr) auto;">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $markerTone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-tag lp-tag--pending" style="font-size: 10px;">{{ $kindLabel }}</span>
                                <span class="lp-ledger-desc" style="margin-left: 4px;">
                                    {{ $a->booking?->guest_name ?? '(no guest)' }}
                                </span>
                                <span class="lp-mono lp-gr-ref">
                                    @if ($a->booking?->booking_ref) {{ $a->booking->booking_ref }} @endif
                                    @if ($a->room?->room_number) · Rm {{ $a->room->room_number }} @endif
                                </span>
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $a->created_at?->diffForHumans() }}
                                @if ($a->booking?->guest_phone)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $a->booking->guest_phone }}
                                @endif
                                @if ($a->distance_meters !== null)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ number_format($a->distance_meters) }} m from property
                                @endif
                                <span style="color: var(--color-ink-mute);">·</span>
                                score <span class="lp-mono">{{ $a->coord_score }}</span>
                                @if (! $isPending && $a->approvedBy)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $a->status }} by {{ $a->approvedBy->name }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-gr-actions" wire:click.stop>
                            @if ($isPending)
                                <span class="lp-tag lp-tag--on-hold">Pending</span>
                                <button type="button" wire:click="approve({{ $a->id }})"
                                        class="lp-ledger-action">
                                    Approve
                                </button>
                                <button type="button" wire:click="open({{ $a->id }})"
                                        class="lp-ledger-action">
                                    Review
                                </button>
                            @elseif ($isApproved)
                                <span class="lp-tag lp-tag--confirmed">Approved</span>
                            @else
                                <span class="lp-tag lp-tag--cancelled">Rejected</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($selected !== null)
        @php
            // Kind → human title + summary blurb for the drawer head.
            $kindMeta = match ($selected->kind) {
                'checkin_no_payment' => [
                    'label'   => 'Check-in without payment',
                    'summary' => 'Front-desk asked to check the guest in without collecting the folio. Approving flips the booking to Confirmed with the total on the balance, notifies the front desk, and unlocks the room. Housekeeping still has to sign off; the desk then runs the standard Check in.',
                ],
                'extend', 'extend_card' => [
                    'label'   => 'Extend stay',
                    'summary' => 'Guest wants more nights than they booked. Payment path depends on whether they used the card or expect wallet coverage.',
                ],
                'checkout' => [
                    'label'   => 'Guest self-checkout',
                    'summary' => 'Guest tapped Check out from the room portal outside the property fence. Confirm intent before releasing the room.',
                ],
                'order_room', 'order_card' => [
                    'label'   => 'Room-service order',
                    'summary' => 'Order raised from the portal outside the property fence. Confirm the guest actually placed it.',
                ],
                'reserve' => [
                    'label'   => 'Additional reservation',
                    'summary' => 'Guest booked another stay from the portal outside the property fence. Confirm before creating the second booking.',
                ],
                default => [
                    'label'   => ucfirst(str_replace('_', ' ', (string) $selected->kind)),
                    'summary' => 'Portal action held for review because the tap came from outside your geo-fence.',
                ],
            };
            $payload = $selected->payload ?? [];

            // Currency-y keys (naira) — render with ₦ and thousands
            // separators instead of the raw integer.
            $moneyKeys = [
                'total_amount', 'amount', 'balance_due', 'deposit_amount',
                'room_total', 'extension_total', 'nightly_rate', 'card_amount',
                'wallet_amount', 'topup_amount',
            ];
            $fmtValue = function ($k, $v) use ($moneyKeys) {
                if ($v === null || $v === '') return '-';
                if (is_bool($v)) return $v ? 'Yes' : 'No';
                if (is_array($v)) return null;   // handled separately
                if (in_array($k, $moneyKeys, true) && is_numeric($v)) {
                    return '₦'.number_format((int) $v);
                }
                if (is_numeric($v) && str_contains($k, 'date')) {
                    return \Illuminate\Support\Carbon::parse((string) $v)->toFormattedDateString();
                }
                return (string) $v;
            };
            $fmtLabel = fn (string $k) => \Illuminate\Support\Str::of($k)
                ->replace('_', ' ')
                ->replace('id', 'ID')
                ->title()
                ->__toString();

            // Payload keys we render inline in a highlight strip (the
            // interesting bits for the manager). The rest fall into
            // the "Everything else" list below.
            $primaryKeys = ['total_amount', 'amount', 'nights', 'checkout_date',
                            'guest_name', 'requested_by_operator_name', 'card_amount',
                            'wallet_amount', 'balance_due'];
            $primary = array_filter(
                $primaryKeys,
                fn ($k) => array_key_exists($k, $payload) && $payload[$k] !== null && $payload[$k] !== ''
            );
        @endphp
        <template x-teleport="body">
        <div wire:key="approval-panel-root" class="lp-page">
            <div x-transition.opacity.duration.150ms
                 wire:click="close"
                 data-testid="ap-panel"
                 style="position: fixed; inset: 0; z-index: 199;
                        background: rgba(20, 17, 12, 0.32);"></div>
            <aside role="dialog" aria-label="Approval detail"
                   x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="translate-x-full"
                   x-transition:enter-end="translate-x-0"
                   x-on:keydown.escape.window="$wire.close()"
                   style="position: fixed; top: 0; right: 0; bottom: 0;
                          width: min(560px, 100vw); z-index: 200;
                          background: var(--color-card);
                          border-left: 1px solid var(--color-line);
                          box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                          display: flex; flex-direction: column;">
                <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                            display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                    <div>
                        <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                           style="color: var(--color-ink-mute);">
                            Portal approval
                        </p>
                        <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                            {{ $kindMeta['label'] }}
                        </h2>
                        <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                            {{ $kindMeta['summary'] }}
                        </p>
                    </div>
                    <button type="button" wire:click="close"
                            class="lp-btn" style="font-size: 12px;"
                            aria-label="Close panel">Close</button>
                </div>

                <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">

                    {{-- Guest strip — who + where + when. --}}
                    <div style="padding: 14px 16px; background: var(--color-card-deep);
                                border-radius: 10px;">
                        <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px;">
                            <div style="min-width: 0; flex: 1;">
                                <div style="color: var(--color-ink); font-weight: 600; font-size: 15px;">
                                    {{ $selected->booking?->guest_name ?? 'Guest' }}
                                </div>
                                <div class="lp-mono text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                    Rm {{ $selected->room?->room_number ?? '?' }}
                                    @if ($selected->booking?->booking_ref)
                                        <span style="margin: 0 4px;">·</span>
                                        {{ $selected->booking->booking_ref }}
                                    @endif
                                </div>
                                <div class="text-[12px] mt-1" style="color: var(--color-ink-soft);">
                                    @if ($selected->booking?->guest_phone)
                                        <a href="tel:{{ $selected->booking->guest_phone }}"
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                            {{ $selected->booking->guest_phone }}
                                        </a>
                                    @else
                                        <span style="color: var(--color-ink-mute);">Phone not on file</span>
                                    @endif
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute);">
                                    Raised
                                </div>
                                <div class="text-[12.5px] mt-1" style="color: var(--color-ink);">
                                    {{ $selected->created_at?->diffForHumans(['parts' => 1]) ?? '-' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Geo signal — coord score + distance. Amber when
                         the guest is near enough that manager judgement
                         calls it. --}}
                    <div style="margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="padding: 12px 14px; background: var(--color-card-deep); border-radius: 10px;">
                            <div class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute);">
                                Coord score
                            </div>
                            <div class="lp-num" style="font-size: 18px; font-weight: 600; margin-top: 2px;
                                                       color: {{ (int) $selected->coord_score < 40 ? '#B85C42' : 'var(--color-ink)' }};">
                                {{ $selected->coord_score }} <span class="text-[11.5px]" style="color: var(--color-ink-mute); font-weight: 400;">/ 100</span>
                            </div>
                        </div>
                        <div style="padding: 12px 14px; background: var(--color-card-deep); border-radius: 10px;">
                            <div class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute);">
                                Distance from property
                            </div>
                            <div class="lp-num" style="font-size: 18px; font-weight: 600; margin-top: 2px; color: var(--color-ink);">
                                @if ($selected->distance_meters !== null)
                                    {{ number_format($selected->distance_meters) }}<span class="text-[11.5px]" style="color: var(--color-ink-mute); font-weight: 400;"> m</span>
                                @else
                                    <span class="text-[13px]" style="color: var(--color-ink-mute);">Not shared</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($selected->guest_latitude !== null)
                        <div style="margin-top: 8px;">
                            <a target="_blank" rel="noopener"
                               href="https://maps.google.com/?q={{ $selected->guest_latitude }},{{ $selected->guest_longitude }}"
                               class="lp-mono text-[11.5px]"
                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                {{ number_format((float) $selected->guest_latitude, 5) }},
                                {{ number_format((float) $selected->guest_longitude, 5) }} · Open in maps →
                            </a>
                        </div>
                    @endif

                    {{-- Request details — decoded from payload. Rendered
                         key-by-key as a two-column grid. Primary keys
                         (amount, guest, requester) get highlighted. --}}
                    @if (! empty($payload))
                        <div style="margin-top: 18px;">
                            <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                                 style="color: var(--color-ink-mute);">
                                Request details
                            </div>

                            @if (! empty($primary))
                                <div style="margin-top: 8px; display: grid; gap: 6px;">
                                    @foreach ($primary as $k)
                                        <div style="display: flex; justify-content: space-between; align-items: baseline;
                                                    padding: 10px 12px; background: rgba(180, 83, 9, 0.05);
                                                    border: 1px solid rgba(180, 83, 9, 0.15); border-radius: 8px;">
                                            <span class="text-[12px]" style="color: var(--color-ink-soft);">
                                                {{ $fmtLabel($k) }}
                                            </span>
                                            <span class="lp-num" style="color: var(--color-ink); font-weight: 500; font-size: 13px;">
                                                {{ $fmtValue($k, $payload[$k]) }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @php
                                $others = array_filter(
                                    $payload,
                                    fn ($v, $k) => ! in_array($k, $primary, true)
                                        && ! is_array($v) && $v !== null && $v !== ''
                                        && ! in_array($k, ['requested_by_operator_id'], true),
                                    ARRAY_FILTER_USE_BOTH,
                                );
                            @endphp
                            @if (! empty($others))
                                <dl style="margin-top: 10px; display: grid;
                                           grid-template-columns: 1fr 1fr; gap: 6px 16px;">
                                    @foreach ($others as $k => $v)
                                        <dt class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                            {{ $fmtLabel((string) $k) }}
                                        </dt>
                                        <dd class="text-[12.5px]" style="color: var(--color-ink); margin: 0;">
                                            {{ $fmtValue($k, $v) }}
                                        </dd>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    @endif

                    {{-- Manager actions. --}}
                    @if ($selected->status === 'pending')
                        <div x-data="{ showReject: false }" style="margin-top: 22px;">
                            <div class="flex gap-2">
                                <button type="button" wire:click="approve({{ $selected->id }})"
                                        class="lp-btn lp-btn--primary" data-testid="ap-approve"
                                        style="font-size: 12.5px;">
                                    <span wire:loading.remove wire:target="approve">Approve &amp; run</span>
                                    <span wire:loading wire:target="approve">Running...</span>
                                </button>
                                <button type="button" x-on:click="showReject = !showReject"
                                        class="lp-btn" style="font-size: 12.5px;">
                                    Reject with note
                                </button>
                            </div>
                            <div x-show="showReject" x-cloak style="margin-top: 12px;">
                                <label class="lp-form-label" style="font-size: 10.5px;">
                                    Reason (shown in the audit trail)
                                </label>
                                <textarea wire:model="rejectNote" rows="3" class="lp-input"
                                          placeholder="Called guest, didn't confirm the request."
                                          style="font-size: 13px;"></textarea>
                                <button type="button" wire:click="reject({{ $selected->id }})"
                                        class="lp-btn" data-testid="ap-reject"
                                        style="font-size: 12.5px; margin-top: 8px; color: #B85C42;">
                                    Confirm reject
                                </button>
                            </div>
                        </div>
                    @else
                        <div style="margin-top: 22px; padding: 12px 14px;
                                    background: var(--color-card-deep); border-radius: 10px;">
                            <div class="text-[12.5px]" style="color: var(--color-ink);">
                                <strong>{{ ucfirst($selected->status) }}</strong>
                                by {{ $selected->approvedBy?->name ?? '-' }}
                                <span style="color: var(--color-ink-mute);">
                                    · {{ $selected->resolved_at?->diffForHumans() ?? '' }}
                                </span>
                            </div>
                            @if ($selected->operator_note)
                                <div class="mt-2 text-[12.5px]" style="color: var(--color-ink-soft);">
                                    "{{ $selected->operator_note }}"
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </aside>
        </div>
        </template>
    @endif
</div>
