<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Streams a CSV of bookings (PRD §14). Honours the same filters as the
 * Bookings\Index Livewire page so the operator can "export current view"
 * in one click.
 *
 * Chunked: pulls 500 rows at a time and `flush()`es each batch so the
 * response starts before the full result set is materialised in memory.
 * Multi-tenant isolation is automatic via Booking's TenantScope.
 */
class BookingsCsvExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $needle = trim((string) $request->query('q', ''));
        $statusFilter = (string) $request->query('status', '');
        $roomFilter = $request->query('room');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $query = Booking::query()->with('room')->orderBy('id');

        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('guest_name', 'like', '%'.$needle.'%')
                    ->orWhere('guest_email', 'like', '%'.$needle.'%')
                    ->orWhere('booking_ref', 'like', '%'.$needle.'%');
            });
        }

        if ($statusFilter !== '' && BookingStatus::tryFrom($statusFilter) !== null) {
            $query->where('booking_status', $statusFilter);
        }

        if (is_numeric($roomFilter) && (int) $roomFilter > 0) {
            $query->where('room_id', (int) $roomFilter);
        }

        if ($from !== '') {
            try {
                $query->where('checkin_date', '>=', Carbon::parse($from)->startOfDay());
            } catch (Throwable) {
                // ignore unparseable
            }
        }
        if ($to !== '') {
            try {
                $query->where('checkin_date', '<=', Carbon::parse($to)->endOfDay());
            } catch (Throwable) {
                // ignore unparseable
            }
        }

        $filename = 'bookings-'.Carbon::now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'booking_ref', 'guest_name', 'guest_email', 'guest_phone',
                'room', 'checkin', 'checkout', 'nights',
                'adults', 'children', 'total_amount', 'deposit_amount', 'balance_due',
                'booking_status', 'payment_status', 'source', 'created_at',
            ]);

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $booking) {
                    fputcsv($out, [
                        $booking->booking_ref,
                        $booking->guest_name,
                        $booking->guest_email,
                        $booking->guest_phone,
                        $booking->room->name,
                        $booking->checkin_date->toDateString(),
                        $booking->checkout_date->toDateString(),
                        $booking->nights,
                        $booking->adults,
                        $booking->children,
                        $booking->total_amount,
                        $booking->deposit_amount,
                        $booking->balance_due,
                        $booking->booking_status->value,
                        $booking->payment_status->value,
                        $booking->source->value,
                        $booking->created_at?->toIso8601String() ?? '',
                    ]);
                }
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
