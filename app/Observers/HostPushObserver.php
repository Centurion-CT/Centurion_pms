<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Payment;
use App\Services\Push\HostPush;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Host push triggers (docs/host-app/PHASE-5.md) on the rows that are
 * the single source of truth, so every code path (web, host app, staff
 * app, guest portal, Paystack webhook, OTA import) is covered once:
 *
 *  Booking   created active / pending -> active   => bookings
 *            -> cancelled / no-show               => approvals (held money)
 *  Payment   succeeded guest payment               => payments
 *  GuestActionApproval created pending             => approvals (portal hold)
 *  GuestRequest created                            => guest_requests
 *
 * Never throws into the caller.
 */
class HostPushObserver
{
    private const ACTIVE = ['confirmed', 'on_hold', 'checked_in'];

    private const PENDING = ['pending', 'pending_corp_approval'];

    public function created(Model $model): void
    {
        $this->safely(function () use ($model): void {
            $push = app(HostPush::class);

            match (true) {
                $model instanceof Booking => in_array(self::status($model->booking_status), self::ACTIVE, true)
                    ? $push->bookingActivated($model) : null,
                $model instanceof Payment => $model->status === PaymentStatus::Succeeded
                    ? $push->paymentSucceeded($model) : null,
                $model instanceof GuestActionApproval => $model->status === 'pending'
                    ? $push->portalApproval($model) : null,
                $model instanceof GuestRequest => $push->guestRequest($model),
                default => null,
            };
        });
    }

    public function updated(Model $model): void
    {
        $this->safely(function () use ($model): void {
            if ($model instanceof Booking && $model->wasChanged('booking_status')) {
                $from = (string) $model->getRawOriginal('booking_status');
                $to = self::status($model->booking_status);
                if (in_array($to, self::ACTIVE, true) && in_array($from, self::PENDING, true)) {
                    app(HostPush::class)->bookingActivated($model);
                } elseif (in_array($to, [BookingStatus::Cancelled->value, BookingStatus::NoShow->value], true)) {
                    app(HostPush::class)->bookingClosed($model);
                }

                return;
            }

            if ($model instanceof Payment
                && $model->wasChanged('status')
                && $model->status === PaymentStatus::Succeeded
                && (string) $model->getRawOriginal('status') !== PaymentStatus::Succeeded->value) {
                app(HostPush::class)->paymentSucceeded($model);
            }
        });
    }

    private static function status(mixed $status): string
    {
        return $status instanceof BackedEnum ? (string) $status->value : (string) $status;
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
