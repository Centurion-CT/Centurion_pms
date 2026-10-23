<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\BookingStatus;
use App\Enums\GuestRequestKind;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\Operator;
use App\Models\Property;
use App\Services\Bookings\HeldMoney;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Builds the host app approvals inbox (docs/host-app/PHASE-3.md, 19)
 * from the queues the web already has, with the web's own gates:
 *
 *  portal_action      pending GuestActionApproval rows of the branch
 *                     (web /dashboard/approvals, role:reservations)
 *  held_money         cancelled / no-show bookings of the branch still
 *                     holding money (web /dashboard/refunds, role:refunds)
 *
 * Ids are "<kind>:<id>". Centurion has no corporate request, rate or
 * transfer queues (Keverna's corporate_request kind is left out).
 */
class ApprovalInbox
{
    public const KINDS = ['portal_action', 'held_money'];

    public function __construct(private readonly HeldMoney $heldMoney) {}

    /** Which queues this operator may see / decide (web route gates). */
    public static function gates(Operator $operator): array
    {
        $approve = HostController::capabilities($operator)['approve'];

        return [
            'portal_action' => $approve,
            'held_money' => $approve && $operator->canAccessModule('refunds'),
        ];
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function items(Operator $operator, Property $property): array
    {
        $gates = self::gates($operator);
        $items = [];

        if ($gates['portal_action']) {
            foreach ($this->portalQuery($property)->get() as $a) {
                $items[] = $this->portalItem($a);
            }
        }
        if ($gates['held_money']) {
            $ids = $this->heldMoney->pendingBookingIds((int) $property->id);
            if ($ids !== []) {
                $held = $this->heldMoney->byBooking($ids);
                $bookings = Booking::query()->with('room:id,room_number')->whereIn('id', $ids)->get();
                $causers = $this->cancelCausers($ids);
                foreach ($bookings as $b) {
                    $items[] = $this->heldItem($b, (int) ($held[$b->id]['held'] ?? 0), $causers[$b->id] ?? null);
                }
            }
        }

        usort($items, fn (array $a, array $b) => [$b['created_at'] ?? '', $b['id']] <=> [$a['created_at'] ?? '', $a['id']]);

        return $items;
    }

    /**
     * Inbox approvals across several branches (owner "all branches"):
     * per-branch queues summed.
     *
     * @param  iterable<Property>  $properties
     */
    public function countFor(Operator $operator, iterable $properties): int
    {
        $n = 0;
        foreach ($properties as $property) {
            $n += $this->count($operator, $property);
        }

        return $n;
    }

    public function count(Operator $operator, Property $property): int
    {
        $gates = self::gates($operator);
        $n = 0;
        if ($gates['portal_action']) {
            $n += $this->portalQuery($property)->count();
        }
        if ($gates['held_money']) {
            $n += count($this->heldMoney->pendingBookingIds((int) $property->id));
        }

        return $n;
    }

    // ── Queries (same filters as the web pages, branch-scoped) ───

    public function portalQuery(Property $property)
    {
        return GuestActionApproval::query()
            ->with(['booking:id,booking_ref,guest_name,guest_phone', 'room:id,room_number,property_id'])
            ->where('status', 'pending')
            ->whereHas('room', fn ($r) => $r->where('property_id', $property->id))
            ->orderByDesc('id');
    }


    // ── Items ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function portalItem(GuestActionApproval $a): array
    {
        $payload = is_array($a->payload) ? $a->payload : [];
        $room = 'Rm '.($a->room?->room_number ?? '?');
        $detail = match (true) {
            in_array($a->kind, ['extend', 'extend_card'], true) && ! empty($payload['new_checkout_date']) => 'new check-out '.Carbon::parse((string) $payload['new_checkout_date'])->format('D j M'),
            $a->kind === 'reserve' && ! empty($payload['checkin']) => Carbon::parse((string) $payload['checkin'])->format('j M').' to '.Carbon::parse((string) ($payload['checkout'] ?? $payload['checkin']))->format('j M'),
            default => null,
        };
        $subtitle = $a->kind === 'checkin_no_payment'
            ? $room.' · asked by '.($payload['requested_by_operator_name'] ?? 'front desk')
            : $room.' · location score '.(int) $a->coord_score.($detail !== null ? ' · '.$detail : '');
        $amount = $payload['amount'] ?? $payload['total_amount'] ?? null;

        return [
            'id' => 'portal_action:'.$a->id,
            'kind' => 'portal_action',
            'title' => self::portalTitle($a->kind),
            'subtitle' => $subtitle,
            'amount' => is_numeric($amount) ? (int) $amount : null,
            'created_at' => $a->created_at?->toIso8601String(),
            'requested_by' => $payload['requested_by_operator_name'] ?? null,
            'booking_ref' => $a->booking?->booking_ref,
            'guest_name' => $a->booking?->guest_name,
            'actions' => [
                self::action('approve', 'Approve', 'primary'),
                // Web: the reject note is optional.
                self::action('reject', 'Reject', 'danger', needsReason: true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function heldItem(Booking $b, int $held, ?string $cancelledBy): array
    {
        $status = $b->booking_status === BookingStatus::NoShow ? 'No-show' : 'Cancelled';

        return [
            'id' => 'held_money:'.$b->id,
            'kind' => 'held_money',
            'title' => '₦'.number_format($held).' held on a '.mb_strtolower($status).' stay',
            'subtitle' => $status.' · '.$b->booking_ref.($b->room?->room_number ? ' · Rm '.$b->room->room_number : ''),
            'amount' => $held,
            'created_at' => $b->updated_at?->toIso8601String(),
            'requested_by' => $cancelledBy,
            'booking_ref' => (string) $b->booking_ref,
            'guest_name' => (string) $b->guest_name,
            'actions' => [
                self::action('refund', 'Refund', 'primary', needsAmount: true, needsMethod: true) + [
                    'max_amount' => $held,
                    'methods' => PaymentMethod::refundOptions(),
                ],
                self::action('keep', 'Keep as forfeit', 'secondary', needsReason: true) + ['max_amount' => $held],
            ],
        ];
    }

    public static function portalTitle(string $kind): string
    {
        if (str_starts_with($kind, 'request:')) {
            $sub = GuestRequestKind::tryFrom(substr($kind, 8));

            return 'Guest request: '.mb_strtolower($sub?->label() ?? str_replace('_', ' ', substr($kind, 8)));
        }

        return match ($kind) {
            'extend', 'extend_card' => 'Extend stay',
            'checkout' => 'Check out',
            'order_room', 'order_card' => 'Room service order',
            'reserve' => 'Reserve another room',
            'balance_card' => 'Pay balance by card',
            'checkin_no_payment' => 'Check in without payment',
            default => ucfirst(str_replace('_', ' ', $kind)),
        };
    }

    /**
     * @return array{value: string, label: string, tone: string, needs_reason: bool, needs_amount: bool, needs_method: bool}
     */
    private static function action(string $value, string $label, string $tone, bool $needsReason = false, bool $needsAmount = false, bool $needsMethod = false): array
    {
        return [
            'value' => $value,
            'label' => $label,
            'tone' => $tone,
            'needs_reason' => $needsReason,
            'needs_amount' => $needsAmount,
            'needs_method' => $needsMethod,
        ];
    }

    /**
     * Who cancelled / marked the no-show, from the activity log.
     *
     * @param  list<int>  $bookingIds
     * @return array<int, string>
     */
    private function cancelCausers(array $bookingIds): array
    {
        $out = [];
        $events = Activity::query()
            ->with('causer')
            ->where('subject_type', Booking::class)
            ->whereIn('subject_id', $bookingIds)
            ->whereIn('event', ['cancelled', 'no_show'])
            ->orderByDesc('id')
            ->get();
        foreach ($events as $e) {
            $id = (int) $e->subject_id;
            if (! isset($out[$id]) && $e->causer !== null) {
                $out[$id] = (string) ($e->causer->name ?? '');
            }
        }

        return $out;
    }
}
