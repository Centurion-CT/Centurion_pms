<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PosSaleStatus;
use App\Enums\RoomInspectionStatus;
use App\Enums\StockRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\PosSale;
use App\Models\RoomInspection;
use App\Models\StockRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Single-shot manager glance dashboard for the mobile Home tab.
 *
 * One JSON payload covers everything a manager needs to see first
 * thing in the morning:
 *
 *   - kpis   → today's counts + amounts (arrivals, departures,
 *              in-house, outstanding, open inspections, pending
 *              stock, today's POS + booking revenue)
 *   - attention → short lists (max 5 each) of items that need action:
 *      bookings_needing_action (Pending / OnHold)
 *      rejected_inspections    (Failed)
 *      pending_stock_requests  (Pending)
 *
 * Manager / Frontdesk / Reservation Officer roles are allowed;
 * others get 403 so we don't leak revenue numbers to housekeepers
 * or PosStaff.
 */
class ManagerController extends Controller
{
    private const ALLOWED_ROLES = [
        OperatorRole::Manager,
        OperatorRole::Frontdesk,
        OperatorRole::ReservationOfficer,
    ];

    public function glance(Request $request): JsonResponse
    {
        $operator = $this->operator($request);
        if (! in_array($operator->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $today = Carbon::today();
        $startOfDay = $today->copy()->startOfDay();
        $endOfDay = $today->copy()->endOfDay();

        // ── Booking counts ────────────────────────────────────
        $arrivalsCount = Booking::query()
            ->whereDate('checkin_date', $today->toDateString())
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
                BookingStatus::Pending->value,
            ])
            ->count();

        $departuresCount = Booking::query()
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkout_date', $today->toDateString())
            ->count();

        $inHouseCount = Booking::query()
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->count();

        // ── Outstanding balance (money owed on non-terminal bookings) ──
        // Sum of balance_due across non-cancelled/no-show/checked-out
        // bookings. Fast enough as a single SUM.
        $outstandingTotal = (int) Booking::query()
            ->whereNotIn('booking_status', [
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
                BookingStatus::CheckedOut->value,
            ])
            ->where('balance_due', '>', 0)
            ->sum('balance_due');

        // ── Today's revenue ───────────────────────────────────
        // POS: sum of Completed sales sold today.
        $todayPosRevenue = (int) PosSale::query()
            ->where('status', PosSaleStatus::Completed->value)
            ->whereBetween('sold_at', [$startOfDay, $endOfDay])
            ->sum('total');

        // Bookings: total_amount of bookings that checked in today
        // (rough — matches "today's stay revenue" mental model for
        // most operators).
        $todayBookingRevenue = (int) Booking::query()
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkin_date', $today->toDateString())
            ->sum('total_amount');

        // ── Housekeeping + stock ──────────────────────────────
        $openInspectionsCount = RoomInspection::query()
            ->whereNotIn('status', [
                RoomInspectionStatus::Passed->value,
                RoomInspectionStatus::Failed->value,
            ])
            ->count();

        $rejectedInspectionsCount = RoomInspection::query()
            ->where('status', RoomInspectionStatus::Failed->value)
            ->count();

        $pendingStockCount = StockRequest::query()
            ->where('status', StockRequestStatus::Pending->value)
            ->count();

        // ── Attention lists (top 5 each) ──────────────────────
        $bookingsNeedingAction = Booking::query()
            ->whereIn('booking_status', [
                BookingStatus::Pending->value,
                BookingStatus::OnHold->value,
            ])
            ->with(['room:id,room_number'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Booking $b) => [
                'booking_ref' => $b->booking_ref,
                'guest_name' => $b->guest_name,
                'status' => $b->booking_status->value,
                'checkin_date' => $b->checkin_date?->toDateString(),
                'balance_due' => (int) $b->outstandingTotal(),
                'room_number' => $b->room?->room_number,
            ]);

        $rejectedInspections = RoomInspection::query()
            ->where('status', RoomInspectionStatus::Failed->value)
            ->with(['room:id,room_number,room_category_id', 'room.category:id,name'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (RoomInspection $i) => [
                'id' => $i->id,
                'room_number' => $i->room?->room_number,
                'category_name' => $i->room?->category?->name,
                'summary_note' => $i->summary_note,
                'rejected_at' => $i->updated_at?->toIso8601String(),
            ]);

        $pendingStockRequests = StockRequest::query()
            ->where('status', StockRequestStatus::Pending->value)
            ->with(['outlet:id,name', 'product:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (StockRequest $r) => [
                'id' => $r->id,
                'outlet_name' => $r->outlet?->name,
                'product_name' => $r->product?->name,
                'quantity' => (int) $r->quantity,
                'requested_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'as_of' => now()->toIso8601String(),
            'kpis' => [
                'arrivals_today' => $arrivalsCount,
                'departures_today' => $departuresCount,
                'in_house' => $inHouseCount,
                'outstanding_total' => $outstandingTotal,
                'open_inspections' => $openInspectionsCount,
                'rejected_inspections' => $rejectedInspectionsCount,
                'pending_stock_requests' => $pendingStockCount,
                'today_pos_revenue' => $todayPosRevenue,
                'today_booking_revenue' => $todayBookingRevenue,
            ],
            'attention' => [
                'bookings_needing_action' => $bookingsNeedingAction,
                'rejected_inspections' => $rejectedInspections,
                'pending_stock_requests' => $pendingStockRequests,
            ],
        ]);
    }

    private function operator(Request $request): Operator
    {
        $op = $request->user();
        if (! $op instanceof Operator) {
            abort(401);
        }

        return $op;
    }
}
