<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Models\Tenant;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Online-payment payouts for a tenant (host app owner screen 26), read
 * from Paystack's settlements API (docs/host-app/PHASE-4.md).
 *
 * Which account, mirroring how guest payments are initialised
 * ({@see \App\Actions\Bookings\InitiateBookingPayment::resolvePaystackClient}):
 *   - subaccount tenant: the platform key (forPlatform) filtered to the
 *     tenant's subaccount; bank details from GET /subaccount.
 *   - own-key tenant (legacy paystack_secret_key, no subaccount): the
 *     tenant's own key, unfiltered (their whole account settles to
 *     them); bank details from the tenant row when saved.
 *   - neither: online payments settle through Keverna, so there are no
 *     direct payouts to show (configured:false + a plain reason).
 *
 * Caching: one fresh copy per tenant for 15 minutes, plus a longer
 * "last good" copy. When Paystack fails the last good copy is served
 * with `stale: true`; with nothing cached the payload says
 * `error: payouts_unavailable`.
 */
class TenantPayouts
{
    public const CACHE_MINUTES = 15;

    public const LAST_GOOD_DAYS = 7;

    /** After a failed fetch, don't retry Paystack for this long. */
    public const FAILURE_BACKOFF_SECONDS = 120;

    public const LOOKBACK_DAYS = 60;

    public const RECENT_LIMIT = 20;

    /** Web page where the owner sets the payout bank (outside /dashboard). */
    public const SETTINGS_PATH = '/settings/property';

    public const NOT_CONFIGURED_REASON = 'Online payments for this property settle through Keverna. Add your bank account in Settings to receive payouts directly.';

    /** Paystack settlement status => host app status. */
    private const STATUS_MAP = [
        'success' => 'paid',
        'successful' => 'paid',
        'paid' => 'paid',
        'processing' => 'processing',
        'pending' => 'scheduled',
        'failed' => 'failed',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forTenant(Tenant $tenant): array
    {
        if (! $tenant->hasOwnPaystackPayouts()) {
            return self::notConfigured();
        }

        $fresh = Cache::get(self::freshKey($tenant));
        if (is_array($fresh)) {
            return $fresh + ['stale' => false];
        }

        try {
            if (Cache::has(self::failureKey($tenant))) {
                throw new \RuntimeException('Paystack failed recently; backing off.');
            }
            $payload = $this->fetch($tenant);
        } catch (Throwable $e) {
            Log::warning('Paystack payouts unavailable', [
                'tenant_id' => $tenant->getKey(),
                'error' => $e->getMessage(),
            ]);
            // Owner home reads payouts too: a Paystack outage must not
            // make every home request wait on timeouts.
            Cache::add(self::failureKey($tenant), true, self::FAILURE_BACKOFF_SECONDS);

            $last = Cache::get(self::lastGoodKey($tenant));
            if (is_array($last)) {
                return array_merge($last, ['stale' => true]);
            }

            return [
                'configured' => true,
                'error' => 'payouts_unavailable',
                'reason' => null,
                'next' => null,
                'recent' => [],
                'month_total' => 0,
                'month_fees' => 0,
                'payouts_count' => 0,
                'bank' => null,
                'cached_at' => null,
                'stale' => false,
            ];
        }

        Cache::put(self::freshKey($tenant), $payload, now()->addMinutes(self::CACHE_MINUTES));
        Cache::put(self::lastGoodKey($tenant), $payload, now()->addDays(self::LAST_GOOD_DAYS));

        return $payload + ['stale' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function notConfigured(): array
    {
        return [
            'configured' => false,
            'reason' => self::NOT_CONFIGURED_REASON,
            'next' => null,
            'recent' => [],
            'month_total' => 0,
            'month_fees' => 0,
            'payouts_count' => 0,
            'bank' => null,
            'cached_at' => null,
            'stale' => false,
        ];
    }

    public static function freshKey(Tenant $tenant): string
    {
        return 'owner-payouts:t'.$tenant->getKey().':fresh';
    }

    public static function failureKey(Tenant $tenant): string
    {
        return 'owner-payouts:t'.$tenant->getKey().':failed';
    }

    public static function lastGoodKey(Tenant $tenant): string
    {
        return 'owner-payouts:t'.$tenant->getKey().':last';
    }

    public static function mapStatus(string $paystackStatus): string
    {
        return self::STATUS_MAP[strtolower(trim($paystackStatus))] ?? 'processing';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(Tenant $tenant): array
    {
        $subaccountCode = (string) ($tenant->paystack_subaccount_code ?? '');
        if ($subaccountCode !== '') {
            $client = PaystackClient::forPlatform();
            $sub = $client->fetchSubaccount($subaccountCode);
            $filter = $sub['id'] ?? $subaccountCode;
            $bank = $sub['bank_name'] !== '' || $sub['account_last4'] !== ''
                ? ['name' => $sub['bank_name'], 'account_last4' => $sub['account_last4'], 'account_name' => $sub['account_name']]
                : null;
        } else {
            $client = PaystackClient::forTenantKey((string) $tenant->paystack_secret_key);
            $filter = null;
            $digits = preg_replace('/\D/', '', (string) ($tenant->paystack_account_number ?? '')) ?? '';
            $bank = (string) ($tenant->paystack_bank_name ?? '') !== '' || $digits !== ''
                ? [
                    'name' => (string) ($tenant->paystack_bank_name ?? ''),
                    'account_last4' => substr($digits, -4),
                    'account_name' => (string) ($tenant->paystack_account_name ?? ''),
                ]
                : null;
        }

        $today = Carbon::today();
        $rows = $client->listSettlements(
            subaccount: $filter,
            from: $today->copy()->subDays(self::LOOKBACK_DAYS)->toDateString(),
            to: $today->copy()->addDays(14)->toDateString(),
            perPage: 100,
        );

        $settlements = collect($rows)->map(fn (array $r): array => self::mapSettlement($r))
            ->filter(fn (array $r) => $r['date'] !== null)
            ->sortBy([
                fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']),
                fn (array $a, array $b) => strcmp($b['id'], $a['id']),
            ])
            ->values();

        $bankLabel = $bank === null ? null : trim($bank['name'].($bank['account_last4'] !== '' ? ' ····'.$bank['account_last4'] : ''));

        $upcoming = $settlements
            ->filter(fn (array $r) => in_array($r['status'], ['scheduled', 'processing'], true))
            ->sortBy(fn (array $r) => $r['date'].'|'.$r['id'])
            ->first();

        $monthPaid = $settlements->filter(fn (array $r) => $r['status'] === 'paid'
            && str_starts_with((string) $r['date'], $today->format('Y-m')));

        return [
            'configured' => true,
            'reason' => null,
            'next' => $upcoming === null ? null : [
                'amount' => $upcoming['amount'],
                'date' => $upcoming['date'],
                'bank_label' => $bankLabel,
            ],
            'recent' => $settlements->take(self::RECENT_LIMIT)->values()->all(),
            'month_total' => (int) $monthPaid->sum('amount'),
            'month_fees' => (int) $monthPaid->sum(fn (array $r) => (int) ($r['fee'] ?? 0)),
            'payouts_count' => $monthPaid->count(),
            'bank' => $bank,
            'cached_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  raw Paystack settlement (kobo)
     * @return array{id: string, amount: int, date: ?string, status: string, payments_count: ?int, fee: ?int}
     */
    public static function mapSettlement(array $row): array
    {
        $kobo = $row['effective_amount'] ?? $row['total_amount'] ?? 0;
        $rawDate = (string) ($row['settlement_date'] ?? $row['settled_at'] ?? $row['createdAt'] ?? $row['created_at'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate) === 1 ? substr($rawDate, 0, 10) : null;
        $count = $row['transactions_count'] ?? $row['total_transactions'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'amount' => (int) round(((float) $kobo) / 100),
            'date' => $date,
            'status' => self::mapStatus((string) ($row['status'] ?? '')),
            'payments_count' => is_numeric($count) ? (int) $count : null,
            'fee' => isset($row['total_fees']) && is_numeric($row['total_fees']) ? (int) round(((float) $row['total_fees']) / 100) : null,
        ];
    }
}
