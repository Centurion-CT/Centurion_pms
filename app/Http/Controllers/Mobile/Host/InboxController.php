<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Actions\Approvals\AlreadyDecidedException;
use App\Actions\Approvals\DecideGuestActionApproval;
use App\Actions\Bookings\ForfeitHeldBookingMoney;
use App\Actions\Bookings\HeldMoneyRefundException;
use App\Actions\Bookings\RefundHeldBookingMoney;
use App\Enums\GuestRequestKind;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Services\Bookings\HeldMoney;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Host app inbox (docs/host-app/PHASE-3.md, 19): badge counts, the
 * approvals list and the decide endpoint. Every decision runs the same
 * shared action the web page uses; decisions are race-safe (the
 * action re-reads the row under a lock) and a lost race is
 * 409 `already_decided`.
 */
class InboxController extends HostController
{
    public function __construct(private readonly ApprovalInbox $inbox) {}

    public function counts(Request $request, NotificationFeed $feed): JsonResponse
    {
        $operator = $this->operator($request);
        $property = $this->property($request);

        $requests = GuestRequest::query()
            ->where('status', 'open')
            ->whereHas('room', fn ($r) => $r->where('property_id', $property->id));
        $urgent = (clone $requests)->whereIn('kind', [GuestRequestKind::Complaint->value, GuestRequestKind::Transfer->value]);

        return response()->json(['data' => [
            'approvals' => $this->inbox->count($operator, $property),
            'requests_open' => $requests->count(),
            'requests_urgent' => $urgent->count(),
            'notifications_unread' => $feed->unreadCount($operator, (int) $property->id),
        ]]);
    }

    public function approvals(Request $request): JsonResponse
    {
        $operator = $this->operator($request);
        if (! self::capabilities($operator)['approve']) {
            return $this->forbidden();
        }

        return response()->json(['data' => $this->inbox->items($operator, $this->property($request))]);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:500'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'method' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $operator = $this->operator($request);
        $property = $this->property($request);
        $gates = ApprovalInbox::gates($operator);

        [$kind, $ref] = array_pad(explode(':', $id, 2), 2, '');
        if (! in_array($kind, ApprovalInbox::KINDS, true) || $ref === '') {
            return $this->notFound();
        }

        try {
            return match ($kind) {
                'portal_action' => $this->decidePortal($request, $ref, $data, $gates),
                'held_money' => $this->decideHeld($request, $ref, $data, $gates),
            };
        } catch (AlreadyDecidedException) {
            return $this->alreadyDecided();
        } catch (Throwable $e) {
            return $this->failed($e->getMessage());
        }
    }

    // ── portal_action ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $gates
     */
    private function decidePortal(Request $request, string $ref, array $data, array $gates): JsonResponse
    {
        if (! $gates['portal_action']) {
            return $this->forbidden();
        }
        $approval = ctype_digit($ref) ? GuestActionApproval::query()
            ->whereKey((int) $ref)
            ->whereHas('room', fn ($r) => $r->where('property_id', $this->property($request)->id))
            ->first() : null;
        if ($approval === null) {
            return $this->notFound();
        }
        if (! $approval->isPending()) {
            return $this->alreadyDecided();
        }

        $decide = app(DecideGuestActionApproval::class);
        $operator = $this->operator($request);
        $resolved = match ($data['action']) {
            'approve' => $decide->approve($approval, $operator),
            'reject' => $decide->reject($approval, $operator, $data['reason'] ?? $data['note'] ?? null),
            default => null,
        };
        if ($resolved === null) {
            return $this->failed('Unknown action for this item.');
        }
        if ($data['action'] === 'approve' && $resolved->status !== 'approved') {
            return $this->failed('Booking no longer exists, the request was closed.');
        }

        return $this->ok(null, $data['action'] === 'approve' ? DecideGuestActionApproval::approvedMessage($approval->kind) : null);
    }

    // ── held_money ───────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $gates
     */
    private function decideHeld(Request $request, string $ref, array $data, array $gates): JsonResponse
    {
        if (! $gates['held_money']) {
            return $this->forbidden();
        }
        $heldMoney = app(HeldMoney::class);
        $property = $this->property($request);
        $booking = ctype_digit($ref) ? Booking::query()
            ->whereKey((int) $ref)
            ->whereIn('booking_status', array_map(fn ($s) => $s->value, HeldMoney::HELD_STATUSES))
            ->whereHas('room', fn ($r) => $r->where('property_id', $property->id))
            ->first() : null;
        if ($booking === null) {
            return $this->notFound();
        }
        if ($heldMoney->heldFor($booking) <= 0) {
            return $this->alreadyDecided();
        }
        $operator = $this->operator($request);

        switch ($data['action']) {
            case 'refund':
                if (empty($data['amount'])) {
                    return $this->failed('Enter the amount to refund.');
                }
                try {
                    app(RefundHeldBookingMoney::class)->handle(
                        $booking, $operator, (int) $data['amount'], (string) ($data['method'] ?? ''), $data['note'] ?? $data['reason'] ?? null,
                    );
                } catch (HeldMoneyRefundException $e) {
                    if ($e->reason === 'nothing_held') {
                        return $this->alreadyDecided();
                    }

                    return $this->failed($e->getMessage());
                }
                break;
            case 'keep':
                // Web "Keep as forfeit": reason required; the app may
                // omit the amount to keep everything still held.
                $amount = (int) ($data['amount'] ?? $heldMoney->heldFor($booking));
                app(ForfeitHeldBookingMoney::class)->handle($booking, $operator, $amount, (string) ($data['reason'] ?? $data['note'] ?? ''));
                break;
            default:
                return $this->failed('Unknown action for this item.');
        }

        // A partial refund leaves money held: return the updated item.
        $remaining = $heldMoney->heldFor($booking);
        $item = $remaining > 0
            ? $this->inbox->heldItem($booking->fresh(['room']), $remaining, null)
            : null;

        return $this->ok($item);
    }

    // ── Responses ────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $item
     */
    private function ok(?array $item, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'status' => 'ok',
            'item' => $item,
            'message' => $message,
        ], fn ($v, $k) => $k === 'item' || $v !== null, ARRAY_FILTER_USE_BOTH));
    }

    private function failed(string $detail): JsonResponse
    {
        return response()->json(['error' => 'decision_failed', 'detail' => $detail], 422);
    }

    private function alreadyDecided(): JsonResponse
    {
        return response()->json([
            'error' => 'already_decided',
            'detail' => 'Someone else already decided this.',
        ], 409);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'detail' => 'Approval not found in this branch.'], 404);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'forbidden', 'detail' => 'Your role can not do this.'], 403);
    }
}
