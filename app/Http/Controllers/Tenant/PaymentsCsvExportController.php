<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Livewire\Dashboard\Payments\Index as PaymentsPage;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the current Payments-page view as a CSV. Accepts the same
 * query params the Livewire page uses (from, to, method, staff, status,
 * type, channel, q) so the operator can hand off exactly what they
 * see on-screen to Sheets / Excel.
 *
 * Rows: one Payment per line, joined with booking + collectedBy so
 * finance can reconcile without opening the app for each cell.
 */
class PaymentsCsvExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $from = $this->parseDate($request->query('from'), Carbon::today());
        $to   = $this->parseDate($request->query('to'), Carbon::today());

        $query = Payment::query()
            ->with(['booking.room.category', 'booking.appliedDiscounts', 'collectedBy'])
            ->where('paid_at', '>=', $from->copy()->startOfDay())
            ->where('paid_at', '<=', $to->copy()->endOfDay());

        if ($q = trim((string) $request->query('q', ''))) {
            $like = '%'.$q.'%';
            $query->where(function ($sq) use ($like): void {
                $sq->where('paystack_reference', 'like', $like)
                    ->orWhereHas('booking', fn ($b) => $b
                        ->where('booking_ref', 'like', $like)
                        ->orWhere('guest_name', 'like', $like)
                        ->orWhere('guest_email', 'like', $like)
                        ->orWhere('guest_phone', 'like', $like)
                        ->orWhereHas('room', fn ($r) => $r->where('room_number', 'like', $like)));
            });
        }
        if ($status = (string) $request->query('status', '')) {
            $query->where('status', $status);
        }
        if ($type = (string) $request->query('type', '')) {
            $query->where('type', $type);
        }
        if ($channel = (string) $request->query('channel', '')) {
            $query->whereHas('booking', fn ($b) => $b->where('source', $channel));
        }
        if ($method = (string) $request->query('method', '')) {
            if ($method === 'paystack') {
                $query->whereNull('method');
            } else {
                $query->where('method', $method);
            }
        }
        if ($staff = (string) $request->query('staff', '')) {
            $query->where('collected_by_operator_id', (int) $staff);
        }

        $rows = $query->orderBy('paid_at', 'desc')->orderByDesc('id')->get();
        $settled = (int) $rows->where('status', PaymentStatus::Succeeded)->sum('amount');
        $refunded = (int) $rows->where('status', PaymentStatus::Refunded)->sum('amount');

        $filename = 'payments-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows, $from, $to, $settled, $refunded): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) return;

            fputcsv($out, ['Payments export']);
            fputcsv($out, ['Range', $from->toDateString().' → '.$to->toDateString()]);
            fputcsv($out, ['Rows', (string) $rows->count()]);
            fputcsv($out, ['Settled total (₦)', (string) $settled]);
            fputcsv($out, ['Refunded total (₦)', (string) $refunded]);
            fputcsv($out, ['Net (₦)', (string) ($settled - $refunded)]);
            fputcsv($out, []);
            fputcsv($out, [
                'Paid at',
                'Reference',
                'Booking ref',
                'Guest name',
                'Guest phone',
                'Guest email',
                'Room',
                'Room category',
                'Channel',
                'Nights',
                'Rate/night (₦)',
                'Breakfast included',
                'Discount (₦)',
                'Type',
                'Method',
                'Status',
                'Staff',
                'Amount (₦)',
                'Note',
            ]);

            foreach ($rows as $p) {
                $b = $p->booking;
                $nights = (int) ($b?->nights ?? 0);
                $ratePerNight = ($b && $nights > 0) ? intdiv((int) $b->room_total, $nights) : 0;
                $autoOff = (int) ($b?->appliedDiscounts?->sum('amount_off') ?? 0);
                $manualOff = (int) ($b?->manual_discount_amount ?? 0);
                fputcsv($out, [
                    $p->paid_at?->toDateTimeString() ?? '',
                    (string) $p->paystack_reference,
                    (string) ($b?->booking_ref ?? ''),
                    (string) ($b?->guest_name ?? ''),
                    (string) ($b?->guest_phone ?? ''),
                    (string) ($b?->guest_email ?? ''),
                    (string) ($b?->room?->room_number ?? ''),
                    (string) ($b?->room?->category?->name ?? ''),
                    (string) ($b?->source?->label() ?? ''),
                    (string) $nights,
                    (string) $ratePerNight,
                    ($b?->breakfast_included) ? 'Yes' : 'No',
                    (string) ($autoOff + $manualOff),
                    (string) $p->type->value,
                    (string) ($p->method ?? 'paystack'),
                    (string) $p->status->value,
                    (string) ($p->collectedBy?->name ?? ''),
                    (string) $p->amount,
                    (string) ($p->reference_note ?? ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function parseDate($raw, Carbon $fallback): Carbon
    {
        if (! is_string($raw) || trim($raw) === '') return $fallback;
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
