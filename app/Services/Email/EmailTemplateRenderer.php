<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailTemplateKey;
use App\Models\Booking;
use App\Models\EmailTemplate;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;

/**
 * Resolves which subject + body to use for a given (tenant, key)
 * combo, performs `{{ token }}` substitution, and packages everything
 * the mailable needs into a {@see RenderedEmail}.
 *
 * Lookup order:
 *   1. tenant override row (email_templates) where active = true
 *   2. default subject + body from {@see EmailTemplateKey}
 *
 * Substitution is deliberately simple — `{{ token }}` (with or without
 * surrounding whitespace) is replaced literally. Unknown tokens are
 * left as-is so an operator who accidentally types `{{ random }}` gets
 * a visible cue rather than silent emptiness.
 */
class EmailTemplateRenderer
{
    /**
     * Render a templated email for a booking. The variables array
     * built from {@see contextFromBooking()} is the canonical set the
     * editor's preview pane reuses, so what operators see in the
     * preview matches what the mailable actually sends.
     */
    public function renderForBooking(
        EmailTemplateKey $key,
        Booking $booking,
        ?string $portalUrl = null,
    ): RenderedEmail {
        return $this->render(
            tenantId: (int) $booking->tenant_id,
            key: $key,
            variables: $this->contextFromBooking($booking, $portalUrl),
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function render(int $tenantId, EmailTemplateKey $key, array $variables): RenderedEmail
    {
        [$subject, $body, $isCustom] = $this->resolve($tenantId, $key);

        return new RenderedEmail(
            subject: $this->substitute($subject, $variables),
            body: $this->substitute($body, $variables),
            isCustom: $isCustom,
        );
    }

    /**
     * Variables every booking-scoped template can reference. Mirrors
     * {@see EmailTemplateKey::availableVariables()} so the editor's
     * picker stays in sync.
     *
     * @return array<string, string>
     */
    public function contextFromBooking(Booking $booking, ?string $portalUrl = null): array
    {
        $property = $booking->room?->property ?? $booking->tenant?->property;

        return [
            'guest_name'       => (string) $booking->guest_name,
            'guest_email'      => (string) $booking->guest_email,
            'guest_phone'      => (string) $booking->guest_phone,
            'booking_ref'      => (string) $booking->booking_ref,
            'room_name'        => (string) ($booking->room?->name ?? '—'),
            'checkin_date'     => $booking->checkin_date?->toFormattedDateString() ?? '',
            'checkout_date'    => $booking->checkout_date?->toFormattedDateString() ?? '',
            'checkin_time'     => $property !== null ? $this->formatCheckWindow($property, 'in') : '',
            'checkout_time'    => $property !== null ? $this->formatCheckWindow($property, 'out') : '',
            'late_checkout_policy' => $property !== null ? $this->formatLateCheckoutPolicy($property) : '',
            'nights'           => (string) ($booking->nights ?? 0),
            'total_amount'    => number_format((int) ($booking->total_amount ?? 0)),
            'balance_due'      => number_format((int) ($booking->balance_due ?? 0)),
            'balance_due_at'   => $booking->balance_due_at?->toFormattedDateString() ?? '',
            'property_name'    => (string) ($property?->name ?? ''),
            'property_address' => (string) ($property?->address ?? ''),
            'portal_url'       => (string) ($portalUrl ?? ''),
        ];
    }

    /**
     * Guest-friendly window like "2:00 PM — 6:00 PM" from the
     * property's house_rules. Returns '' when the operator hasn't set
     * either bound.
     */
    private function formatCheckWindow(\App\Models\Property $property, string $side): string
    {
        $rules = $property->houseRules();
        $from = $side === 'in' ? ($rules['check_in_from'] ?? '') : ($rules['check_out_from'] ?? '');
        $to = $side === 'in' ? ($rules['check_in_to'] ?? '') : ($rules['check_out_to'] ?? '');
        $fmt = static function (string $t): string {
            if ($t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t)) return '';
            try { return Carbon::createFromFormat('H:i', $t)->format('g:i A'); }
            catch (\Throwable) { return ''; }
        };
        $fromF = $fmt($from);
        $toF = $fmt($to);

        $core = match (true) {
            $fromF !== '' && $toF !== '' => "{$fromF} — {$toF}",
            $fromF !== '' => "from {$fromF}",
            $toF !== '' => "until {$toF}",
            default => '',
        };

        // Leading " · " so the token slots inline after a date without
        // producing "Check-in: Mar 5 · " when unset — an empty check
        // window returns '' with no separator baggage.
        return $core === '' ? '' : ' · '.$core;
    }

    /**
     * Plain-English late-checkout sentence including its own "Late
     * check-out: " label. Empty when the operator hasn't enabled the
     * policy — inclusion of the label inside the token means a
     * disabled policy leaves no orphan "Late check-out:" in the body.
     */
    private function formatLateCheckoutPolicy(\App\Models\Property $property): string
    {
        $policy = $property->checkoutPolicy();
        if (! $policy['late_checkout_enabled']) {
            return '';
        }
        $hourly = (int) $policy['late_checkout_hourly_fee'];
        $fullDay = (int) $policy['late_checkout_full_day_after_hours'];
        if ($hourly <= 0 && $fullDay <= 0) {
            return '';
        }

        $rules = $property->houseRules();
        $deadline = ($rules['check_out_to'] ?? '') !== ''
            ? (Carbon::createFromFormat('H:i', $rules['check_out_to'])?->format('g:i A') ?? $rules['check_out_to'])
            : 'the end of check-out';

        $parts = [];
        if ($hourly > 0) {
            $parts[] = '₦'.number_format($hourly).'/hour past '.$deadline
                .($policy['late_checkout_grace_minutes'] > 0
                    ? ' ('.$policy['late_checkout_grace_minutes'].'-min grace)'
                    : '')
                .'.';
        }
        if ($fullDay > 0) {
            $parts[] = 'Stay '.$fullDay.'+ hours late and the full room rate is charged.';
        }

        return 'Late check-out: '.implode(' ', $parts);
    }

    /**
     * Sample data used by the editor preview when no real booking
     * is in scope. Picks plausible-but-fake values for every token.
     *
     * @return array<string, string>
     */
    public function sampleContext(string $propertyName = 'Your Property', string $propertyAddress = ''): array
    {
        return [
            'guest_name'       => 'Amara Okafor',
            'guest_email'      => 'amara@example.com',
            'guest_phone'      => '+234 800 123 4567',
            'booking_ref'      => 'KV-7XQ2H9P',
            'room_name'        => 'Deluxe Suite · 102',
            'checkin_date'     => now()->addDays(3)->toFormattedDateString(),
            'checkout_date'    => now()->addDays(5)->toFormattedDateString(),
            'checkin_time'     => ' · 2:00 PM — 6:00 PM',
            'checkout_time'    => ' · until 11:00 AM',
            'late_checkout_policy' => '₦5,000/hour past 11:00 AM (30-min grace). Stay 6+ hours late and the full room rate is charged.',
            'nights'           => '2',
            'total_amount'     => '120,000',
            'balance_due'      => '60,000',
            'balance_due_at'   => now()->addDays(3)->toFormattedDateString(),
            'property_name'    => $propertyName,
            'property_address' => $propertyAddress !== '' ? $propertyAddress : '14 Aminu Kano Crescent, Wuse 2, Abuja',
            'portal_url'       => 'https://yourproperty.keverna.com/guest/booking/KV-7XQ2H9P?token=…',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: bool}  subject, body, isCustom
     */
    private function resolve(int $tenantId, EmailTemplateKey $key): array
    {
        $override = EmailTemplate::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('key', $key->value)
            ->where('active', true)
            ->first();

        if ($override !== null && $override->subject !== '' && $override->body !== '') {
            return [$override->subject, $override->body, true];
        }

        return [$key->defaultSubject(), $key->defaultBody(), false];
    }

    /**
     * Literal `{{ token }}` replacement. Tokens not in the variables
     * array are left intact so the operator sees the typo, rather
     * than ending up with a sentence that says "Hi, your booking
     * is.".
     *
     * @param  array<string, string>  $variables
     */
    /**
     * Public entrypoint for one-off token substitution — mirrors the
     * renderer's semantics (including the blank-line hygiene pass) so
     * the template-editor preview stays in sync with what a real send
     * would produce.
     *
     * @param  array<string, string>  $variables
     */
    public function applyTokens(string $template, array $variables): string
    {
        return $this->substitute($template, $variables);
    }

    private function substitute(string $template, array $variables): string
    {
        $out = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $m) use ($variables): string {
                $token = $m[1];

                return array_key_exists($token, $variables) ? $variables[$token] : $m[0];
            },
            $template,
        );

        // Collapse triple-or-more blank lines to a single blank line so
        // an optional token (checkin_time / checkout_time /
        // late_checkout_policy) that renders as an empty line doesn't
        // leave a widening gap. Safe: normal templates rarely produce
        // 3+ consecutive newlines organically.
        return (string) preg_replace('/(\r?\n){3,}/', "\n\n", $out);
    }
}
