<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\SubscriptionExpiredException;
use App\Mail\Transport\SendpipeTransport;
use App\Models\Activity;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\SystemDnsResolver;
use App\Services\Identity\GuestClaimTokenSigner;
use App\Services\Identity\TenantSsoTokenSigner;
use App\Services\Sendpipe\SendpipeClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNAdapter;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // DNS lookups (PRD §6.6.2 custom domain verification). Tests override
        // this binding with FakeDnsResolver.
        $this->app->singleton(DnsResolver::class, SystemDnsResolver::class);

        // Central → tenant SSO handoff signer (docs/unified-login.md §3).
        // The signer HMACs URLs with the app key. Bound as a singleton so
        // constructor injection resolves cleanly in controllers and tests.
        $this->app->singleton(TenantSsoTokenSigner::class, function ($app): TenantSsoTokenSigner {
            return new TenantSsoTokenSigner(self::resolveAppKeyBytes());
        });

        // Same signing shape as the SSO signer, different route + TTL.
        $this->app->singleton(GuestClaimTokenSigner::class, function ($app): GuestClaimTokenSigner {
            return new GuestClaimTokenSigner(self::resolveAppKeyBytes());
        });
    }

    /**
     * Return the app.key as raw bytes. Laravel stores APP_KEY prefixed
     * with "base64:" — strip and decode so the HMAC key is deterministic
     * across restarts and matches how Laravel itself uses the key.
     */
    private static function resolveAppKeyBytes(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), strict: true) ?: $key;
        }

        return $key;
    }

    public function boot(): void
    {
        // Register a custom "sendpipe" mail transport so any Mailable sent
        // via Mail::to(...)->queue(...) routes through Sendpipe (PRD §5.1).
        Mail::extend('sendpipe', fn (): SendpipeTransport => new SendpipeTransport(
            app(SendpipeClient::class),
        ));

        // Keep customer aggregates (lifetime_value, total_bookings,
        // first/last_booking_at) truthful against booking rows on
        // EVERY save + delete — no code path can leave the aggregate
        // stale. See the observer for scope + reasoning.
        \App\Models\Booking::observe(\App\Observers\BookingCustomerAggregateObserver::class);

        // Push signal for the dashboard search cache. On every
        // booking mutation, broadcast an upsert / delete on the
        // tenant's private channel so operator IDB stores update
        // without waiting for the 5-minute heartbeat pull.
        \App\Models\Booking::observe(\App\Observers\BookingSearchCacheObserver::class);

        // Broadcast a cross-tenant PlatformPaymentPulse whenever a
        // successful Payment lands, powering the marketing kobo counter
        // live-tick + floating "just received" toast. The observer
        // filters refunds, failed rows, corporate-AR mirrors, and
        // micro-txns below the ₦5k floor so the public surface only
        // reflects real inflows.
        \App\Models\Payment::observe(\App\Observers\PaymentPulseObserver::class);

        // Auto-provision a hidden category + room when a single_unit
        // property is created / flipped, so the availability + pricing
        // engine has a backing chain without operator busywork.
        \App\Models\Property::observe(\App\Observers\PropertyObserver::class);

        // Register the Bunny Storage driver so `Storage::disk('bunny')`
        // and any media-library collection with `disk_name => 'bunny'`
        // resolves to the BunnyCDN Storage zone configured via env.
        // Public URLs are served through the linked Pull Zone — see
        // BUNNY_PULL_ZONE_URL in .env. The custom override of `url()`
        // makes Spatie's `getUrl()` return the CDN URL rather than the
        // Storage origin.
        Storage::extend('bunny', function ($app, array $config): FilesystemAdapter {
            $client = new BunnyCDNClient(
                storage_zone_name: (string) $config['storage_zone'],
                api_key: (string) $config['api_key'],
                region: (string) ($config['region'] ?? 'de'),
            );
            $adapter = new BunnyCDNAdapter($client, (string) ($config['pull_zone_url'] ?? ''));

            return new class(new Filesystem($adapter, $config), $adapter, $config) extends FilesystemAdapter
            {
                public function __construct($driver, $adapter, array $config)
                {
                    parent::__construct($driver, $adapter, $config);
                }

                // Laravel's parent declares `url($path)` without
                // typehints — keep the signature untyped so PHP
                // accepts the override. Internally we still treat it
                // as a string.
                public function url($path)
                {
                    $base = rtrim((string) ($this->config['pull_zone_url'] ?? ''), '/');

                    return $base.'/'.ltrim((string) $path, '/');
                }
            };
        });

        // NOTE: every listener in app/Listeners is auto-discovered by
        // Laravel 12 via the `handle*(TypedEvent $event)` convention:
        //   - DispatchSubdomainProvisioning::handle{TenantOnboarded,SubscriptionActivated}
        //   - SendBookingConfirmationMail::handle(BookingConfirmed $event)
        //   - SendBookingCancelledMail::handle(BookingCancelled $event)
        //   - SendOperatorWelcomeMail::handle(TenantOnboarded $event)
        //   - NotifyOperatorsOfBookingActivity::handle{Confirmed,Cancelled}
        //   - UpdateCustomerOnBookingActivity::handle{Confirmed,Cancelled}
        // Don't add manual Event::listen() calls — they create duplicate
        // registrations that double-fire (caught when bell tests showed
        // 2× expected notifications on each event dispatch).

        // PRD §17: stamp tenant_id on every DatabaseNotification row so the
        // bell can query without joining on the notifiable. Prefers the value
        // packed into the notification's data payload (the only reliable
        // tenant_id when running queued — Tenant::current() resets between
        // jobs); falls back to the current bound tenant for synchronous fires.
        DatabaseNotification::creating(function (DatabaseNotification $notification): void {
            if ($notification->getAttribute('tenant_id') !== null) {
                return;
            }

            $fromData = $notification->getAttribute('data')['tenant_id'] ?? null;
            if (is_numeric($fromData)) {
                $notification->setAttribute('tenant_id', (int) $fromData);

                return;
            }

            $tenant = Tenant::current();
            if ($tenant instanceof Tenant) {
                $notification->setAttribute('tenant_id', $tenant->getKey());
            }
        });

        // Global write guard for expired-trial tenants. The
        // EnforceSubscriptionAccess middleware sets the container flag when
        // the current request belongs to a tenant with no live subscription;
        // these listeners then throw on any Eloquent save/delete so writes
        // fail whether they originate from a Livewire action, a form POST,
        // or a service call. Allowlist preserves the renewal path:
        // Subscription rows must be creatable so the operator can start a
        // Paystack checkout; Activity + DatabaseNotification stay writable
        // so audit + system notifications still work.
        //
        // Listens on the wildcard "eloquent.saving: *" (not Model::saving),
        // because Eloquent namespaces model events by subclass and a base
        // Model::saving() callback never fires for App\Models\Room et al.
        $guard = static function (string $eventName, array $payload): void {
            if (! app()->bound('billing.read_only') || ! app('billing.read_only')) {
                return;
            }

            $model = $payload[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }

            foreach ([Subscription::class, Activity::class, DatabaseNotification::class] as $allowed) {
                if ($model instanceof $allowed) {
                    return;
                }
            }

            throw new SubscriptionExpiredException();
        };

        Event::listen('eloquent.saving: *', $guard);
        Event::listen('eloquent.deleting: *', $guard);
    }
}
