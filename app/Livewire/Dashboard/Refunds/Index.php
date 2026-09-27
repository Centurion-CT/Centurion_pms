<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Refunds;

use App\Actions\Bookings\RecordBookingRefund;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

/**
 * Pending refunds — bookings where the till took cash but the
 * booking got cancelled / no-show and no refund was recorded.
 *
 * The "held" amount is derived from the payment ledger, not from
 * booking.balance_due, so partial refunds are handled cleanly:
 *   held = SUM(payments.amount status=succeeded) − SUM(status=refunded)
 * A booking shows up here iff held > 0 AND booking status in
 * (Cancelled, NoShow). No-shows are folded in on purpose — same
 * scenario, same money sitting in the till, same conversation with
 * the guest.
 *
 * From here the operator either records a refund (opens the
 * reservation, which is where the refund UI lives) or marks the
 * amount as an intentional forfeit (future — right now this page
 * is read-only with a link out to the reservation).
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';   // '' | cancelled | no_show

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    public function updatedSearch(): void { $this->resetPage(); }

    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function updatedDateFrom(): void { $this->resetPage(); }

    public function updatedDateTo(): void { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    /**
     * Record a refund against a booking inline from the panel. The
     * amount is capped at the held total (paid − refunded) so the
     * operator can't accidentally over-refund. Method is one of the
     * standard payment methods; note is optional but nudged in the
     * UI ("reason", "reference number", etc.).
     *
     * Called from the Alpine panel's "Record refund" form via
     * $wire.recordRefund(bookingId, amount, method, note).
     */
    public function recordRefund(
        int $bookingId,
        int $amount,
        string $method,
        ?string $note = null,
        RecordBookingRefund $action = null,
    ): void {
        $action ??= app(RecordBookingRefund::class);
        $op = auth('web')->user();
        if (! $op instanceof Operator) {
            $this->dispatch('notify', title: 'Not signed in', message: 'Sign in and try again.', kind: 'err');
            return;
        }
        if ($amount <= 0) {
            $this->dispatch('notify', title: 'Amount required', message: 'Refund amount must be greater than zero.', kind: 'err');
            return;
        }
        if (! in_array($method, ['cash', 'transfer', 'card', 'paystack', 'other'], true)) {
            $this->dispatch('notify', title: 'Method required', message: 'Pick a valid refund method.', kind: 'err');
            return;
        }

        $booking = Booking::query()->find($bookingId);
        if ($booking === null) {
            $this->dispatch('notify', title: 'Booking gone', message: 'The reservation is no longer available.', kind: 'err');
            return;
        }

        // Cap the refund at the currently-held amount so a typo
        // can't push us into a negative-balance situation.
        $ledger = $this->paidRefundedByBooking([$bookingId])[$bookingId] ?? ['paid' => 0, 'refunded' => 0];
        $held = max(0, (int) $ledger['paid'] - (int) $ledger['refunded']);
        if ($held <= 0) {
            $this->dispatch('notify', title: 'Nothing to refund', message: 'This booking has no held cash — already refunded.', kind: 'err');
            return;
        }
        if ($amount > $held) {
            $this->dispatch('notify',
                title: 'Amount too high',
                message: 'Held is ₦'.number_format($held).'. Enter that or less.',
                kind: 'err',
            );
            return;
        }

        try {
            $action->handle(
                booking: $booking,
                operator: $op,
                amount: $amount,
                method: $method,
                referenceNote: $note !== null && trim($note) !== '' ? trim($note) : null,
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t record refund', message: $e->getMessage(), kind: 'err');
            return;
        }

        $this->dispatch('notify',
            title: 'Refund recorded',
            message: 'Refund of ₦'.number_format($amount).' logged. The booking now shows the new held balance.',
            kind: 'ok',
        );
    }

    public function render(): View
    {
        $ids = $this->pendingBookingIds();

        $bookings = Booking::query()
            ->with(['room:id,room_number', 'customer:id,name,phone'])
            ->whereIn('id', $ids)
            ->orderByDesc('updated_at')
            ->paginate(20);

        // KPI strip — computed over the FILTERED set (not the page)
        // so the numbers describe the current query, not just page 1.
        $paidByBooking = $this->paidRefundedByBooking($ids);
        $heldTotal = 0;
        foreach ($ids as $bid) {
            $row = $paidByBooking[$bid] ?? ['paid' => 0, 'refunded' => 0];
            $heldTotal += max(0, (int) $row['paid'] - (int) $row['refunded']);
        }

        // Client-side detail panel payload — one entry per booking on
        // the current page, keyed by id. Fetched once (payments +
        // cancellation-event lookups) so opening a row is instant.
        $pageIds = $bookings->pluck('id')->all();
        $panels = $this->buildPanels($pageIds, $paidByBooking);

        return view('livewire.dashboard.refunds.index', [
            'bookings' => $bookings,
            'paidByBooking' => $paidByBooking,
            'panels' => $panels,
            'kpis' => [
                'count' => count($ids),
                'held_total' => $heldTotal,
            ],
            'exportUrl' => route('tenant.refunds.export', array_filter([
                'q' => $this->search !== '' ? $this->search : null,
                'status' => $this->statusFilter !== '' ? $this->statusFilter : null,
                'from' => $this->dateFrom !== '' ? $this->dateFrom : null,
                'to' => $this->dateTo !== '' ? $this->dateTo : null,
            ])),
        ]);
    }

    /**
     * Build the click-a-row panel payload for the current page of
     * bookings. Loads:
     *   - the recent Payment rows (up to 5) so the panel shows what
     *     came in and any partial refunds already recorded
     *   - the cancel / no-show activity_log entry with causer + note
     *
     * Returns a keyed map so the Alpine factory can find(id) in O(1).
     *
     * @param  list<int>  $bookingIds
     * @param  array<int, array{paid:int, refunded:int}>  $paidRefunded
     * @return array<int, array<string, mixed>>
     */
    private function buildPanels(array $bookingIds, array $paidRefunded): array
    {
        if ($bookingIds === []) return [];

        // Pull the booking rows again with the extra relations we
        // need for the panel — paginate() only carried room + customer
        // shells for the table.
        $bookings = Booking::query()
            ->with(['room:id,room_number,room_category_id', 'room.category:id,name'])
            ->whereIn('id', $bookingIds)
            ->get()->keyBy('id');

        // Payments per booking, most-recent first, capped at 5.
        $payments = Payment::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->orderByDesc('paid_at')
            ->get()
            ->groupBy('booking_id');

        // Cancel / no-show event per booking with causer for
        // attribution. Fetched in one query keyed by subject_id.
        $events = \Spatie\Activitylog\Models\Activity::query()
            ->with('causer:id,name')
            ->where('subject_type', Booking::class)
            ->whereIn('subject_id', $bookingIds)
            ->whereIn('event', ['cancelled', 'no_show'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('subject_id');

        $panels = [];
        foreach ($bookingIds as $id) {
            $b = $bookings->get($id);
            if (! $b) continue;
            $pr = $paidRefunded[$id] ?? ['paid' => 0, 'refunded' => 0];
            $held = max(0, (int) $pr['paid'] - (int) $pr['refunded']);

            // Recent payment rows shaped for the panel view.
            $pays = ($payments[$id] ?? collect())->take(5)->map(fn (Payment $p) => [
                'amount' => (int) $p->amount,
                'status' => (string) $p->status->value,
                'method' => ucfirst((string) ($p->method ?? 'paystack')),
                'paid_at' => $p->paid_at?->format('j M · H:i') ?? '—',
            ])->values()->all();

            // First cancel/no-show event for this booking (latest
            // wins if there's more than one — should be one in
            // practice).
            $event = ($events[$id] ?? collect())->first();
            $cancelInfo = null;
            if ($event) {
                $props = $event->properties?->all() ?? [];
                $cancelInfo = [
                    'kind' => (string) $event->event,          // 'cancelled' | 'no_show'
                    'at' => $event->created_at?->format('D, j M Y · H:i') ?? '—',
                    'by' => $event->causer?->name ?? 'System',
                    'note' => (string) ($event->description ?? ''),
                    'deposit_forfeit' => (bool) ($props['deposit_forfeit'] ?? false),
                    'refund_requested' => (bool) ($props['refund_requested'] ?? false),
                    'days_until_checkin' => $props['days_until_checkin'] ?? null,
                ];
            }

            $panels[$id] = [
                'id' => $id,
                'ref' => (string) $b->booking_ref,
                'guest_name' => (string) $b->guest_name,
                'guest_phone' => (string) ($b->guest_phone ?? ''),
                'guest_email' => (string) ($b->guest_email ?? ''),
                'room' => $b->room?->room_number ? 'Rm '.$b->room->room_number : '—',
                'category' => (string) ($b->room?->category?->name ?? ''),
                'checkin' => $b->checkin_date?->format('D, j M Y') ?? '—',
                'checkout' => $b->checkout_date?->format('D, j M Y') ?? '—',
                'nights' => (int) ($b->nights ?? 0),
                'total' => (int) ($b->total_amount ?? 0),
                'paid' => (int) $pr['paid'],
                'refunded' => (int) $pr['refunded'],
                'held' => $held,
                'status' => (string) ($b->booking_status?->value ?? ''),
                'cancel' => $cancelInfo,
                'payments' => $pays,
                'url' => route('tenant.reservations.show', $b->booking_ref),
            ];
        }
        return $panels;
    }

    /**
     * Shared filter: return booking ids that qualify as "pending
     * refund" — cancelled/no-show with paid > refunded. Used by both
     * the list query and the KPI totals so a filter change updates
     * both in lockstep.
     *
     * @return list<int>
     */
    private function pendingBookingIds(): array
    {
        $statuses = $this->statusFilter !== ''
            ? [$this->statusFilter]
            : [BookingStatus::Cancelled->value, BookingStatus::NoShow->value];

        $bookingQuery = Booking::query()
            ->whereIn('booking_status', $statuses);

        // Scope to the operator's current branch — the panel list, KPI
        // held-total, and downstream Payment / event lookups all read
        // from this base id set, so filtering here propagates cleanly.
        $propertyId = app(PropertyContext::class)->current()?->getKey();
        if ($propertyId !== null) {
            $bookingQuery->whereHas('room', fn ($r) => $r->where('property_id', $propertyId));
        }

        // Date filter clamps by when the booking transitioned to its
        // current status. There's no dedicated cancelled_at column,
        // so updated_at is the closest proxy for cancelled bookings;
        // no_show_at is the precise anchor for no-shows.
        if ($this->dateFrom !== '' || $this->dateTo !== '') {
            $from = $this->dateFrom !== '' ? Carbon::parse($this->dateFrom)->startOfDay() : null;
            $to = $this->dateTo !== '' ? Carbon::parse($this->dateTo)->endOfDay() : null;
            $bookingQuery->where(function ($q) use ($from, $to): void {
                $q->where(function ($sub) use ($from, $to): void {
                    if ($from) $sub->where('updated_at', '>=', $from);
                    if ($to) $sub->where('updated_at', '<=', $to);
                });
            });
        }

        if ($this->search !== '') {
            $like = '%'.trim($this->search).'%';
            $bookingQuery->where(function ($q) use ($like): void {
                $q->where('booking_ref', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhere('guest_phone', 'like', $like)
                    ->orWhere('guest_email', 'like', $like);
            });
        }

        $candidateIds = $bookingQuery->pluck('id')->all();
        if ($candidateIds === []) return [];

        // Aggregate payments once + keep only those where held > 0.
        $paid = $this->paidRefundedByBooking($candidateIds);
        $qualifying = [];
        foreach ($candidateIds as $bid) {
            $row = $paid[$bid] ?? ['paid' => 0, 'refunded' => 0];
            if ((int) $row['paid'] - (int) $row['refunded'] > 0) {
                $qualifying[] = $bid;
            }
        }
        return $qualifying;
    }

    /**
     * @param  list<int>  $bookingIds
     * @return array<int, array{paid:int, refunded:int}>
     */
    private function paidRefundedByBooking(array $bookingIds): array
    {
        if ($bookingIds === []) return [];
        $rows = Payment::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->toBase()
            ->selectRaw("booking_id,
                SUM(CASE WHEN status = 'succeeded' THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refunded")
            ->groupBy('booking_id')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->booking_id] = [
                'paid' => (int) $r->paid,
                'refunded' => (int) $r->refunded,
            ];
        }
        return $out;
    }
}
