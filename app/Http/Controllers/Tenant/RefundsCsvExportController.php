<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the current Refunds-page view as CSV. Same filter query
 * params the Livewire page uses (q, status, from, to), so the
 * export link on the toolbar streams exactly what's on-screen.
 *
 * Rows: one booking per line with paid / refunded / held. Held is
 * derived on the fly so partial refunds show correctly.
 */
class RefundsCsvExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $status = trim((string) $request->query('status', ''));
        $statuses = $status !== ''
            ? [$status]
            : [BookingStatus::Cancelled->value, BookingStatus::NoShow->value];

        $bookings = Booking::query()
            ->with(['room:id,room_number'])
            ->whereIn('booking_status', $statuses);

        if ($q = trim((string) $request->query('q', ''))) {
            $like = '%'.$q.'%';
            $bookings->where(function ($sq) use ($like): void {
                $sq->where('booking_ref', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhere('guest_phone', 'like', $like)
                    ->orWhere('guest_email', 'like', $like);
            });
        }

        if ($from = $this->parseDate($request->query('from'), null)) {
            $bookings->where('updated_at', '>=', $from->startOfDay());
        }
        if ($to = $this->parseDate($request->query('to'), null)) {
            $bookings->where('updated_at', '<=', $to->endOfDay());
        }

        $rows = $bookings->orderByDesc('updated_at')->get();

        // Aggregate payments once for the whole set.
        $paidByBooking = [];
        if ($rows->isNotEmpty()) {
            $agg = Payment::query()
                ->whereIn('booking_id', $rows->pluck('id'))
                ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
                ->toBase()
                ->selectRaw("booking_id,
                    SUM(CASE WHEN status = 'succeeded' THEN amount ELSE 0 END) as paid,
                    SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refunded")
                ->groupBy('booking_id')
                ->get();
            foreach ($agg as $r) {
                $paidByBooking[(int) $r->booking_id] = [
                    'paid' => (int) $r->paid,
                    'refunded' => (int) $r->refunded,
                ];
            }
        }
        // Only rows with held > 0 belong in this export.
        $rows = $rows->filter(function ($b) use ($paidByBooking): bool {
            $p = $paidByBooking[$b->id] ?? ['paid' => 0, 'refunded' => 0];
            return ($p['paid'] - $p['refunded']) > 0;
        })->values();

        $filename = 'refunds-pending-'.Carbon::now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $paidByBooking): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) return;

            fputcsv($out, ['Pending refunds — cash held on cancelled / no-show bookings']);
            fputcsv($out, ['Generated', Carbon::now()->toDateTimeString()]);
            fputcsv($out, ['Rows', (string) $rows->count()]);
            fputcsv($out, []);
            fputcsv($out, [
                'Booking ref', 'Guest', 'Guest phone', 'Guest email',
                'Room', 'Status', 'Cancelled/no-show at',
                'Paid (₦)', 'Refunded (₦)', 'Held (₦)',
            ]);

            foreach ($rows as $b) {
                $p = $paidByBooking[$b->id] ?? ['paid' => 0, 'refunded' => 0];
                $held = max(0, (int) $p['paid'] - (int) $p['refunded']);
                $anchor = $b->booking_status?->value === 'no_show'
                    ? ($b->no_show_at ?? $b->updated_at)
                    : $b->updated_at;
                fputcsv($out, [
                    (string) $b->booking_ref,
                    (string) ($b->guest_name ?? ''),
                    (string) ($b->guest_phone ?? ''),
                    (string) ($b->guest_email ?? ''),
                    (string) ($b->room?->room_number ?? ''),
                    (string) ($b->booking_status?->value ?? ''),
                    $anchor?->toDateTimeString() ?? '',
                    (string) $p['paid'],
                    (string) $p['refunded'],
                    (string) $held,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function parseDate($raw, ?Carbon $fallback): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') return $fallback;
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
