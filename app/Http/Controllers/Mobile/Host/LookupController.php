<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Room;
use App\Services\Bookings\RoomAllocator;
use App\Services\Corporates\CorporateResolver;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Host app lookups for the new-booking flow + the app's label source
 * (docs/host-app/PHASE-2.md, section B): free rooms in the bound
 * branch, customer lookup by phone, and `meta`.
 */
class LookupController extends HostController
{
    public function availableRooms(Request $request, RoomAllocator $allocator, PricingCalculator $pricing): JsonResponse
    {
        $data = $request->validate([
            'checkin' => ['required', 'date_format:Y-m-d'],
            'checkout' => ['required', 'date_format:Y-m-d', 'after:checkin'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);
        $property = $this->property($request);
        $checkin = Carbon::parse($data['checkin']);
        $checkout = Carbon::parse($data['checkout']);
        $adults = (int) ($data['adults'] ?? 1);
        $children = (int) ($data['children'] ?? 0);

        $rooms = $allocator->availableRoomsInProperty((int) $property->id, $checkin, $checkout)
            ->filter(fn (Room $room) => $room->category !== null
                && (int) $room->category->capacity_adults >= $adults
                && (int) $room->category->capacity_children >= $children)
            ->values();

        return response()->json([
            'data' => $rooms->map(function (Room $room) use ($pricing, $checkin, $checkout): array {
                $category = $room->category;
                // Walk-in pricing: net subtotal, no online fees (same as
                // CreateBooking for desk sources).
                $breakdown = $pricing->calculate($room, $checkin, $checkout);
                $nights = max(1, $breakdown->nights);

                return [
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'category' => (string) ($category?->name ?? ''),
                    'nightly_rate' => (int) round($breakdown->netSubtotal / $nights),
                    'total' => (int) $breakdown->netSubtotal,
                    'breakfast_available' => (bool) ($category?->hasBreakfastOption() ?? false),
                    'breakfast_rate' => $category?->hasBreakfastOption() ? (int) $category->breakfast_included_rate : null,
                    'min_deposit' => (int) $breakdown->depositAmount,
                ];
            })->all(),
        ]);
    }

    public function customerLookup(Request $request, CorporateResolver $corporates): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone = (string) $data['phone'];

        $customer = self::findCustomerByPhone($phone);
        $corp = strlen(preg_replace('/\D+/', '', $phone) ?? '') >= 7 ? $corporates->resolveByPhone($phone) : null;
        $corporate = $corp !== null
            ? ['id' => (int) $corp['corporate']->id, 'name' => (string) $corp['corporate']->name]
            : null;

        if ($customer === null) {
            // A company staffer with no stay history yet: still useful
            // (name prefill + "Bill to company"), but there is no
            // customer record or wallet.
            return response()->json(['data' => $corp === null ? null : [
                'id' => null,
                'name' => (string) $corp['member']->name,
                'email' => $corp['member']->email ?? null,
                'phone' => $phone,
                'stays_count' => 0,
                'is_vip' => false,
                'wallet_balance' => 0,
                'corporate' => $corporate,
            ]]);
        }

        return response()->json(['data' => [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'stays_count' => (int) $customer->bookings()->count(),
            'is_vip' => (bool) $customer->is_vip,
            'wallet_balance' => $customer->walletBalance(),
            'corporate' => $corporate,
        ]]);
    }

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'payment_methods' => PaymentMethod::takeOptions(),
            'charge_types' => array_map(
                fn (string $v) => ['value' => $v, 'label' => \App\Enums\BookingChargeType::from($v)->label()],
                BookingsController::chargeTypeValues(),
            ),
            'booking_statuses' => [
                ['value' => 'confirmed', 'label' => 'Paid in full', 'hint' => 'Cash, transfer, card or POS at the desk. Marked confirmed.'],
                ['value' => 'on_hold', 'label' => 'Deposit only', 'hint' => 'Deposit taken, rest due at check-in.'],
                ['value' => 'pending', 'label' => 'Not paid yet', 'hint' => 'Collect later. Marked pending.'],
                ['value' => 'wallet', 'label' => 'Pay from wallet', 'hint' => 'Uses the guest wallet. Confirmed if it covers the total, on hold otherwise.'],
                ['value' => 'corp_invoice', 'label' => 'Bill to company', 'hint' => 'The company pays by invoice after the stay.'],
            ],
            'early_checkout_modes' => [
                ['value' => 'keep', 'label' => 'Keep as revenue', 'hint' => 'No refund for the unused nights.'],
                ['value' => 'wallet', 'label' => 'Credit to wallet', 'hint' => 'The guest can spend it on a future stay.'],
                ['value' => 'cash', 'label' => 'Refund in cash', 'hint' => 'Count the refund out at the desk.'],
            ],
        ]]);
    }

    /**
     * Same match as the web quick-reserve sheet: exact phone, or the
     * stored phone ending in the typed digits (+234… vs 080…).
     */
    public static function findCustomerByPhone(string $phone): ?Customer
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($normalized) < 7) {
            return null;
        }

        return Customer::query()
            ->where(function ($q) use ($phone, $normalized): void {
                $q->where('phone', $phone)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ['%'.$normalized]);
            })
            ->with('wallet')
            ->first();
    }
}
