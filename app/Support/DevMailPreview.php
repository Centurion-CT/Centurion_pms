<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Carbon;

/**
 * Local-only helper that renders any Keverna mail template with hand-
 * seeded sample data. Skips the real Mailable classes (which need
 * hydrated Eloquent models bound to a tenant) and wraps each Blade
 * template in an anonymous Mailable so <x-mail::message> and friends
 * hydrate exactly like a real send. Used only from the /dev/mail/*
 * preview routes.
 */
final class DevMailPreview
{
    public static function render(string $template): string
    {
        $data = self::sample($template);
        if ($data === null) {
            abort(404, "No preview seeded for [{$template}].");
        }

        $mailable = new class($template, $data) extends Mailable {
            public function __construct(
                public string $template,
                public array $data,
            ) {}

            public function content(): Content
            {
                return new Content(markdown: $this->template, with: $this->data);
            }
        };

        return $mailable->render();
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function sample(string $template): ?array
    {
        $tenant = new class {
            public int $id = 1;
            public string $name = 'Grand Palace Abuja';
            public string $slug = 'grand-palace';
            public string $email = 'owner@grandpalace.test';
            public string $phone = '+234 809 555 0142';

            public function primaryHost(): string
            {
                return 'grand-palace.keverna.com';
            }
        };

        $property = new class {
            public string $name = 'Grand Palace Abuja';
        };

        $room = new class($property) {
            public string $name = 'Terrace Suite 4';

            public function __construct(public $property) {}
        };

        $booking = new class($tenant, $room) {
            public string $guest_name = 'Amaka Onwudiwe';
            public string $booking_ref = 'KV-7Q3W9K';
            public int $adults = 2;
            public int $children = 0;
            public int $nights = 2;
            public int $total_amount = 220_000;
            public int $deposit_amount = 110_000;
            public int $balance_due = 110_000;
            public ?string $special_requests = 'High floor if possible.';
            public Carbon $checkin_date;
            public Carbon $checkout_date;
            public Carbon $balance_due_at;

            public function __construct(public $tenant, public $room)
            {
                $this->checkin_date = Carbon::now()->addDays(5)->startOfDay();
                $this->checkout_date = Carbon::now()->addDays(7)->startOfDay();
                $this->balance_due_at = Carbon::now()->addDays(3)->startOfDay();
            }
        };

        $operator = new class {
            public string $name = 'Chinedu Ike';
            public string $email = 'chinedu@grandpalace.test';
        };

        $corporate = new class {
            public string $name = 'Human Capital Partners';
        };

        // Wallet stub that satisfies the two accessor-style calls used
        // in the corporate mails.
        $wallet = new class {
            public int $balance_naira = 480_000;
            public int $held_naira = 40_000;
            public int $low_balance_threshold_naira = 100_000;

            public function available(): int
            {
                return $this->balance_naira - $this->held_naira;
            }
        };

        return match ($template) {
            'mail.bookings.confirmed' => [
                'booking'   => $booking,
                'portalUrl' => 'https://'.$tenant->primaryHost().'/portal/'.$booking->booking_ref,
            ],
            'mail.bookings.balance-reminder' => [
                'booking'    => $booking,
                'daysBefore' => 3,
            ],
            'mail.bookings.on-hold' => [
                'booking'   => $booking,
                'portalUrl' => 'https://'.$tenant->primaryHost().'/portal/'.$booking->booking_ref,
            ],
            'mail.bookings.cancelled' => [
                'booking'         => $booking,
                'reason'          => 'Balance not received by the due date.',
                'depositForfeit'  => true,
            ],
            'mail.bookings.checkin-reminder' => [
                'booking' => $booking,
            ],
            'mail.bookings.review-prompt' => [
                'booking'   => $booking,
                'reviewUrl' => 'https://'.$tenant->primaryHost().'/portal/'.$booking->booking_ref.'/review',
            ],
            'mail.onboarding.email-otp' => [
                'operator' => $operator,
                'code'     => '482619',
            ],
            'mail.operators.welcome' => [
                'operator'     => $operator,
                'tenant'       => $tenant,
                'dashboardUrl' => 'https://'.$tenant->primaryHost().'/dashboard',
            ],
            'mail.operators.invite' => [
                'operator'    => (object) ['name' => 'Ngozi Bello', 'email' => 'ngozi@grandpalace.test'],
                'inviterName' => 'Chinedu Ike',
                'tenant'      => $tenant,
                'roleLabel'   => 'Manager',
                'magicLink'   => 'https://'.$tenant->primaryHost().'/invite/abcd1234',
            ],
            'mail.marketplace.stays-review-invite' => [
                'booking'      => $booking,
                'propertyName' => 'Grand Palace Abuja',
                'reviewUrl'    => 'https://keverna.com/stays/grand-palace/review/abcd',
            ],
            'mail.identity.guest-claim-invite' => [
                'propertyName' => 'Grand Palace Abuja',
                'claimUrl'     => 'https://keverna.com/claim/xyz',
            ],
            'mail.corporates.booking-confirmed' => [
                'booking'   => $booking,
                'member'    => (object) ['name' => 'Amaka Onwudiwe'],
                'room'      => $room,
                'corporate' => $corporate,
                'saving'    => 30_000,
            ],
            'mail.corporates.low-balance' => [
                'corporate' => $corporate,
                'wallet'    => $wallet,
            ],
            'mail.corporates.membership-paused' => [
                'corporate'  => $corporate,
                'member'     => (object) ['name' => 'Amaka Onwudiwe'],
                'membership' => (object) ['id' => 482, 'amount_naira' => 90_000],
                'shortfall'  => 45_000,
            ],
            'mail.corporates.topup-succeeded' => [
                'corporate' => $corporate,
                'wallet'    => $wallet,
                'topup'     => (object) [
                    'amount_naira' => 250_000,
                    'reference'    => 'ps-01JZK2M8H1QW3F',
                    'note'         => 'Q4 top-up',
                ],
            ],
            'mail.inventory.low-stock-digest' => [
                'tenant' => $tenant,
                'items'  => [
                    ['outlet' => 'Bar', 'name' => 'Chapman Mix', 'sku' => 'BAR-CHAP', 'on_hand' => 3, 'threshold' => 12],
                    ['outlet' => 'Kitchen', 'name' => 'Palm oil (5L)', 'sku' => 'KIT-PLM5', 'on_hand' => 1, 'threshold' => 4],
                    ['outlet' => 'Housekeeping', 'name' => 'Towels', 'sku' => null, 'on_hand' => 8, 'threshold' => 24],
                ],
            ],
            'mail.reports.summary-report' => [
                'tenant'       => $tenant,
                'periodLabel'  => 'August 2026',
                'periodRange'  => '1 Aug – 31 Aug',
                'stats'        => [
                    'bookings' => 68, 'nights_sold' => 142, 'gross_revenue' => 4_200_000,
                    'occupancy_pct' => 74, 'adr' => 29_580, 'cancellations' => 4,
                ],
                'dashboardUrl' => 'https://'.$tenant->primaryHost().'/dashboard',
            ],
            'mail.approvals.checkin-requested' => [
                'requesterName' => 'Chinedu Ike',
                'guestName'   => 'Amaka Onwudiwe',
                'bookingRef'  => 'KV-7Q3W9K',
                'roomLabel'   => 'Terrace Suite 4',
                'checkin'     => Carbon::now()->addDay()->toFormattedDateString(),
                'checkout'    => Carbon::now()->addDays(3)->toFormattedDateString(),
                'nights'      => 2,
                'totalNaira'  => 220_000,
                'approvalUrl' => 'https://'.$tenant->primaryHost().'/dashboard/approvals',
            ],
            'mail.subscriptions.renewal-reminder' => [
                'tenant'     => $tenant,
                'expiresAt'  => Carbon::now()->addDays(9),
                'daysBefore' => 9,
            ],
            'mail.marketplace.property-report-submitted' => [
                'propertyName'  => 'Grand Palace Abuja',
                'propertySlug'  => 'grand-palace-abuja',
                'propertyUrl'   => 'https://keverna.com/stays/grand-palace-abuja',
                'reason'        => 'suspicious pricing',
                'message'       => 'Rate looks like a decimal typo. The Deluxe Suite is listed at ₦2,500/night; I think it should be ₦25,000.',
                'reporterEmail' => 'guest@example.test',
            ],
            default => null,
        };
    }
}
