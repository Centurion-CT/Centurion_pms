<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Mobile\Host\ApprovalInbox;
use App\Http\Controllers\Mobile\Host\HostController;
use App\Http\Middleware\EnsureHostAppOperator;
use App\Jobs\SendHostPush;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Property;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Observers\HostPushObserver;
use App\Services\Bookings\HeldMoney;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Host app push notifications (docs/host-app/PHASE-5.md).
 *
 * Triggers come from {@see HostPushObserver} (one hook
 * per source-of-truth model, so two code paths that call each other
 * still produce one push), are buffered until the DB transaction
 * commits, then handed to the {@see SendHostPush} job which rebuilds the
 * message from fresh rows, picks the recipients and calls the driver.
 *
 * Recipients pass the same gates the app uses to show the thing:
 * active `app=host` device with a token, host role that can still sign
 * in, branch access, the per-kind gate (owner, approval queue, module),
 * the device's preference, never the operator who did it, and quiet
 * hours (22:00 to 07:00 Lagos: only urgent pushes).
 */
final class HostPush
{
    /** Preference keys (also the message `kind`). */
    public const PREFS = ['bookings', 'payments', 'approvals', 'guest_requests', 'daily_summary', 'low_stock'];

    public const QUIET_TZ = 'Africa/Lagos';

    public const QUIET_FROM = 22;

    public const QUIET_UNTIL = 7;

    /** Statuses that mean "a real booking now exists" (not a pending hold). */
    private const ACTIVE = [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn];

    /** Guest money types: room, deposit, balance, invoice, extras. Not POS sales or corporate AR mirrors. */
    private const GUEST_PAYMENT_TYPES = [PaymentType::Full, PaymentType::Deposit, PaymentType::Balance, PaymentType::Invoice, PaymentType::Extra];

    /** @var array<int, array{tenant: int, actor: ?int}> booking id => trigger */
    private array $bookings = [];

    /** @var array<int, array{tenant: int, actor: ?int, ids: list<int>}> booking id => succeeded payments */
    private array $payments = [];

    /** @var list<array{tenant: int, event: string, payload: array<string, mixed>, actor: ?int}> */
    private array $events = [];

    public static function makeDriver(): PushDriver
    {
        $driver = strtolower(trim((string) config('services.push.driver', '')));
        if ($driver === '') {
            $driver = config('services.fcm.project_id') && config('services.fcm.credentials') ? 'fcm' : 'null';
        }

        return match ($driver) {
            'fcm' => FcmDriver::fromConfig(),
            'log' => new LogDriver,
            default => new NullDriver,
        };
    }

    public function driver(): PushDriver
    {
        return app(PushDriver::class);
    }

    public function available(): bool
    {
        return $this->driver()->available();
    }

    // ── Preferences ─────────────────────────────────────────────

    /**
     * Stored prefs over the defaults (all on).
     *
     * @return array<string, bool>
     */
    public static function prefs(MobileDevice $device): array
    {
        $stored = is_array($device->push_prefs) ? $device->push_prefs : [];
        $out = [];
        foreach (self::PREFS as $key) {
            $out[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : true;
        }

        return $out;
    }

    /**
     * Which kinds this operator can ever receive (the same gates the
     * app uses to show the thing).
     *
     * @return array<string, bool>
     */
    public static function allowed(Operator $operator): array
    {
        $caps = HostController::capabilities($operator);

        return [
            'bookings' => $caps['create_booking'],
            'payments' => $caps['owner'],
            'approvals' => in_array(true, ApprovalInbox::gates($operator), true),
            'guest_requests' => $operator->canAccessModule('reservations'),
            'daily_summary' => $caps['owner'],
            'low_stock' => $operator->canAccessModule('inventory'),
        ];
    }

    public static function isQuietHours(?Carbon $at = null): bool
    {
        $hour = (int) ($at ?? Carbon::now())->copy()->setTimezone(self::QUIET_TZ)->format('G');

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL;
    }

    // ── Triggers (called by HostPushObserver) ───────────────────

    /** A booking became real: created confirmed / on hold / checked in, or left a pending state. */
    public function bookingActivated(Booking $booking): void
    {
        if (! $this->worthIt((int) $booking->tenant_id)) {
            return;
        }
        $this->bookings[(int) $booking->id] ??= ['tenant' => (int) $booking->tenant_id, 'actor' => self::actorId()];
        $this->flushAfterCommit();
    }

    /** Cancelled / no-show: may now hold money that needs a refund-or-keep decision. */
    public function bookingClosed(Booking $booking): void
    {
        $this->event((int) $booking->tenant_id, 'approval', ['queue' => 'held_money', 'id' => (int) $booking->id]);
    }

    public function paymentSucceeded(Payment $payment): void
    {
        if ($payment->booking_id === null
            || (int) $payment->amount <= 0
            || $payment->method === 'wallet'
            || ! in_array($payment->type, self::GUEST_PAYMENT_TYPES, true)
            || ! $this->worthIt((int) $payment->tenant_id)) {
            return;
        }
        $bid = (int) $payment->booking_id;
        $this->payments[$bid] ??= ['tenant' => (int) $payment->tenant_id, 'actor' => self::actorId(), 'ids' => []];
        $this->payments[$bid]['ids'][] = (int) $payment->id;
        $this->flushAfterCommit();
    }

    public function portalApproval(GuestActionApproval $approval): void
    {
        $payload = is_array($approval->payload) ? $approval->payload : [];
        $requestedBy = isset($payload['requested_by_operator_id']) ? (int) $payload['requested_by_operator_id'] : null;
        $this->event((int) $approval->tenant_id, 'approval', ['queue' => 'portal_action', 'id' => (int) $approval->id], $requestedBy);
    }

    public function guestRequest(GuestRequest $request): void
    {
        $this->event((int) $request->tenant_id, 'guest_request', ['id' => (int) $request->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(int $tenantId, string $event, array $payload, ?int $actor = null): void
    {
        if (! $this->worthIt($tenantId)) {
            return;
        }
        $this->events[] = ['tenant' => $tenantId, 'event' => $event, 'payload' => $payload, 'actor' => $actor ?? self::actorId()];
        $this->flushAfterCommit();
    }

    /** Cheap pre-check so tenants without host phones never queue anything. */
    private function worthIt(int $tenantId): bool
    {
        if ($tenantId <= 0 || ! $this->available()) {
            return false;
        }

        return self::deviceQuery($tenantId)->exists();
    }

    private function flushAfterCommit(): void
    {
        // Outside a transaction this runs at once; inside one, every row
        // of the transaction is in the buffers by the time it runs, so a
        // settle that writes one Payment per charge sends one push.
        DB::afterCommit(fn () => $this->flush());
    }

    public function flush(): void
    {
        $bookings = $this->bookings;
        $payments = $this->payments;
        $events = $this->events;
        $this->bookings = $this->payments = $this->events = [];

        foreach ($bookings as $id => $b) {
            $this->dispatch($b['tenant'], 'booking', ['id' => $id], $b['actor']);
        }
        foreach ($payments as $bookingId => $p) {
            $this->dispatch($p['tenant'], 'payment', ['booking_id' => $bookingId, 'ids' => $p['ids']], $p['actor']);
        }
        foreach ($events as $e) {
            $this->dispatch($e['tenant'], $e['event'], $e['payload'], $e['actor']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(int $tenantId, string $event, array $payload, ?int $actor): void
    {
        $previous = Tenant::current();
        try {
            // The queue is tenant-aware: dispatch with the trigger's
            // tenant current (a central webhook has none).
            if ($previous === null || (int) $previous->id !== $tenantId) {
                $tenant = Tenant::query()->find($tenantId);
                if ($tenant === null) {
                    return;
                }
                $tenant->makeCurrent();
            }
            SendHostPush::dispatch($tenantId, $event, $payload, $actor);
        } catch (Throwable $e) {
            report($e);
        } finally {
            $now = Tenant::current();
            if ($previous === null) {
                if ($now !== null) {
                    Tenant::forgetCurrent();
                }
            } elseif ($now === null || (int) $now->id !== (int) $previous->id) {
                $previous->makeCurrent();
            }
        }
    }

    private static function actorId(): ?int
    {
        try {
            $user = Auth::user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Operator ? (int) $user->id : null;
    }

    // ── Job side: build + deliver (tenant is current) ───────────

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Tenant $tenant, string $event, array $payload, ?int $actor): int
    {
        return match ($event) {
            'booking' => $this->sendBooking($tenant, (int) $payload['id'], $actor),
            'payment' => $this->sendPayment($tenant, array_map('intval', (array) ($payload['ids'] ?? [])), $actor),
            'approval' => $this->sendApproval($tenant, (string) $payload['queue'], (int) $payload['id'], $actor),
            'guest_request' => $this->sendGuestRequest($tenant, (int) $payload['id'], $actor),
            default => 0,
        };
    }

    private function sendBooking(Tenant $tenant, int $id, ?int $actor): int
    {
        $b = Booking::query()->with(['room.category', 'roomCategory'])->find($id);
        if ($b === null || ! in_array($b->booking_status, self::ACTIVE, true)) {
            return 0;
        }
        $property = self::bookingProperty($b);

        $title = match (true) {
            $b->source?->value === 'online' && Str::contains((string) $b->source_note, 'Stays') => 'New online booking',
            $b->source?->value === 'online' => 'New booking from your website',
            $b->source?->value === 'walk_in' => 'New walk-in booking',
            in_array($b->source?->value, ['booking_com', 'airbnb', 'hotels_ng', 'jumia_travel'], true) => 'New booking from '.$b->source->label(),
            default => 'New booking',
        };
        $body = implode(' · ', array_filter([
            (string) $b->guest_name,
            $b->room?->category?->name ?? $b->roomCategory?->name,
            self::dateRange($b->checkin_date, $b->checkout_date),
            PushMessage::naira((int) $b->total_amount),
        ]));

        $message = new PushMessage('bookings', $title, $body, 'booking:'.$b->booking_ref, $property?->id, (string) $tenant->slug, (string) $b->booking_ref);

        return $this->deliver($tenant, $message, $property, $actor, fn (Operator $op) => HostController::capabilities($op)['create_booking']);
    }

    /**
     * @param  list<int>  $ids
     */
    private function sendPayment(Tenant $tenant, array $ids, ?int $actor): int
    {
        $rows = Payment::query()
            ->whereIn('id', $ids)
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('amount', '>', 0)
            ->get()
            ->filter(fn (Payment $p) => $p->method !== 'wallet');
        if ($rows->isEmpty()) {
            return 0;
        }
        $b = Booking::query()->with('room')->find((int) $rows->first()->booking_id);
        if ($b === null) {
            return 0;
        }
        $property = self::bookingProperty($b);
        $total = (int) $rows->sum('amount');
        $methods = $rows->pluck('method')->map(fn ($m) => (string) $m)->unique()->values();
        $collectors = $rows->pluck('collected_by_operator_id')->filter()->unique()->values();
        $actor ??= $collectors->count() === 1 ? (int) $collectors->first() : null;

        $body = implode(' · ', array_filter([
            trim($b->guest_name.' '.($methods->count() === 1 ? self::paidBy($methods->first()) : 'paid')),
            $b->room?->room_number ? 'Room '.$b->room->room_number : null,
        ]));
        $message = new PushMessage(
            'payments',
            'Payment received: '.PushMessage::naira($total),
            $body,
            'booking:'.$b->booking_ref,
            $property?->id,
            (string) $tenant->slug,
            (string) $rows->max('id'),
        );

        return $this->deliver($tenant, $message, $property, $actor, fn (Operator $op) => HostController::isOwner($op));
    }

    private function sendApproval(Tenant $tenant, string $queue, int $id, ?int $actor): int
    {
        /** @var ApprovalInbox $inbox */
        $inbox = app(ApprovalInbox::class);
        $property = null;
        $item = null;
        $urgent = false;

        switch ($queue) {
            case 'portal_action':
                $a = GuestActionApproval::query()->with(['booking', 'room'])->find($id);
                if ($a === null || $a->status !== 'pending') {
                    return 0;
                }
                $property = $a->room?->property_id ? Property::query()->find($a->room->property_id) : null;
                $item = $inbox->portalItem($a);
                // A guest (or the guest at the desk) is waiting on it.
                $urgent = true;
                break;
            case 'held_money':
                $b = Booking::query()->with('room')->find($id);
                $property = $b !== null ? self::bookingProperty($b) : null;
                if ($b === null || $property === null) {
                    return 0;
                }
                $held = app(HeldMoney::class);
                if ($held->pendingBookingIds((int) $property->id, null, fn ($q) => $q->whereKey($b->id)) === []) {
                    return 0;
                }
                $item = $inbox->heldItem($b, (int) ($held->byBooking([$b->id])[$b->id]['held'] ?? 0), null);
                break;
            default:
                return 0;
        }
        if ($item === null || $property === null) {
            return 0;
        }

        $who = $item['guest_name'] ?? null;
        $message = new PushMessage(
            'approvals',
            'Approval needed: '.$item['title'],
            $who !== null && $who !== '' ? $who.' · '.$item['subtitle'] : (string) $item['subtitle'],
            'approvals',
            $property?->id,
            (string) $tenant->slug,
            (string) $item['id'],
            $urgent,
        );
        $gateKey = $queue === 'portal_action' ? 'portal_action' : $queue;

        return $this->deliver($tenant, $message, $property, $actor, fn (Operator $op) => (bool) (ApprovalInbox::gates($op)[$gateKey] ?? false));
    }

    private function sendGuestRequest(Tenant $tenant, int $id, ?int $actor): int
    {
        $r = GuestRequest::query()->with(['booking', 'room'])->find($id);
        if ($r === null || ! $r->isOpen()) {
            return 0;
        }
        $property = $r->room?->property_id ? Property::query()->find($r->room->property_id) : null;
        if ($property === null) {
            return 0;
        }
        $room = $r->room?->room_number ? ' from Room '.$r->room->room_number : '';
        $notes = trim((string) ($r->notes ?? ''));
        $body = implode(': ', array_filter([
            $r->booking?->guest_name,
            $notes !== '' ? Str::limit($notes, 140) : $r->kind->label(),
        ]));

        $message = new PushMessage(
            'guest_requests',
            $r->kind->label().$room,
            $body,
            'request:'.$r->id,
            (int) $property->id,
            (string) $tenant->slug,
            (string) $r->id,
            $r->kind->isUrgent(),
        );

        return $this->deliver($tenant, $message, $property, $actor, fn (Operator $op) => $op->canAccessModule('reservations'));
    }

    /**
     * Low-stock piggyback on the daily digest: one push per branch per
     * day, only for branches with something at or under its threshold.
     * Runs inside the digest command (already off the request path).
     *
     * @param  iterable<Product>  $products  low products of this tenant (outlet + store loaded)
     * @return int devices sent to
     */
    public function lowStock(Tenant $tenant, iterable $products): int
    {
        if (! $this->available() || ! self::deviceQuery((int) $tenant->id)->exists()) {
            return 0;
        }

        $byProperty = [];
        foreach ($products as $p) {
            $pid = (int) ($p->outlet?->property_id ?? $p->store?->property_id ?? 0);
            if ($pid > 0) {
                $byProperty[$pid][] = (string) $p->name;
            }
        }

        $previous = Tenant::current();
        $tenant->makeCurrent();
        $sent = 0;
        try {
            $today = Carbon::today()->toDateString();
            foreach ($byProperty as $pid => $names) {
                $property = Property::query()->find($pid);
                if ($property === null) {
                    continue;
                }
                if (! Cache::add('host-push:low-stock:'.$tenant->id.':'.$pid.':'.$today, 1, Carbon::now()->addHours(36))) {
                    continue;
                }
                sort($names);
                $count = count($names);
                $shown = array_slice($names, 0, 3);
                $list = implode(', ', $shown).($count > 3 ? ' and '.($count - 3).' more' : '');
                $message = new PushMessage(
                    'low_stock',
                    'Low stock at '.$property->name,
                    $count.' '.($count === 1 ? 'item is' : 'items are').' running low: '.$list,
                    '/dashboard/inventory',
                    (int) $property->id,
                    (string) $tenant->slug,
                    $pid.':'.$today,
                );
                $sent += $this->deliver($tenant, $message, $property, null, fn (Operator $op) => $op->canAccessModule('inventory'));
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            if ($previous !== null) {
                $previous->makeCurrent();
            } else {
                Tenant::forgetCurrent();
            }
        }

        return $sent;
    }

    // ── Delivery ────────────────────────────────────────────────

    /**
     * Sends $message to every eligible device. Returns the operators reached.
     *
     * @param  Closure(Operator): bool  $gate
     * @return int devices sent to
     */
    public function deliver(Tenant $tenant, PushMessage $message, ?Property $property, ?int $actor, Closure $gate): int
    {
        if (! $message->urgent && self::isQuietHours()) {
            return 0;
        }

        $sent = 0;
        foreach ($this->recipients((int) $tenant->id, $message->kind, $property, $actor, $gate) as $device) {
            if ($this->sendToDevice($device, $message) === PushResult::Sent) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Eligible devices for one kind (prefs, role, branch, gate, actor).
     *
     * @param  Closure(Operator): bool  $gate
     * @return Collection<int, MobileDevice>
     */
    public function recipients(int $tenantId, string $kind, ?Property $property, ?int $actor, Closure $gate): Collection
    {
        $devices = self::deviceQuery($tenantId)->with(['operator' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])->get();

        $ok = [];

        return $devices->filter(function (MobileDevice $d) use (&$ok, $kind, $property, $actor, $gate): bool {
            $op = $d->operator;
            if ($op === null || (int) $op->tenant_id !== (int) $d->tenant_id) {
                return false;
            }
            if (! array_key_exists($op->id, $ok)) {
                $ok[$op->id] = in_array($op->role, EnsureHostAppOperator::ALLOWED_ROLES, true)
                    && $op->canSignIn()
                    && ($actor === null || (int) $op->id !== $actor)
                    && ($property === null || $op->hasAccessToBranch($property))
                    && $gate($op);
            }

            return $ok[$op->id] && (self::prefs($d)[$kind] ?? true);
        })->values();
    }

    public function sendToDevice(MobileDevice $device, PushMessage $message): PushResult
    {
        try {
            $result = $this->driver()->send($message, (string) $device->push_token, (string) ($device->push_platform ?? $device->platform));
        } catch (Throwable $e) {
            report($e);

            return PushResult::Failed;
        }

        if ($result === PushResult::InvalidToken) {
            MobileDevice::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($device->id)
                ->where('push_token', $device->push_token)
                ->update(['push_token' => null, 'push_token_updated_at' => Carbon::now()]);
        }

        return $result;
    }

    /**
     * Active host-app devices with a push token on this tenant.
     *
     * @return Builder<MobileDevice>
     */
    public static function deviceQuery(int $tenantId)
    {
        return MobileDevice::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('app', MobileDevice::APP_HOST)
            ->whereNotNull('push_token')
            ->whereNull('revoked_at');
    }

    // ── Copy helpers ────────────────────────────────────────────

    public static function bookingProperty(Booking $b): ?Property
    {
        $pid = $b->room?->property_id ?? $b->roomCategory?->property_id;

        return $pid ? Property::query()->find($pid) : null;
    }

    /** "24 to 28 Oct", "30 Oct to 2 Nov". */
    public static function dateRange(?Carbon $in, ?Carbon $out): ?string
    {
        if ($in === null || $out === null) {
            return null;
        }

        return $in->format('Y-m') === $out->format('Y-m')
            ? $in->format('j').' to '.$out->format('j M')
            : $in->format('j M').' to '.$out->format('j M');
    }

    public static function paidBy(?string $method): string
    {
        return match ($method) {
            'cash' => 'paid in cash',
            'transfer' => 'paid by transfer',
            'card' => 'paid by card',
            'pos' => 'paid by POS',
            'paystack', null, '' => 'paid online',
            default => 'paid',
        };
    }
}
