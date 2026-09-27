@php
    $money = fn (int $n) => '₦' . number_format($n);
    $statusClass = fn (string $s) => match ($s) {
        'draft'   => 'lp-tag--pending',
        'issued'  => 'lp-tag--on-hold',
        'partial' => 'lp-tag--on-hold',
        'paid'    => 'lp-tag--confirmed',
        'void'    => 'lp-tag--cancelled',
        default   => '',
    };
    $activeFilters = collect([
        $search        !== '' ? 'search'  : null,
        $statusFilter  !== '' ? 'status'  : null,
        $dateFrom      !== '' ? 'from'    : null,
        $dateTo        !== '' ? 'to'      : null,
    ])->filter()->count();
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + primary action ─────────────────────
         Editorial identity block over an outstanding-balance
         side panel. Matches the reservations index treatment. --}}
    <section class="lp-res-idx-hero" data-testid="invoices-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Invoices
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    {{ number_format($invoices->total()) }} {{ Str::plural('invoice', $invoices->total()) }} across every booking. Search, filter, download PDFs.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.bookings.index') }}"
                       class="lp-res-action lp-res-action--primary"
                       data-testid="new-invoice-from-booking">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New invoice from booking
                    </a>
                    @if ($activeFilters > 0)
                        <button type="button" wire:click="clearFilters"
                                class="lp-res-action lp-res-action--ghost"
                                data-testid="clear-filters">
                            Clear {{ $activeFilters }} {{ Str::plural('filter', $activeFilters) }}
                        </button>
                    @endif
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Invoice balances">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Outstanding balance</p>
                    <p class="lp-ih-hero-accrued-value {{ $outstanding > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                       data-testid="invoices-outstanding">
                        {{ $money($outstanding) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ $money($paidMtd) }} collected this month
                        @if ($overdueCount > 0)
                            · {{ $overdueCount }} {{ Str::plural('invoice', $overdueCount) }} over 14 days
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Filter strip ─────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 14px 16px;">
        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="inv-search" class="lp-form-label">Search</label>
                <input id="inv-search" type="search"
                       wire:model.live.debounce.400ms="search"
                       data-testid="invoices-search"
                       class="lp-input"
                       placeholder="Number, guest, email, or booking ref" />
            </div>

            <div>
                <label for="inv-status" class="lp-form-label">Status</label>
                <select id="inv-status" wire:model.live="statusFilter"
                        data-testid="invoices-status" class="lp-input">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->value }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="inv-from" class="lp-form-label">Issued from</label>
                    <input id="inv-from" type="date" wire:model.live="dateFrom"
                           data-testid="invoices-from" class="lp-input" />
                </div>
                <div>
                    <label for="inv-to" class="lp-form-label">Issued to</label>
                    <input id="inv-to" type="date" wire:model.live="dateTo"
                           data-testid="invoices-to" class="lp-input" />
                </div>
            </div>
        </form>
    </section>

    {{-- ── Invoice list (card stack) ──────────────────────── --}}
    <section class="lp-dash-card" data-testid="invoices-list" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 12px;">
            <span class="lp-dash-card-title">
                {{ $activeFilters > 0 ? 'Matching invoices' : 'Latest invoices' }}
            </span>
            <span class="lp-dash-card-meta">
                {{ $invoices->firstItem() ? number_format($invoices->firstItem()).'–'.number_format($invoices->lastItem()) : '0' }}
                of {{ number_format($invoices->total()) }}
            </span>
        </div>

        @if ($invoices->isEmpty())
            @include('livewire.dashboard._partials._empty', [
                'icon'    => 'wallet',
                'title'   => $activeFilters > 0 ? 'No invoices match those filters.' : "You haven't issued any invoices yet.",
                'sub'     => $activeFilters > 0
                                ? 'Widen the date range or clear filters to see everything.'
                                : 'Invoices are generated automatically from bookings, or you can start a manual one.',
                'ctaLabel' => $activeFilters > 0 ? 'Clear filters' : 'Open bookings',
                'ctaHref'  => $activeFilters > 0 ? null : route('tenant.bookings.index'),
                'ctaClickJs' => $activeFilters > 0 ? "\$wire.clearFilters()" : null,
            ])
        @else
            {{-- Dense table replaces the ledger card-stack — a busy
                 property with a full invoice register scans much
                 faster in a grid. Row-click via rowclick.js's global
                 data-row-href handler still opens the invoice show. --}}
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="invoices-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Guest</th>
                            <th>Booking</th>
                            <th>Issued</th>
                            <th>Status</th>
                            <th style="text-align: right;">Total</th>
                            <th style="width: 60px; text-align: right;">PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($invoices as $inv)
                        @php
                            $bal      = (int) $inv->balanceOwed();
                            $issuedOn = $inv->issued_at?->toFormattedDateString();
                            $isOverdue = in_array($inv->status->value, ['issued', 'partial'], true)
                                         && $inv->issued_at
                                         && $inv->issued_at->lt(now()->subDays(14));
                        @endphp
                        <tr class="lp-invoice-row @if ($isOverdue) lp-invoice-row--overdue @endif"
                            data-testid="invoice-row-{{ $inv->id }}"
                            data-row-href="{{ route('tenant.invoices.show', $inv->id) }}"
                            tabindex="0">
                            <td>
                                <a href="{{ route('tenant.invoices.show', $inv->id) }}"
                                   wire:navigate
                                   class="lp-mono"
                                   style="color: var(--color-ink); text-decoration: none; font-size: 12px;"
                                   aria-label="Open invoice {{ $inv->number ?? 'draft' }}">
                                    {{ $inv->number ?? 'Draft' }}
                                </a>
                            </td>
                            <td>
                                <p style="margin: 0; font-weight: 500; color: var(--color-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px;">
                                    {{ $inv->guest_name ?? 'Unnamed guest' }}
                                </p>
                            </td>
                            <td>
                                @if ($inv->booking?->booking_ref)
                                    <span class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px;">
                                        {{ $inv->booking->booking_ref }}
                                    </span>
                                @else
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="margin: 0; font-size: 12.5px; color: var(--color-ink);">
                                        {{ $issuedOn ?? 'Not yet issued' }}
                                    </p>
                                    @if ($isOverdue)
                                        <span class="lp-tag" style="font-size: 9.5px; padding: 1px 6px; margin-top: 3px; display: inline-block;
                                                                    background: rgba(163, 99, 24, 0.14); color: #a36318;
                                                                    border: 1px solid rgba(163, 99, 24, 0.28);">
                                            overdue
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="lp-tag {{ $statusClass($inv->status->value) }}">
                                    {{ $inv->status->value }}
                                </span>
                            </td>
                            <td class="lp-num" style="text-align: right; font-weight: 600;">
                                <p style="margin: 0;">{{ $money($inv->total) }}</p>
                                @if ($bal > 0)
                                    <p class="lp-mono" style="margin: 2px 0 0; font-size: 10.5px; color: #a36318; font-weight: 600;">
                                        {{ $money($bal) }} owing
                                    </p>
                                @else
                                    <p class="lp-mono" style="margin: 2px 0 0; font-size: 10.5px; color: var(--color-ink-mute); font-weight: 400;">
                                        settled
                                    </p>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <a href="{{ route('tenant.invoices.pdf', $inv) }}"
                                   class="lp-invoice-pdf"
                                   aria-label="Download {{ $inv->number ?? 'draft' }} as PDF"
                                   data-testid="invoice-pdf-{{ $inv->id }}"
                                   title="Download PDF"
                                   onclick="event.stopPropagation();">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 11v2.5h10V11M8 3v8M5 8l3 3 3-3"/></svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($invoices->hasPages())
            <div class="mt-4">{{ $invoices->links() }}</div>
        @endif
    </section>

</div>
