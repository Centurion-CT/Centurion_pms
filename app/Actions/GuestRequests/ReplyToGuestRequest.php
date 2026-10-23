<?php

declare(strict_types=1);

namespace App\Actions\GuestRequests;

use App\Models\GuestRequest;
use App\Models\GuestRequestMessage;
use App\Models\Operator;
use App\Services\GuestPortal\GuestNotifier;
use InvalidArgumentException;
use Throwable;

/**
 * Front desk replies on a guest request thread. Shared by the web
 * Guest requests panel and the host app (POST host/guest-requests/{id}/reply).
 *
 *  1. Writes the operator message on the portal thread (the guest sees
 *     it under "My requests" in the in-room portal).
 *  2. Nudges the guest on WhatsApp with the reply text, through the
 *     same best-effort GuestNotifier session-message path the resolve
 *     notice uses (no-op without a phone or when Sendpipe is unset;
 *     delivery failures never bubble).
 */
class ReplyToGuestRequest
{
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly GuestNotifier $notifier) {}

    public function handle(GuestRequest $request, ?Operator $operator, string $body): GuestRequestMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Write a reply first.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Keep the reply under '.self::MAX_LENGTH.' characters.');
        }

        $message = GuestRequestMessage::query()->create([
            'tenant_id' => $request->tenant_id,
            'guest_request_id' => $request->id,
            'sender_type' => 'operator',
            'sender_operator_id' => $operator?->getKey(),
            'message' => $body,
        ]);

        activity('guest_request')
            ->causedBy($operator)
            ->performedOn($request)
            ->event('message_sent')
            ->log("Operator replied on request {$request->id}.");

        $request->loadMissing('booking.room.property');
        $phone = (string) ($request->booking?->guest_phone ?? '');
        if ($phone !== '') {
            $property = $request->booking?->room?->property;
            try {
                $this->notifier->sendRequestResolvedNotice(
                    $phone,
                    sprintf('%s: %s', $property?->name ?? 'Reception', $body),
                );
            } catch (Throwable) {
                // Notifier already catches + logs delivery failures.
            }
        }

        return $message;
    }
}
