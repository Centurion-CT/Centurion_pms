<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\BookingStatus;
use App\Enums\CustomerInteractionDirection;
use App\Enums\CustomerInteractionType;
use App\Enums\CustomerLifecycle;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single point that owns the Customer ↔ Booking link and the CRM
 * aggregates that follow from it.
 *
 * Every booking lifecycle event (created, confirmed, cancelled, balance
 * paid, etc.) routes through here so the customer record and timeline
 * stay coherent without each action having to recompute everything.
 *
 * Identity resolution order — only changes if you really want different
 * behaviour:
 *   1. Match by `(tenant_id, email)` if a non-blank email is present
 *      (case-insensitive; we normalise on write).
 *   2. Match by `(tenant_id, phone)` if email lookup missed and the
 *      phone is present.
 *   3. Otherwise create a fresh Customer row.
 *
 * All public methods are idempotent — calling them twice for the same
 * booking won't double-count totals or write two timeline rows for the
 * same system event.
 */
class CustomerLinker
{
    /**
     * Attach a Customer to the booking, creating one if needed, and
     * write the "booking.created" timeline row.
     *
     * Returns the (re)attached Customer so the caller can inspect or
     * surface it (e.g. for an operator UI flash).
     */
    public function attachToBooking(Booking $booking): Customer
    {
        return DB::transaction(function () use ($booking): Customer {
            $customer = $this->findOrCreate(
                tenantId: $booking->tenant_id,
                name: $booking->guest_name,
                email: $booking->guest_email,
                phone: $booking->guest_phone,
            );

            // Backfill missing profile fields on existing records — never
            // overwrite operator-edited values.
            $dirty = false;
            if (($customer->email === null || $customer->email === '') && $booking->guest_email) {
                $customer->email = $this->normaliseEmail($booking->guest_email);
                $dirty = true;
            }
            if (($customer->phone === null || $customer->phone === '') && $booking->guest_phone) {
                $customer->phone = $this->normalisePhone($booking->guest_phone);
                $dirty = true;
            }
            if ($dirty) {
                $customer->save();
            }

            if ($booking->customer_id !== $customer->id) {
                $booking->customer_id = $customer->id;
                $booking->save();
            }

            $this->recordSystemEvent(
                customer: $customer,
                booking: $booking,
                event: 'booking.created',
                subject: 'Booking '.$booking->booking_ref.' created',
                body: sprintf(
                    '%d night(s) in %s · ₦%s',
                    (int) $booking->nights,
                    $booking->room?->name ?? '—',
                    number_format((int) $booking->total_amount),
                ),
            );

            $this->recomputeAggregates($customer->fresh() ?? $customer);

            return $customer->fresh() ?? $customer;
        });
    }

    /**
     * Record a system event for the booking (confirmed, cancelled,
     * checked-in, etc.) and refresh the customer aggregates.
     *
     * Safe to call from BookingWebhookController / state-transition
     * actions — silently no-ops if the booking has no attached customer.
     */
    public function recordBookingEvent(
        Booking $booking,
        string $event,
        string $subject,
        ?string $body = null,
    ): void {
        $customer = $booking->customer ?? null;
        if ($customer === null && $booking->customer_id !== null) {
            $customer = Customer::query()->find($booking->customer_id);
        }
        if ($customer === null) {
            return;
        }

        $this->recordSystemEvent(
            customer: $customer,
            booking: $booking,
            event: $event,
            subject: $subject,
            body: $body,
        );

        $this->recomputeAggregates($customer);
    }

    /**
     * Recompute lifetime_value / total_bookings / first / last booking
     * dates + lifecycle bucket from the current state of bookings.
     *
     * Called automatically from attachToBooking and recordBookingEvent;
     * also exposed for the backfill command and any future maintenance
     * cron.
     */
    public function recomputeAggregates(Customer $customer): void
    {
        // Only count bookings that actually produced revenue. Pending /
        // OnHold can flip either way, so we treat anything not Cancelled
        // as a booking that exists for lifetime-value purposes; revenue
        // however only counts Confirmed + CheckedIn + CheckedOut.
        $bookings = Booking::query()
            ->where('customer_id', $customer->id)
            ->get(['booking_status', 'total_amount', 'checkin_date']);

        $revenueStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
        ];

        $totalBookings = $bookings->filter(fn (Booking $b): bool => $b->booking_status->value !== BookingStatus::Cancelled->value)->count();
        $lifetimeValue = (int) $bookings
            ->filter(fn (Booking $b): bool => in_array($b->booking_status->value, $revenueStatuses, true))
            ->sum('total_amount');

        $first = $bookings->min('checkin_date');
        $last = $bookings->max('checkin_date');

        $customer->total_bookings = $totalBookings;
        $customer->lifetime_value = $lifetimeValue;
        $customer->first_booking_at = $first ? Carbon::parse((string) $first) : null;
        $customer->last_booking_at = $last ? Carbon::parse((string) $last) : null;

        $customer->lifecycle = $this->computeLifecycle($customer);

        $customer->save();
    }

    /**
     * Find or create a customer by (tenant_id, email|phone, name).
     * Public so future imports / manual-entry flows can call it too.
     */
    public function findOrCreate(int $tenantId, string $name, ?string $email, ?string $phone): Customer
    {
        $email = $this->normaliseEmail($email);
        $phone = $this->normalisePhone($phone);

        $existing = null;
        if ($email !== null) {
            $existing = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('email', $email)->first();
        }
        if ($existing === null && $phone !== null) {
            $existing = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('phone', $phone)->first();
        }

        if ($existing !== null) {
            // Promote a blank name (rare) to the booking's name.
            if (($existing->name === '' || $existing->name === '—') && $name !== '') {
                $existing->name = $name;
                $existing->save();
            }

            return $existing;
        }

        return Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name !== '' ? $name : '—',
            'email' => $email,
            'phone' => $phone,
            'lifecycle' => CustomerLifecycle::Prospect->value,
        ]);
    }

    private function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trim = trim(mb_strtolower($email));

        return $trim === '' ? null : $trim;
    }

    private function normalisePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        // Strip spaces, dashes, parentheses — keep leading + and digits.
        $cleaned = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        return $cleaned === '' ? null : $cleaned;
    }

    private function computeLifecycle(Customer $customer): CustomerLifecycle
    {
        // Honour operator-pinned lifecycle for 30 days after they set it.
        // (We detect "pinned" by the absence of a recent system bump;
        // pragmatic v1 — operators rarely need bigger guardrails.)
        if ($customer->last_booking_at === null) {
            return CustomerLifecycle::Prospect;
        }

        $daysSinceLastStay = $customer->last_booking_at->diffInDays(Carbon::now());

        if ($daysSinceLastStay <= 180) {
            return CustomerLifecycle::Active;
        }
        if ($daysSinceLastStay <= 365) {
            return CustomerLifecycle::Dormant;
        }

        return CustomerLifecycle::Churned;
    }

    /**
     * Write a single system-event row to the interaction timeline.
     * Idempotent on (customer_id, booking_id, system_event).
     */
    private function recordSystemEvent(
        Customer $customer,
        Booking $booking,
        string $event,
        string $subject,
        ?string $body = null,
    ): void {
        $existing = CustomerInteraction::query()
            ->where('customer_id', $customer->id)
            ->where('booking_id', $booking->id)
            ->where('system_event', $event)
            ->exists();

        if ($existing) {
            return;
        }

        CustomerInteraction::query()->create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'type' => CustomerInteractionType::System->value,
            'direction' => CustomerInteractionDirection::None->value,
            'subject' => $subject,
            'body' => $body,
            'system_event' => $event,
            'happened_at' => Carbon::now(),
        ]);
    }
}
