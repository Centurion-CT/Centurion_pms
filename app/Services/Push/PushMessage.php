<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * One host push (docs/host-app/PHASE-5.md). `kind` is the preference
 * key it belongs to (bookings, payments, approvals, guest_requests,
 * low_stock, daily_summary); `target` is what the app's router opens
 * on tap (booking:REF, approvals, request:ID, summary:YYYY-MM-DD, or a
 * /dashboard/... web path).
 */
final class PushMessage
{
    public readonly string $title;

    public readonly string $body;

    public function __construct(
        public readonly string $kind,
        string $title,
        string $body,
        public readonly string $target,
        public readonly ?int $propertyId,
        public readonly string $tenantSlug,
        public readonly string $id,
        /** Urgent pushes still go out in quiet hours (22:00 to 07:00 Lagos). */
        public readonly bool $urgent = false,
    ) {
        $this->title = self::clean($title, 120);
        $this->body = self::clean($body, 240);
    }

    /**
     * FCM data payload: every value a string.
     *
     * @return array{kind: string, target: string, property_id: string, tenant: string, id: string}
     */
    public function data(): array
    {
        return [
            'kind' => $this->kind,
            'target' => $this->target,
            'property_id' => $this->propertyId !== null ? (string) $this->propertyId : '',
            'tenant' => $this->tenantSlug,
            'id' => $this->id,
        ];
    }

    /** ₦85,000 */
    public static function naira(int $amount): string
    {
        return '₦'.number_format($amount);
    }

    /** No em / en dashes in push copy, single spaces, capped length. */
    private static function clean(string $text, int $max): string
    {
        $text = str_replace(['—', '–'], ['-', '-'], $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 3)).'...' : $text;
    }
}
