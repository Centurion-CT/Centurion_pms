<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomDomainStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Multitenancy\TenantScope;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $email
 * @property string|null $phone
 * @property string|null $plan_id
 * @property int|null $expected_units
 * @property TenantStatus $status
 * @property string|null $paystack_secret_key
 * @property string|null $paystack_subaccount_code
 * @property string|null $paystack_bank_code
 * @property string|null $paystack_account_number
 * @property string|null $paystack_account_name
 * @property string|null $paystack_bank_name
 * @property string|null $dokwe_storage_access_key
 * @property string|null $dokwe_storage_secret_key
 * @property string|null $dokwe_storage_bucket
 * @property string|null $dokwe_storage_endpoint
 * @property string|null $dokwe_storage_public_url
 * @property string $dokwe_storage_region
 * @property Carbon|null $dokwe_storage_connected_at
 * @property string|null $custom_domain
 * @property string|null $custom_domain_verification_token
 * @property CustomDomainStatus|null $custom_domain_status
 * @property Carbon|null $domain_verified_at
 * @property Carbon|null $subdomain_provisioned_at
 * @property Carbon|null $slug_changed_at
 * @property Carbon|null $subscription_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Tenant extends SpatieTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'expected_units' => 'int',
            // PRD §10 — AES-256 at rest. APP_KEY drives Laravel's encrypter.
            // Account number is encrypted because losing it lets an
            // attacker re-point an operator's payouts. The subaccount
            // code itself is non-sensitive (only useful with the
            // master Paystack key).
            'paystack_secret_key' => 'encrypted',
            'paystack_account_number' => 'encrypted',
            // Dokwe Storage integration — tenant supplies their own
            // S3-compatible space. Access/secret keys are AES-256 at
            // rest via APP_KEY; bucket/endpoint/URL stay plaintext
            // because they're guessable from the tenant's Dokwe
            // dashboard URL anyway.
            'dokwe_storage_access_key' => 'encrypted',
            'dokwe_storage_secret_key' => 'encrypted',
            'dokwe_storage_connected_at' => 'datetime',
            'custom_domain_status' => CustomDomainStatus::class,
            'domain_verified_at' => 'datetime',
            'subdomain_provisioned_at' => 'datetime',
            'slug_changed_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    /**
     * True when this tenant has their own Paystack split-settlement
     * (subaccount) wired up. When false the guest checkout falls back
     * to the platform's own Paystack account — money lands with
     * Keverna and gets manually paid out to the operator against the
     * booking ledger. See {@see \App\Actions\Bookings\InitiateBookingPayment::resolvePaystackClient}.
     */
    public function hasOwnPaystackPayouts(): bool
    {
        return (string) $this->paystack_subaccount_code !== ''
            || (string) $this->paystack_secret_key !== '';
    }

    /**
     * True when the tenant has connected their own Dokwe Storage space
     * via the Integrations page — used by ConfigureTenantMediaDisk
     * middleware to decide whether to swap MediaLibrary's disk for
     * this request.
     */
    public function hasDokweStorage(): bool
    {
        return $this->dokwe_storage_connected_at !== null
            && (string) $this->dokwe_storage_bucket !== ''
            && (string) $this->dokwe_storage_access_key !== ''
            && (string) $this->dokwe_storage_secret_key !== '';
    }

    /**
     * The host (subdomain or verified custom domain) used by the booking engine
     * for canonical URL generation (PRD §10 SEO requirement).
     */
    public function primaryHost(): string
    {
        if ($this->custom_domain !== null && $this->domain_verified_at !== null) {
            return $this->custom_domain;
        }

        return $this->slug.config('keverna.tenant_domain_suffix');
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TenantStatus::Trial->value,
            TenantStatus::Active->value,
            TenantStatus::Grace->value,
        ]);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * All properties (branches) belonging to this org. Multi-property
     * was added late in Phase 3 — orgs pre-launch have exactly one
     * property (slug "main"); new orgs can add branches from the
     * Branches management page.
     *
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * Legacy singular accessor — returns the first property, ordered
     * by id (so "main" wins for pre-multi-property tenants). Preserved
     * so surfaces that don't care about branch (branding preview,
     * mail-branding fallback, onboarding gate) keep working without
     * every call site becoming property-aware overnight.
     *
     * New code should prefer either `properties()` (for lists) or the
     * PropertyContext service (for the operator's current branch).
     *
     * @return HasOne<Property, $this>
     */
    public function property(): HasOne
    {
        return $this->hasOne(Property::class)->oldest('id');
    }

    /**
     * The most recent live (trial or active) subscription, if any. Drives the
     * derived tenant.status + tenant.subscription_expires_at fields.
     *
     * Lifts the TenantScope so it works from central context (webhooks,
     * scheduled commands) — Tenant itself is central, so calling this never
     * crosses isolation boundaries.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
            ])
            ->orderByDesc('expires_at')
            ->first();
    }

    /**
     * True when the tenant has no live subscription — either the most recent
     * Trial/Active row has expired, or there's no such row at all. Drives the
     * dashboard read-only mode + the "renew to make changes" bottom bar.
     *
     * Checks expires_at live (not tenant.status) so a trial that ended today
     * flips read-only immediately, without waiting for the daily
     * ProcessExpiredSubscriptions job to advance the tenant into Grace.
     */
    public function isReadOnlyForBilling(): bool
    {
        $sub = $this->currentSubscription();

        return $sub === null || $sub->expires_at->isPast();
    }
}
