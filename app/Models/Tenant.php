<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomDomainStatus;
use App\Enums\TenantStatus;
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
 * @property string|null $site_title
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
 * @property Carbon|null $custom_domain_live_at
 * @property int $custom_domain_failed_checks
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
            'custom_domain_live_at' => 'datetime',
            'custom_domain_failed_checks' => 'integer',
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
     * via the Integrations page — used by the UseTenantMediaDisk switch
     * task to decide whether to swap MediaLibrary's disk.
     */
    public function hasDokweStorage(): bool
    {
        return $this->dokwe_storage_connected_at !== null
            && (string) $this->dokwe_storage_bucket !== ''
            && (string) $this->dokwe_storage_access_key !== ''
            && (string) $this->dokwe_storage_secret_key !== '';
    }

    /**
     * The business's title on its booking site and in browser tabs: its
     * own site title, else its name (docs/MULTITENANCY.md, step 6).
     */
    public function siteTitle(): string
    {
        return trim((string) $this->site_title) !== '' ? (string) $this->site_title : (string) $this->name;
    }

    /**
     * The business's logo: the one uploaded under Branding for its first
     * building, else the next building that has one. Null when none.
     */
    public function logoUrl(): ?string
    {
        foreach ($this->properties()->oldest('id')->with('media')->get() as $building) {
            if (($media = $building->getFirstMedia('logo')) !== null) {
                return $media->getUrl();
            }
        }

        return null;
    }

    /**
     * True once the business's own domain is verified and answering for it
     * over HTTPS (App\Actions\Domains\CheckOwnDomain). Only then do links
     * and the booking site move to it.
     */
    public function ownDomainIsLive(): bool
    {
        return $this->custom_domain !== null
            && $this->domain_verified_at !== null
            && $this->custom_domain_live_at !== null;
    }

    /**
     * The host (subdomain or live own domain) used by the booking engine
     * for canonical URL generation (PRD §10 SEO requirement).
     */
    public function primaryHost(): string
    {
        if ($this->ownDomainIsLive()) {
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
     * All properties (branches) belonging to this install. Solo mode
     * seeds one Property in /setup; the operator can add more from
     * the Branches management page.
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
     * Solo mode has no platform billing — the hotel runs on its own
     * infra. Kept as a stub returning `false` so any leftover caller
     * (dashboard read-only banner, write-guard) reads as "unlocked".
     */
    public function isReadOnlyForBilling(): bool
    {
        return false;
    }
}
