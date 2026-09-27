@php
    /**
     * Reusable "House rules" card. Inclusion sites must pass:
     *
     *   @include('tenant.partials.house-rules', ['property' => $property])
     *
     * The card renders nothing when no rule fields are set, so it's
     * always safe to drop in even if the operator hasn't filled rules.
     *
     * Styling reuses the tokens defined in the parent landing/category
     * page so it inherits cream + ink palette without redefining them.
     */
    $rules = $property->houseRules();
    if (! $property->hasHouseRules()) {
        return;
    }

    /**
     * Formats an HH:MM string into a guest-friendly "1:00 PM" without
     * leading zeros. Empty strings come back as empty.
     */
    $formatTime = static function (string $t): string {
        if ($t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t)) {
            return '';
        }
        try {
            return \Carbon\Carbon::createFromFormat('H:i', $t)->format('g:i A');
        } catch (\Throwable) {
            return '';
        }
    };
    $checkInFrom = $formatTime($rules['check_in_from']);
    $checkInTo = $formatTime($rules['check_in_to']);
    $checkOutFrom = $formatTime($rules['check_out_from']);
    $checkOutTo = $formatTime($rules['check_out_to']);

    $checkInRange = match (true) {
        $checkInFrom !== '' && $checkInTo !== '' => "{$checkInFrom}, {$checkInTo}",
        $checkInFrom !== '' => "From {$checkInFrom}",
        $checkInTo !== '' => "Until {$checkInTo}",
        default => '',
    };
    $checkOutRange = match (true) {
        $checkOutFrom !== '' && $checkOutTo !== '' => "{$checkOutFrom}, {$checkOutTo}",
        $checkOutFrom !== '' => "From {$checkOutFrom}",
        $checkOutTo !== '' => "Until {$checkOutTo}",
        default => '',
    };

    // Items to render, order matters (guests scan top-down for the
    // policies most likely to affect their decision).
    $items = [];
    if ($checkInRange !== '') {
        $items[] = ['icon' => 'calendar-in', 'label' => 'Check-in',  'value' => $checkInRange];
    }
    if ($checkOutRange !== '') {
        $items[] = ['icon' => 'calendar-out', 'label' => 'Check-out', 'value' => $checkOutRange];
    }
    if ($rules['cancellation_policy'] !== '') {
        $items[] = ['icon' => 'info', 'label' => 'Cancellation', 'value' => $rules['cancellation_policy']];
    }
    if ($rules['early_checkout'] !== '') {
        $items[] = ['icon' => 'clock', 'label' => 'Early check-out', 'value' => $rules['early_checkout']];
    }
    if ($rules['children_policy'] !== '') {
        $items[] = ['icon' => 'children', 'label' => 'Children', 'value' => $rules['children_policy']];
    }
    if ($rules['pets_policy'] !== '') {
        $items[] = ['icon' => 'paw', 'label' => 'Pets', 'value' => $rules['pets_policy']];
    }
    if ($rules['smoking_policy'] !== '') {
        $items[] = ['icon' => 'smoke', 'label' => 'Smoking', 'value' => $rules['smoking_policy']];
    }
    if ($rules['quiet_hours'] !== '') {
        $items[] = ['icon' => 'moon', 'label' => 'Quiet hours', 'value' => $rules['quiet_hours']];
    }
    // Payments, derived from tenant setup, not free text. We only show
    // when at least one payment channel is configured so guests aren't
    // promised a method that won't work at checkout.
    $tenant = $property->tenant;
    if ($tenant && ((string) ($tenant->paystack_subaccount_code ?? '') !== ''
                    || (string) ($tenant->paystack_secret_key ?? '') !== '')) {
        $items[] = ['icon' => 'card', 'label' => 'Payment methods', 'value' => 'Online, card, bank transfer, USSD'];
    }

    $iconSvg = static function (string $name): string {
        return match ($name) {
            'calendar-in', 'calendar-out' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/></svg>',
            'info'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v.01M11 12h1v5h1"/></svg>',
            'clock'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            'children' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="6" r="2"/><path d="M9 21v-7l-2-3 5-2 5 2-2 3v7"/></svg>',
            'paw'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="10" r="1.7"/><circle cx="10" cy="6" r="1.7"/><circle cx="14" cy="6" r="1.7"/><circle cx="18" cy="10" r="1.7"/><path d="M9 17c0-3 6-3 6 0 0 2-1.5 3-3 3s-3-1-3-3z"/></svg>',
            'smoke'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16h14a4 4 0 0 0 0-8M18 14v4"/><circle cx="20" cy="18" r="1"/></svg>',
            'moon'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>',
            'card'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 11h18M7 16h4"/></svg>',
            default  => '',
        };
    };
@endphp

<section class="house-rules" data-testid="house-rules" aria-labelledby="house-rules-heading">
    <div class="house-rules-head">
        <span class="eyebrow">Good to know</span>
        <h2 id="house-rules-heading">Property rules</h2>
        <p>Worth a quick read before you book, sets expectations on both sides.</p>
    </div>

    <ul class="house-rules-list">
        @foreach ($items as $item)
            <li class="house-rules-row" data-testid="rule-{{ $item['label'] }}">
                <span class="house-rules-icon" aria-hidden="true">{!! $iconSvg($item['icon']) !!}</span>
                <span class="house-rules-label">{{ $item['label'] }}</span>
                <span class="house-rules-value">{{ $item['value'] }}</span>
            </li>
        @endforeach
    </ul>
</section>

<style>
    .house-rules {
        margin-top: 3.5rem;
        padding: 36px;
        background: var(--paper-2, #FCFAF5);
        border: 1px solid var(--line-soft, #ECE6D8);
        border-radius: 22px;
    }
    .house-rules-head .eyebrow {
        font-size: 11px;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        color: var(--ink-mute, #8a7f6a);
    }
    .house-rules-head h2 {
        margin: 0.5rem 0 0;
        font-family: var(--display-font, var(--body-font, serif));
        font-size: clamp(22px, 2.6vw, 28px);
        font-weight: 600;
        color: var(--ink, #1a1614);
    }
    .house-rules-head p {
        margin: 0.5rem 0 1.5rem;
        font-size: 14.5px;
        line-height: 1.55;
        color: var(--ink-soft, #4d4438);
        max-width: 560px;
    }
    .house-rules-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 0;
    }
    .house-rules-row {
        display: grid;
        grid-template-columns: 28px 160px 1fr;
        align-items: start;
        gap: 16px;
        padding: 16px 0;
        border-top: 1px solid var(--line-soft, #ECE6D8);
        font-size: 14.5px;
        color: var(--ink, #1a1614);
        line-height: 1.55;
    }
    .house-rules-row:first-child { border-top: 0; padding-top: 4px; }
    .house-rules-icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px;
        border-radius: 999px;
        background: var(--paper, #FAF7F1);
        color: var(--ink-soft, #4d4438);
        margin-top: 1px;
    }
    .house-rules-label {
        font-weight: 600;
        color: var(--ink, #1a1614);
        font-size: 13.5px;
    }
    .house-rules-value {
        color: var(--ink-soft, #4d4438);
    }
    @media (max-width: 720px) {
        .house-rules { padding: 22px; }
        .house-rules-row {
            grid-template-columns: 28px 1fr;
        }
        .house-rules-label {
            grid-column: 2;
        }
        .house-rules-value {
            grid-column: 2;
            margin-top: 2px;
        }
    }
</style>
