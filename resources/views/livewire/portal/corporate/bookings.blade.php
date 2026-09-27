<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Bookings</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Bookings across your team
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            @if ($isAdmin)
                Stays, desk sessions, memberships and leases attributed to your corporate.
            @else
                Your own bookings across the platform.
            @endif
        </p>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <div class="flex gap-2 mt-2" data-testid="type-picker">
            @foreach ([['', 'All'], ['stay', 'Stays'], ['desk', 'Desks'], ['membership', 'Memberships'], ['lease', 'Leases']] as [$value, $label])
                <button type="button"
                        wire:click="$set('typeFilter', '{{ $value }}')"
                        class="lp-tag"
                        data-testid="type-{{ $value ?: 'all' }}"
                        style="cursor: pointer; padding: 6px 12px;
                               {{ $typeFilter === $value
                                  ? 'background: var(--color-ink); color: var(--color-card); border-color: var(--color-ink);'
                                  : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </section>

    @if ($rows->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                Nothing here yet.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                Bookings show up when members book using their work email.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Kind</th>
                            <th>Ref</th>
                            <th>Property</th>
                            <th>What</th>
                            <th>Member</th>
                            <th>When</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <span class="lp-tag lp-tag--checked-in">{{ $row->kind }}</span>
                                </td>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px;">{{ $row->ref }}</td>
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $row->subject }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $row->detail }}</td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $row->member ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px; white-space: nowrap;">
                                    {{ $row->when?->format('d M Y') }}
                                </td>
                                <td><span class="lp-table-amount">₦{{ number_format($row->amount) }}</span></td>
                                <td>
                                    <span class="lp-tag lp-tag--checked-out">{{ str_replace('_', ' ', $row->status) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
