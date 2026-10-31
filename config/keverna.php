<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Domain Topology
    |--------------------------------------------------------------------------
    |
    | The "central" domain hosts the marketing site, registration, and shared
    | auth flows. Tenants live on `<slug>.<tenant_domain_suffix>` (e.g.
    | `grand-palace-abuja.keverna.com`) or a verified custom domain. The admin
    | panel lives on its own subdomain (PRD §8).
    */
    'central_domain' => env('CENTRAL_DOMAIN', 'keverna.test'),
    'admin_domain' => env('ADMIN_DOMAIN', 'admin.keverna.test'),
    'tenant_domain_suffix' => env('TENANT_DOMAIN_SUFFIX', '.keverna.test'),

    /*
    | How a request finds its business (docs/MULTITENANCY.md):
    |  - 'single'  — every request binds the first business, whatever the
    |                host. What production runs until the wildcard DNS for
    |                the tenant suffix is live.
    |  - 'domains' — the host decides: a verified own domain, else
    |                `{slug}<tenant_domain_suffix>`. The central domain and
    |                reserved subdomains bind no business.
    */
    'tenancy_mode' => env('TENANCY_MODE', 'single'),

    /*
    | Whether new businesses can sign up on the platform's /signup page
    | (docs/MULTITENANCY.md). Off by default, so the platform can go live
    | for existing businesses before sign-up opens. When off, /signup and
    | the home page invite people to email instead.
    */
    'signup_open' => (bool) env('SIGNUP_OPEN', false),

    /*
    | Map tiles on the booking site (Leaflet URL template + attribution).
    | The default is Esri's street map, as in the approved design; check
    | its terms, or set a keyed provider (MapTiler, Stadia) for production
    | traffic.
    */
    'site_map' => [
        'tiles' => env('SITE_MAP_TILES', 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}'),
        'attribution' => env('SITE_MAP_ATTRIBUTION', 'Tiles &copy; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'),
    ],

    /*
    | Subdomains of the tenant suffix that never belong to a business, and
    | that sign-up must refuse as a slug.
    */
    'reserved_slugs' => [
        'www', 'app', 'admin', 'api', 'mail', 'email', 'login', 'signup', 'register',
        'help', 'support', 'status', 'console', 'billing', 'docs', 'blog', 'cdn',
        'static', 'assets', 'media', 'dashboard', 'cname', 'ns1', 'ns2', 'smtp',
    ],

    /*
    | The well-known CNAME target operators point their custom domain at.
    | This hostname resolves to Dokwe's ingress; the choice of DNS
    | provider (Namecheap today) doesn't matter here. Used by:
    |  - App\Livewire\Domain\Manage: shown to the operator as the value
    |    they type into their DNS provider's CNAME field.
    |  - App\Jobs\Domains\VerifyCustomDomain: the value we assert their
    |    live CNAME resolves to before we mark the domain verified.
    */
    'custom_domain_cname_target' => env('CUSTOM_DOMAIN_CNAME_TARGET', 'cname.'.env('CENTRAL_DOMAIN', 'keverna.test')),

    /*
    | A bare domain (`grandpalace.ng`) can't carry a CNAME, so operators
    | point it with A records at these addresses instead: Dokwe's ingress
    | IPs, comma-separated. Empty hides the A-record option on /domain.
    */
    'custom_domain_a_records' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CUSTOM_DOMAIN_A_RECORDS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | In-app domain management (opt-in)
    |--------------------------------------------------------------------------
    |
    | Off by default in solo: the hotel's domain is attached once in the
    | Dokwe dashboard. When enabled, the /domain page + sidebar entry come
    | back, letting the manager rename the subdomain or attach a custom
    | domain, which dispatches the App\Jobs\Domains\* jobs. Those need
    | DOKWE_API_KEY + DOKWE_PROJECT_ID (and CLOUDFLARE_* for subdomain DNS).
    */
    'domain_management' => [
        // On by default once each business has its own address.
        'enabled' => (bool) env('DOMAIN_MANAGEMENT_ENABLED', env('TENANCY_MODE', 'single') === 'domains'),
    ],

    /*
    | App-level HTTP → HTTPS 301 (App\Http\Middleware\ForceHttpsInProduction).
    | Turn off when the edge already enforces HTTPS but the proxy chain
    | doesn't deliver `X-Forwarded-Proto: https` to the app — otherwise the
    | middleware 301-loops. Generated URLs stay https either way because
    | AppServiceProvider forces the scheme from an https APP_URL.
    */
    'force_https' => (bool) env('FORCE_HTTPS', true),

    /*
    |--------------------------------------------------------------------------
    | Subdomain provisioning mode
    |--------------------------------------------------------------------------
    |
    | 'wildcard'      — nginx serves `*.keverna.com` under one wildcard cert.
    |                   ProvisionTenantSubdomain stamps `subdomain_provisioned_at`
    |                   and stops there; no per-tenant infra call is needed
    |                   because the tenant subdomain is already routable and
    |                   TLS-covered the moment the row exists. This is the
    |                   current production setup (see docs/subdomain-dns.md).
    | 'dokwe-attach'  — Legacy: dispatch AttachTenantDomainToDokwe which asks
    |                   Dokwe to record + verify + issue a per-subdomain cert.
    |                   Only works when the deployment is in Traefik mode with
    |                   per-domain CNAMEs, which no longer matches how we run.
    */
    'subdomain_provisioning' => [
        'mode' => env('SUBDOMAIN_PROVISIONING_MODE', 'wildcard'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing constants (PRD §4.1)
    |--------------------------------------------------------------------------
    |
    | Values are in NGN. Percent fields are floats (e.g. 1.5 = 1.5%).
    */
    'pricing' => [
        'subscription_annual_ngn' => (int) env('SUBSCRIPTION_ANNUAL_NGN', 300000),
        'trial_days' => (int) env('TRIAL_DAYS', 14),
        // Paystack card fee for guest bookings + wallet top-ups.
        // Model: 1.5% × subtotal, capped at ₦2,000. No flat portion
        // - Paystack waives the ₦100 flat on transactions above
        // ₦2,500 which every hospitality booking will be.
        'paystack_fee_percent' => (float) env('PAYSTACK_FEE_PERCENT', 1.5),
        'paystack_fee_flat_ngn' => (int) env('PAYSTACK_FEE_FLAT_NGN', 0),
        'paystack_fee_cap_ngn' => (int) env('PAYSTACK_FEE_CAP_NGN', 2000),
        // Keverna platform fee - 5% of room subtotal on marketplace
        // bookings. Uncapped in practice (10M cap is a safety rail
        // for wildly out-of-band values - real bookings never hit
        // it). Additive to the guest total AND deducted from the
        // operator settlement so the 5% is guaranteed on every
        // transaction. Marketplace-guest facing description of the
        // fee is "Keverna service fee".
        'keverna_fee_percent' => (float) env('KEVERNA_FEE_PERCENT', 5.0),
        'keverna_fee_cap_ngn' => (int) env('KEVERNA_FEE_CAP_NGN', 10_000_000),
        // Marketplace-only. Pay-at-property option collects this
        // percentage of the total up-front via Paystack; the
        // remainder is collected by the operator at check-in.
        'stays_deposit_percent' => (int) env('STAYS_DEPOSIT_PERCENT', 30),
        // Keverna Stays marketplace fee model: tiered % of the room
        // subtotal, DEDUCTED from the operator's Paystack payout
        // (guest never sees the line - sticker price = what they
        // pay + processing). 5% on bookings <= 100k, 2.5% above.
        // Threshold + tier percents are envvar-overridable so we
        // can tune per market / partner deal without a code change.
        'stays_fee_percent_default' => (float) env('STAYS_FEE_PERCENT_DEFAULT', 2.5),
        'stays_fee_percent_high_tier' => (float) env('STAYS_FEE_PERCENT_HIGH_TIER', 2.5),
        'stays_fee_tier_threshold_ngn' => (int) env('STAYS_FEE_TIER_THRESHOLD_NGN', 100_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription lifecycle (PRD §4.2)
    |--------------------------------------------------------------------------
    */
    'subscription' => [
        'grace_period_days' => (int) env('GRACE_PERIOD_DAYS', 3),
        'lock_to_archive_days' => (int) env('LOCK_TO_ARCHIVE_DAYS', 30),
        'renewal_reminder_days' => [30, 14, 7, 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance window (PRD §19)
    |--------------------------------------------------------------------------
    |
    | We don't put the platform in actual `php artisan down` mode during the
    | nightly window — that would 503 every booking. Instead we render a
    | non-blocking banner inside dashboard layouts ahead of and during the
    | window so operators know about pending downtime. Times are in WAT
    | (the IANA zone is Africa/Lagos — Nigeria has just one tz zone that
    | covers Abuja where we operate).
    */
    'maintenance_window' => [
        // Show "expect intermittent downtime" warning starting N hours before.
        'warn_hours_before' => (int) env('MAINTENANCE_WARN_HOURS_BEFORE', 2),
        // 24-hour format. Inclusive start, exclusive end. Default 02:00–04:00 WAT.
        'start_hour' => (int) env('MAINTENANCE_START_HOUR', 2),
        'end_hour' => (int) env('MAINTENANCE_END_HOUR', 4),
        // When false, no banner is ever shown — useful while we're
        // bedding in monitoring before announcing a window.
        'enabled' => env('MAINTENANCE_WINDOW_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Landing page branding (PRD §6.4 / §16)
    |--------------------------------------------------------------------------
    |
    | font_themes: each key is a class hook the landing layout can target.
    | sections: optional landing sections the operator can hide via toggles.
    | Both lists are duplicated nowhere — the editor + landing pull from
    | here so they can't drift apart.
    */
    'branding' => [
        'font_themes' => [
            'inter_playfair' => 'Inter + Playfair (default)',
            'inter_inter' => 'Inter (clean / minimal)',
            'lora_lora' => 'Lora (warm / boutique)',
            'poppins_poppins' => 'Poppins (modern hospitality)',
            'merriweather_montserrat' => 'Merriweather + Montserrat (heritage)',
        ],
        'sections' => [
            'amenities' => 'Amenities list',
            'map' => 'Location / map',
            'reviews' => 'Guest reviews',
            'contact_block' => 'Contact block',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest ID documents (Centurion Desk check-in)
    |--------------------------------------------------------------------------
    |
    | How long the ID seen at check-in (number, nationality, date of
    | birth, expiry, scan) is kept after the guest checks out, under the
    | Nigeria Data Protection Act. `guests:purge-id-documents` wipes it
    | daily once that passes, keeping only that an ID of a type was seen.
    | 12 months is a placeholder until the owner confirms the period.
    */
    'guest_id_retention_months' => (int) env('KEVERNA_GUEST_ID_RETENTION_MONTHS', 12),
];
