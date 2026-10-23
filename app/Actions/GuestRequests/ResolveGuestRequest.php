<?php

declare(strict_types=1);

namespace App\Actions\GuestRequests;

use App\Models\GuestRequest;
use App\Models\Operator;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Mark a guest request resolved + send the best-effort WhatsApp
 * "your request has been sorted" notice. Shared by the web Guest
 * requests queue (single + bulk resolve) and the host app.
 */
class ResolveGuestRequest
{
    public function __construct(private readonly GuestNotifier $notifier) {}

    public function handle(GuestRequest $request, ?Operator $operator): GuestRequest
    {
        $request->loadMissing('booking.room.property');
        $request->forceFill([
            'status' => 'resolved',
            'resolved_by_operator_id' => $operator?->getKey(),
            'resolved_at' => Carbon::now(),
        ])->save();

        // Skips silently when the guest phone is missing or Sendpipe
        // isn't configured; the notifier catches delivery failures.
        $phone = (string) ($request->booking?->guest_phone ?? '');
        if ($phone !== '') {
            $property = $request->booking?->room?->property;
            $message = sprintf(
                "%s: your %s has been sorted. Reach out if there's anything else.",
                $property?->name ?? 'Reception',
                mb_strtolower($request->kind->label()),
            );
            try {
                $this->notifier->sendRequestResolvedNotice($phone, $message);
            } catch (Throwable) {
                // Notifier already catches + logs; nothing more here.
            }
        }

        return $request;
    }

    /** Undo a mistaken resolve (clears operator + timestamp). */
    public function reopen(GuestRequest $request): GuestRequest
    {
        $request->forceFill([
            'status' => 'open',
            'resolved_by_operator_id' => null,
            'resolved_at' => null,
        ])->save();

        return $request;
    }
}
