<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Room;
use App\Services\Payments\BookingPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Host app week grid (docs/host-app/PHASE-3.md, Calendar 17).
 *
 * Same data the web calendar ({@see PropertyView})
 * paints, scoped to the bound branch:
 *  - rooms: the branch's active rooms, by category then number.
 *  - stays: one bar per booking from its Booked availability blocks in
 *    the window, plus the web's checkout-day overlay for checked-in
 *    guests: a guest still checked in on/after the booked checkout is
 *    drawn from the checkout date through today (overdue when the
 *    checkout has passed, departing when it is today).
 *  - blocks: operator Blocked nights (close for maintenance / blocked
 *    dates) as contiguous runs per room + reason.
 *  - occupancy: rooms with a guest that night / active rooms, per day
 *    (Booked nights plus overdue guests still in the room, the same
 *    definition host/today and the occupancy reports use).
 * Dates are YYYY-MM-DD; `to` and every `end` are exclusive.
 */
class CalendarController extends HostController
{
    private const MAX_DAYS = 31;

    private const WAITING_STATUSES = [
        BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ]);
        $property = $this->property($request);
        $from = Carbon::parse($data['from'])->startOfDay();
        $days = (int) ($data['days'] ?? 7);
        $to = $from->copy()->addDays($days);            // exclusive
        $lastDay = $to->copy()->subDay();
        $today = Carbon::today();
        $todayIso = $today->toDateString();

        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active->value)
            ->with('category:id,name')
            ->orderBy('room_category_id')
            ->orderBy('room_number')
            ->get();
        $roomIds = $rooms->pluck('id')->all();

        $blocks = $roomIds === [] ? collect() : AvailabilityBlock::query()
            ->whereIn('room_id', $roomIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $lastDay->toDateString())
            ->orderBy('date')
            ->get();

        // Checked-in guests at/after their booked checkout (web overlay).
        $lingering = $roomIds === [] ? collect() : Booking::query()
            ->whereIn('room_id', $roomIds)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereNotNull('checkout_date')
            ->whereDate('checkout_date', '<=', $todayIso)
            ->get();

        $bookedBlocks = $blocks->filter(fn (AvailabilityBlock $b) => $b->type === AvailabilityBlockType::Booked && $b->booking_id !== null);
        $bookingIds = $bookedBlocks->pluck('booking_id')->merge($lingering->pluck('id'))->unique()->values()->all();
        $bookings = $bookingIds === [] ? collect() : Booking::query()
            ->with(['customer.wallet'])
            ->whereIn('id', $bookingIds)
            ->get()
            ->keyBy('id');

        // ── Stays ────────────────────────────────────────────────
        $runs = []; // "bookingId:roomId" => [startIso, endIso)
        foreach ($bookedBlocks as $b) {
            $key = $b->booking_id.':'.$b->room_id;
            $iso = $b->date->toDateString();
            $next = $b->date->copy()->addDay()->toDateString();
            if (! isset($runs[$key])) {
                $runs[$key] = [$iso, $next];
            } else {
                $runs[$key][0] = min($runs[$key][0], $iso);
                $runs[$key][1] = max($runs[$key][1], $next);
            }
        }

        // Overlay: checkout day .. today, clipped to the window, only on
        // cells no real block already owns (a new arrival wins).
        $taken = [];
        foreach ($blocks as $b) {
            $taken[$b->room_id.':'.$b->date->toDateString()] = true;
        }
        foreach ($lingering as $booking) {
            $checkout = $booking->checkout_date->copy()->startOfDay();
            $start = $checkout->greaterThan($from) ? $checkout : $from->copy();
            $end = $today->lessThan($lastDay) ? $today->copy() : $lastDay->copy();
            for ($c = $start->copy(); $c->lte($end); $c->addDay()) {
                $iso = $c->toDateString();
                if (isset($taken[$booking->room_id.':'.$iso])) {
                    continue;
                }
                $key = $booking->id.':'.$booking->room_id;
                $next = $c->copy()->addDay()->toDateString();
                if (! isset($runs[$key])) {
                    $runs[$key] = [$iso, $next];
                } else {
                    $runs[$key][0] = min($runs[$key][0], $iso);
                    $runs[$key][1] = max($runs[$key][1], $next);
                }
            }
        }

        $stays = [];
        foreach ($runs as $key => [$start, $end]) {
            [$bookingId, $roomId] = array_map('intval', explode(':', $key));
            $booking = $bookings->get($bookingId);
            if ($booking === null) {
                continue;
            }
            $stays[] = [
                'ref' => (string) $booking->booking_ref,
                'room_id' => $roomId,
                'guest_name' => (string) $booking->guest_name,
                'guest_surname' => self::surname((string) $booking->guest_name),
                'start' => $start,
                'end' => $end,
                'state' => $this->stayState($booking, $todayIso),
                'balance_due' => BookingPayments::owed($booking),
            ];
        }
        usort($stays, fn (array $a, array $b) => [$a['room_id'], $a['start']] <=> [$b['room_id'], $b['start']]);

        // ── Operator blocks ──────────────────────────────────────
        $blockRuns = [];
        $blocked = $blocks->filter(fn (AvailabilityBlock $b) => $b->type === AvailabilityBlockType::Blocked)
            ->sortBy(fn (AvailabilityBlock $b) => sprintf('%010d:%s', $b->room_id, $b->date->toDateString()));
        foreach ($blocked as $b) {
            $iso = $b->date->toDateString();
            $reason = $b->reason !== null && trim($b->reason) !== '' ? trim($b->reason) : null;
            $last = $blockRuns === [] ? null : $blockRuns[array_key_last($blockRuns)];
            if ($last !== null && $last['room_id'] === (int) $b->room_id && $last['end'] === $iso && $last['reason'] === $reason) {
                $blockRuns[array_key_last($blockRuns)]['end'] = $b->date->copy()->addDay()->toDateString();

                continue;
            }
            $blockRuns[] = [
                'room_id' => (int) $b->room_id,
                'start' => $iso,
                'end' => $b->date->copy()->addDay()->toDateString(),
                'kind' => $reason !== null && str_contains(mb_strtolower($reason), 'maintenance') ? 'maintenance' : 'blocked',
                'reason' => $reason,
            ];
        }

        // ── Occupancy per day ────────────────────────────────────
        $roomCount = count($roomIds);
        $occupied = [];
        foreach ($bookedBlocks as $b) {
            $occupied[$b->date->toDateString()][(int) $b->room_id] = true;
        }
        foreach ($lingering as $booking) {
            if ($booking->checkout_date->toDateString() >= $todayIso) {
                continue; // due out today: leaves before tonight
            }
            for ($c = $booking->checkout_date->copy()->startOfDay(); $c->lte($today); $c->addDay()) {
                $occupied[$c->toDateString()][(int) $booking->room_id] = true;
            }
        }
        $occupancy = [];
        for ($c = $from->copy(); $c->lt($to); $c->addDay()) {
            $iso = $c->toDateString();
            $n = count($occupied[$iso] ?? []);
            $occupancy[] = [
                'date' => $iso,
                'pct' => $roomCount > 0 ? (int) round(min($n / $roomCount, 1) * 100) : 0,
            ];
        }

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $todayIso,
            'rooms' => $rooms->map(fn (Room $r) => [
                'id' => (int) $r->id,
                'number' => (string) $r->room_number,
                'category' => $r->category?->name,
                'category_short' => self::categoryShort($r->category?->name),
            ])->values()->all(),
            'stays' => $stays,
            'blocks' => $blockRuns,
            'occupancy' => $occupancy,
        ]]);
    }

    private function stayState(Booking $b, string $todayIso): string
    {
        if ($b->booking_status === BookingStatus::CheckedIn) {
            $checkout = $b->checkout_date?->toDateString() ?? $todayIso;

            return match (true) {
                $checkout < $todayIso => 'overdue',
                $checkout === $todayIso => 'departing',
                default => 'in_house',
            };
        }
        if (in_array($b->booking_status, self::WAITING_STATUSES, true)
            && $b->checkin_date?->toDateString() === $todayIso) {
            return 'arriving';
        }

        return 'booked';
    }

    /** Last word of the name (web calendar chip rule), else the first. */
    public static function surname(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [''];

        return trim(end($parts) ?: reset($parts) ?: '') ?: 'Guest';
    }

    /** "Executive Deluxe" -> "ED", "Deluxe" -> "DEL". */
    public static function categoryShort(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 3));
        }

        return mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 3))));
    }
}
