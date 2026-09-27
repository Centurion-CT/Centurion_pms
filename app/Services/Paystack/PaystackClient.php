<?php

declare(strict_types=1);

namespace App\Services\Paystack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Low-level HTTP wrapper around the Paystack REST API. Holds a single secret
 * key per instance — Keverna runs this with two keys at once:
 *
 *   - FregateLab's master key: subscription payments (PRD §4.1) AND
 *     guest bookings, routed via per-operator Subaccounts so 2% lands
 *     in Keverna's balance and 98% in the operator's bank.
 *   - Each tenant's legacy per-operator key: only used when a tenant
 *     was provisioned before Subaccounts shipped, or has explicitly
 *     opted into running their own Paystack account.
 *
 * Build instances with the static factories so callers can't accidentally
 * pass a tenant's key where the master key was intended.
 */
class PaystackClient
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl,
    ) {
        if ($this->secretKey === '') {
            throw new RuntimeException('PaystackClient requires a non-empty secret key.');
        }
    }

    public static function forSubscriptions(): self
    {
        return new self(
            secretKey: (string) config('services.paystack.secret_key'),
            baseUrl: (string) config('services.paystack.base_url'),
        );
    }

    /**
     * Use FregateLab's master key. Same credentials as forSubscriptions()
     * but named for the call sites that need it for subaccount /
     * marketplace operations rather than billing.
     */
    public static function forPlatform(): self
    {
        return self::forSubscriptions();
    }

    public static function forTenantKey(string $secretKey): self
    {
        return new self(
            secretKey: $secretKey,
            baseUrl: (string) config('services.paystack.base_url'),
        );
    }

    /**
     * Kicks off a Paystack transaction. Amount is sent in kobo (NGN × 100).
     *
     * When `subaccount` is provided, Paystack will split the transaction
     * automatically — the master account keeps `percentage_charge` (set
     * at subaccount-creation time) and the rest settles to the
     * operator's bank. `bearer = subaccount` makes the operator absorb
     * Paystack's own card-processing fee, which is the right default
     * for marketplace flows: Keverna's 2% should be net.
     *
     * @param  array{
     *     email: string,
     *     amount_ngn: int,
     *     reference: string,
     *     callback_url: ?string,
     *     metadata: array<string,mixed>,
     *     subaccount?: ?string,
     *     bearer?: ?string
     * }  $data
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initializeTransaction(array $data): array
    {
        $payload = [
            'email' => $data['email'],
            'amount' => $data['amount_ngn'] * 100,
            'reference' => $data['reference'],
            'callback_url' => $data['callback_url'] ?? null,
            'metadata' => $data['metadata'],
        ];

        if (! empty($data['subaccount'])) {
            $payload['subaccount'] = $data['subaccount'];
            // Default the operator to bearing Paystack's card fees so
            // Keverna's 2% is net of processing cost. Callers can
            // override with bearer=account if they want the platform
            // to absorb fees instead.
            $payload['bearer'] = $data['bearer'] ?? 'subaccount';
        }

        $response = $this->http()->post('/transaction/initialize', $payload)->throw();

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack initializeTransaction returned status=false');
        }

        return [
            'authorization_url' => (string) $body['data']['authorization_url'],
            'access_code' => (string) $body['data']['access_code'],
            'reference' => (string) $body['data']['reference'],
        ];
    }

    /**
     * Independent server-side verification of a Paystack transaction. Used as
     * a belt-and-braces check before trusting a webhook payload, and as the
     * primary path when running the callback (synchronous redirect after
     * checkout) — webhooks may lag.
     *
     * @return array{status: string, amount_ngn: int, reference: string, paid_at: ?string, metadata: array<string,mixed>}
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->http()->get('/transaction/verify/'.rawurlencode($reference))->throw();
        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack verifyTransaction returned status=false');
        }

        return [
            'status' => (string) ($body['data']['status'] ?? ''),
            'amount_ngn' => (int) ($body['data']['amount'] ?? 0) / 100,
            'reference' => (string) ($body['data']['reference'] ?? $reference),
            'paid_at' => $body['data']['paid_at'] ?? null,
            'metadata' => (array) ($body['data']['metadata'] ?? []),
        ];
    }

    /**
     * Verifies a webhook payload's signature. Paystack signs the raw body
     * with HMAC-SHA512 using the receiving account's secret key — that's the
     * SAME key used for API calls.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, $this->secretKey);

        return hash_equals($computed, $signature);
    }

    /**
     * List of Nigerian banks Paystack supports for payouts. The list
     * rarely changes, so we cache it for a day — refreshes on next
     * lookup if the cache is cold. Returns rows shaped like:
     *   [{ name: string, code: string, slug: string, longcode: string }]
     *
     * @return list<array{name: string, code: string, slug: string, longcode: string}>
     */
    public function listBanks(string $country = 'nigeria'): array
    {
        $cacheKey = "paystack:banks:{$country}";

        /** @var list<array{name: string, code: string, slug: string, longcode: string}> $cached */
        $cached = Cache::remember($cacheKey, now()->addDay(), function () use ($country): array {
            $response = $this->http()->get('/bank', [
                'country' => $country,
                'perPage' => 200,
            ])->throw();
            $body = $response->json();
            if (! is_array($body) || ($body['status'] ?? false) !== true) {
                throw new RuntimeException('Paystack /bank returned status=false');
            }

            return array_values(array_map(static fn (array $b): array => [
                'name'     => (string) ($b['name'] ?? ''),
                'code'     => (string) ($b['code'] ?? ''),
                'slug'     => (string) ($b['slug'] ?? ''),
                'longcode' => (string) ($b['longcode'] ?? ''),
            ], (array) ($body['data'] ?? [])));
        });

        return $cached;
    }

    /**
     * Resolves a NUBAN against a bank code and returns the verified
     * account holder name. Paystack rate-limits this endpoint, so we
     * only call it during the subaccount setup flow — never on every
     * keystroke.
     *
     * @return array{account_number: string, account_name: string}
     */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $response = $this->http()->get('/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ])->throw();
        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack /bank/resolve returned status=false');
        }

        return [
            'account_number' => (string) ($body['data']['account_number'] ?? $accountNumber),
            'account_name' => (string) ($body['data']['account_name'] ?? ''),
        ];
    }

    /**
     * Creates a Paystack Subaccount and returns its subaccount_code.
     * `percentageCharge` is what the MAIN account (Keverna) keeps from
     * each transaction routed through this subaccount; the subaccount
     * (operator) receives 100 - percentageCharge.
     *
     * @return array{subaccount_code: string, account_name: string}
     */
    public function createSubaccount(
        string $businessName,
        string $bankCode,
        string $accountNumber,
        float $percentageCharge,
        ?string $primaryContactEmail = null,
        ?string $primaryContactName = null,
        ?string $primaryContactPhone = null,
        ?string $description = null,
    ): array {
        $response = $this->http()->post('/subaccount', [
            'business_name' => $businessName,
            'settlement_bank' => $bankCode,
            'account_number' => $accountNumber,
            'percentage_charge' => $percentageCharge,
            'description' => $description,
            'primary_contact_email' => $primaryContactEmail,
            'primary_contact_name' => $primaryContactName,
            'primary_contact_phone' => $primaryContactPhone,
        ])->throw();
        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack /subaccount returned status=false');
        }

        return [
            'subaccount_code' => (string) ($body['data']['subaccount_code'] ?? ''),
            'account_name' => (string) ($body['data']['account_name'] ?? ''),
        ];
    }

    /**
     * Updates an existing Subaccount — used when the operator switches
     * their payout bank or business name. Paystack will re-verify the
     * NUBAN; a bad number raises a RequestException so the caller can
     * surface it on the form.
     *
     * @return array{subaccount_code: string, account_name: string}
     */
    public function updateSubaccount(
        string $subaccountCode,
        string $businessName,
        string $bankCode,
        string $accountNumber,
        float $percentageCharge,
    ): array {
        $response = $this->http()->put('/subaccount/'.rawurlencode($subaccountCode), [
            'business_name' => $businessName,
            'settlement_bank' => $bankCode,
            'account_number' => $accountNumber,
            'percentage_charge' => $percentageCharge,
        ])->throw();
        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack /subaccount PUT returned status=false');
        }

        return [
            'subaccount_code' => (string) ($body['data']['subaccount_code'] ?? $subaccountCode),
            'account_name' => (string) ($body['data']['account_name'] ?? ''),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            // Paystack is in Europe; from West Africa the TCP + TLS
            // handshake routinely takes 4–8s on cellular. Default
            // Guzzle connectTimeout (10s) was the cause of mid-checkout
            // "cURL 28" timeouts during the Lagos peak window. Give
            // it room: 20s to connect, 30s end-to-end, plus a single
            // quick retry so a flaky packet doesn't sink the booking.
            ->connectTimeout(20)
            ->timeout(30)
            ->retry(2, 250, throw: false);
    }
}
