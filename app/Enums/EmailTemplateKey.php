<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five system events the operator can customise. Each case
 * carries its own default subject + body — those defaults are what
 * the booking engine sends when no tenant override exists. Operators
 * editing a template can always "Reset to default" to come back here.
 *
 * Body content is plain-text with double-brace placeholders the
 * renderer substitutes ({{ guest_name }}, {{ booking_ref }}, …). The
 * full variable set is exposed via {@see availableVariables()} so the
 * editor's variable picker stays in sync with what the renderer
 * supports.
 */
enum EmailTemplateKey: string
{
    case BookingConfirmed = 'booking.confirmed';
    case BookingOnHold    = 'booking.on_hold';
    case BookingCancelled = 'booking.cancelled';
    case BalanceReminder  = 'balance.reminder';
    case CheckinReminder  = 'checkin.reminder';

    public function label(): string
    {
        return match ($this) {
            self::BookingConfirmed => 'Booking confirmed',
            self::BookingOnHold    => 'Booking on hold (deposit only)',
            self::BookingCancelled => 'Booking cancelled',
            self::BalanceReminder  => 'Balance reminder',
            self::CheckinReminder  => 'Check-in reminder',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BookingConfirmed => 'Sent when a full-payment booking is confirmed by Paystack.',
            self::BookingOnHold    => 'Sent when only the deposit clears, with balance due later.',
            self::BookingCancelled => 'Sent when the booking is cancelled by the operator or guest.',
            self::BalanceReminder  => 'Sent ahead of the balance-due deadline.',
            self::CheckinReminder  => 'Sent the day before / morning of check-in.',
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::BookingConfirmed => 'Booking confirmed — {{ booking_ref }}',
            self::BookingOnHold    => 'Deposit received — {{ booking_ref }}',
            self::BookingCancelled => 'Your booking has been cancelled — {{ booking_ref }}',
            self::BalanceReminder  => 'Balance reminder — {{ booking_ref }}',
            self::CheckinReminder  => 'See you soon — {{ booking_ref }}',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::BookingConfirmed => <<<TXT
                Hi {{ guest_name }},

                We've confirmed your booking at {{ property_name }} — see you on {{ checkin_date }}.

                Reference: {{ booking_ref }}
                Room: {{ room_name }}
                Check-in: {{ checkin_date }}{{ checkin_time }}
                Check-out: {{ checkout_date }}{{ checkout_time }}
                Nights: {{ nights }}
                Total: ₦{{ total_amount }}

                {{ late_checkout_policy }}

                You can manage this booking from the portal: {{ portal_url }}

                Looking forward to hosting you.

                — The team at {{ property_name }}
                TXT,

            self::BookingOnHold => <<<TXT
                Hi {{ guest_name }},

                We've received your deposit and your room is held — {{ booking_ref }}.

                Room: {{ room_name }}
                Check-in: {{ checkin_date }}
                Check-out: {{ checkout_date }}

                Balance due on or before {{ balance_due_at }}: ₦{{ balance_due }}

                Settle the balance any time from your portal: {{ portal_url }}

                — The team at {{ property_name }}
                TXT,

            self::BookingCancelled => <<<TXT
                Hi {{ guest_name }},

                Your booking {{ booking_ref }} at {{ property_name }} has been cancelled.

                If a refund was requested it will be processed via Paystack within a few business days.

                Reach out if anything's unclear — we're here to help.

                — The team at {{ property_name }}
                TXT,

            self::BalanceReminder => <<<TXT
                Hi {{ guest_name }},

                A friendly reminder — the balance for your stay at {{ property_name }} is due on {{ balance_due_at }}.

                Reference: {{ booking_ref }}
                Balance due: ₦{{ balance_due }}

                Settle now from the portal: {{ portal_url }}

                Thanks!

                — The team at {{ property_name }}
                TXT,

            self::CheckinReminder => <<<TXT
                Hi {{ guest_name }},

                Quick reminder — check-in is on {{ checkin_date }} at {{ property_name }}.

                Reference: {{ booking_ref }}
                Room: {{ room_name }}

                Address: {{ property_address }}

                Any change of plans? Get in touch via the portal: {{ portal_url }}

                See you soon!

                — The team at {{ property_name }}
                TXT,
        };
    }

    /**
     * Variables the renderer supports. Each entry: [token, label].
     * Drives the variable-picker chips in the editor — clicking a chip
     * inserts the corresponding `{{ token }}` at the cursor.
     *
     * @return array<int, array{token: string, label: string}>
     */
    public static function availableVariables(): array
    {
        return [
            ['token' => 'guest_name',        'label' => 'Guest name'],
            ['token' => 'guest_email',       'label' => 'Guest email'],
            ['token' => 'guest_phone',       'label' => 'Guest phone'],
            ['token' => 'booking_ref',       'label' => 'Booking reference'],
            ['token' => 'room_name',         'label' => 'Room name'],
            ['token' => 'checkin_date',      'label' => 'Check-in date'],
            ['token' => 'checkout_date',     'label' => 'Check-out date'],
            ['token' => 'checkin_time',      'label' => 'Check-in window'],
            ['token' => 'checkout_time',     'label' => 'Check-out window'],
            ['token' => 'late_checkout_policy', 'label' => 'Late check-out policy'],
            ['token' => 'nights',            'label' => 'Number of nights'],
            ['token' => 'total_amount',      'label' => 'Total amount'],
            ['token' => 'balance_due',       'label' => 'Balance due'],
            ['token' => 'balance_due_at',    'label' => 'Balance due date'],
            ['token' => 'property_name',     'label' => 'Property name'],
            ['token' => 'property_address',  'label' => 'Property address'],
            ['token' => 'portal_url',        'label' => 'Guest portal URL'],
        ];
    }
}
