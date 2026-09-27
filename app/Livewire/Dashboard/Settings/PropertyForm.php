<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Settings;

use App\Actions\Paystack\CreatePaystackSubaccount;
use App\Enums\PropertyType;
use App\Models\Tenant;
use App\Services\Paystack\PaystackClient;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Property-level settings, operator can edit name, address, contact
 * information, description after onboarding without going through the
 * full Branding flow.
 */
#[Layout('layouts.tenant-auth')]
class PropertyForm extends Component
{
    public string $name = '';
    public string $description = '';
    /**
     * Free-form list of amenities shown on the property's stays page
     * and fed into the schema.org amenityFeature payload. Curated
     * presets are grouped in the view; anything not on the list can be
     * added as free text. Stored on Property.amenities as list<string>.
     *
     * @var list<string>
     */
    public array $amenities = [];
    // Optional YouTube URL - the stays detail page turns it into a
    // click-to-play video tour card. Kept as a plain URL string so
    // the operator pastes what YouTube shows in the address bar.
    public string $videoUrl = '';
    public string $address = '';
    public string $city = '';
    public string $state = '';
    // Optional coordinates for the guest self-service portal's
    // "Where we are" map. Strings on the form so the input is
    // typo-friendly ("6.5244" not 6.5244); coerced to floats on save.
    public string $latitude = '';
    public string $longitude = '';
    // Guest-portal geo-fence. Preset picker on the settings form
    // writes to these; "off" = 0 fence which disables the gate.
    public int $geoFenceRadiusMeters = 0;
    public int $guestCoordMinScore = 0;
    public string $contactEmail = '';
    public string $contactPhone = '';
    public string $type = 'hotel';
    // ISO 4217 code the property renders amounts in. Backfilled to
    // NGN on the migration; operator changes it here to switch the
    // whole surface (invoices, receipts, dashboard, POS) to their
    // local currency. Display only — no FX conversion.
    public string $currencyCode = 'NGN';
    // Which inventory model the property runs, central store vs
    // per-department. Drives sidebar visibility of the Store module.
    public string $stockModel = 'store_and_requisition';
    public bool $acceptsCorporates = false;

    /**
     * Public visibility switch. When off the tenant subdomain serves the
     * "coming soon" placeholder from {@see \App\Http\Controllers\Tenant\PublicLandingController};
     * when on, the auto-generated landing (or the operator's custom home
     * page) goes live. Default false on new properties so onboarding
     * doesn't expose a half-configured site.
     */
    public bool $isLive = false;

    /**
     * Keverna Stays marketplace consent bit. Distinct from `isLive`:
     * `isLive` controls the property's own subdomain landing;
     * `isListedPublicly` controls whether the property appears in
     * keverna.com/stays search + accepts card bookings taken by
     * Keverna on the property's behalf. Default true (opt-out) per
     * the marketplace launch decision — operators flip off here if
     * they want to keep operations off-market.
     */
    public bool $isListedPublicly = true;

    /**
     * How /stays online reservations hold inventory:
     *   'room'     — auto-picks a specific room at checkout (default).
     *   'category' — books the category only; front-desk assigns the
     *                room at check-in via the Reservations page.
     * Front-desk bookings ignore this flag.
     */
    public string $staysOnlineHoldMode = 'room';

    /**
     * Globally-unique slug that appears in the marketplace URL,
     * i.e. keverna.com/stays/{publicSlug}. Backfilled from the
     * property name; operator can rename it but collisions with
     * another Keverna property are rejected at save.
     */
    public string $publicSlug = '';

    /**
     * Paystack Subaccount setup. The operator picks a bank from the
     * cached /bank list and enters their NUBAN; we resolve the account
     * name via Paystack and register a subaccount on FregateLab's
     * master account. Guest bookings then auto-split, Keverna keeps
     * 2%, the operator's 98% settles directly to this bank.
     */
    public string $payoutBankCode = '';
    public string $payoutAccountNumber = '';

    /**
     * Snapshot of what's currently saved on the tenant, so the form
     * can show "Payouts going to GTBank · ••••1234, John Doe" without
     * exposing the full account number on screen.
     */
    public string $currentBankName = '';
    public string $currentAccountMask = '';
    public string $currentAccountName = '';
    public bool $hasSubaccount = false;

    /**
     * House rules shown on the public landing + each category-detail
     * page. Kept as a flat array so the Blade form can bind to
     * `wire:model="houseRules.cancellation_policy"` etc. without
     * fighting nested object syntax.
     *
     * @var array<string,string>
     */
    public array $houseRules = [
        'check_in_from' => '',
        'check_in_to' => '',
        'check_out_from' => '',
        'check_out_to' => '',
        'cancellation_policy' => '',
        'early_checkout' => '',
        'children_policy' => '',
        'pets_policy' => '',
        'smoking_policy' => '',
        'quiet_hours' => '',
    ];

    /**
     * Operator-editable list of nearby landmarks/transit options for
     * the public landing's "Places nearby" card. Each entry:
     *   ['label' => string, 'distance' => string, 'mode' => string]
     * `mode` is one of: walk, drive, transit.
     *
     * @var list<array{label: string, distance: string, mode: string}>
     */
    public array $nearbyPlaces = [];

    /**
     * Late-checkout billing policy - kept flat so the Blade form can
     * bind with `wire:model="checkoutPolicy.late_checkout_hourly_fee"`
     * without nested object wrangling. Persisted as the property's
     * `checkout_policy` JSON column; see Property::checkoutPolicy()
     * for the canonical accessor + default shape.
     *
     * @var array{
     *     late_checkout_enabled: bool,
     *     late_checkout_grace_minutes: int,
     *     late_checkout_hourly_fee: int,
     *     late_checkout_full_day_after_hours: int
     * }
     */
    public array $checkoutPolicy = [
        'late_checkout_enabled' => false,
        'late_checkout_grace_minutes' => 30,
        'late_checkout_hourly_fee' => 0,
        'late_checkout_full_day_after_hours' => 6,
        // Early-checkout: default_mode 'ask' preserves prior behaviour
        // (front desk picks each time). 'keep' / 'wallet' / 'cash'
        // auto-apply the picked resolution silently. refund_percent
        // is what fraction of unused nights the property refunds
        // (100 = full, 50 = half, 0 = non-refundable).
        'early_checkout_default_mode' => 'ask',
        'early_checkout_refund_percent' => 100,
    ];

    public ?string $payoutError = null;

    /**
     * Cached list of Nigerian banks for the bank dropdown. Pulled from
     * Paystack on mount and stashed on the component so re-renders
     * don't hammer the API. If Paystack is unreachable we degrade to
     * a free-text bank code input rather than blocking the rest of
     * the form.
     *
     * @var list<array{name: string, code: string}>
     */
    public array $banks = [];
    public bool $banksLoadFailed = false;

    public function mount(): void
    {
        $tenant = Tenant::current();
        // Edit the branch the operator has switched into via the sidebar
        // - falls back to the tenant's first property for pre-multi-branch
        // orgs so the settings screen keeps working exactly as before.
        $property = app(\App\Services\Properties\PropertyContext::class)->current()
            ?? $tenant?->property;
        if ($property !== null) {
            $this->name = (string) $property->name;
            $this->description = (string) $property->description;
            // Property.amenities historically stores a list<string>;
            // guard against legacy nulls or associative shapes so the
            // form always hydrates a clean sequential array.
            $this->amenities = array_values(array_filter(
                is_array($property->amenities) ? $property->amenities : [],
                fn ($v) => is_string($v) && trim($v) !== '',
            ));
            $this->videoUrl = (string) ($property->video_url ?? '');
            $this->address = (string) $property->address;
            $this->city = (string) $property->city;
            $this->state = (string) $property->state;
            $this->latitude = $property->latitude !== null ? (string) $property->latitude : '';
            $this->longitude = $property->longitude !== null ? (string) $property->longitude : '';
            $this->geoFenceRadiusMeters = (int) ($property->geo_fence_radius_meters ?? 0);
            $this->guestCoordMinScore = (int) ($property->guest_coord_min_score ?? 0);
            $this->type = $property->type?->value ?? 'hotel';
            $this->currencyCode = $property->currency->value;
            $this->stockModel = $property->stock_model?->value ?? 'store_and_requisition';
            $this->acceptsCorporates = (bool) $property->accepts_corporates;
            $this->isLive = (bool) $property->is_live;
            $this->isListedPublicly = (bool) $property->is_listed_publicly;
            $this->staysOnlineHoldMode = in_array($property->stays_online_hold_mode, ['room', 'category'], true)
                ? $property->stays_online_hold_mode
                : 'room';
            $this->publicSlug = (string) ($property->public_slug ?? '');

            $existing = is_array($property->nearby_places) ? $property->nearby_places : [];
            // Normalise shape so the view's array access never blows up.
            $this->nearbyPlaces = array_values(array_map(fn ($p): array => [
                'label'    => (string) ($p['label'] ?? ''),
                'distance' => (string) ($p['distance'] ?? ''),
                'mode'     => in_array($p['mode'] ?? 'drive', ['walk', 'drive', 'transit'], true)
                    ? (string) $p['mode']
                    : 'drive',
            ], $existing));

            // House rules: pull through the model's canonical accessor
            // so missing keys are filled with empty strings and stale
            // shape changes don't break the bind.
            $this->houseRules = $property->houseRules();
            $this->checkoutPolicy = $property->checkoutPolicy();
        }
        if ($tenant !== null) {
            $this->contactEmail = (string) ($tenant->email ?? '');
            $this->contactPhone = (string) ($tenant->phone ?? '');

            $this->refreshPayoutSnapshot($tenant);
        }

        // Pull the bank list once at mount. If Paystack is down we fall
        // through gracefully, the form still lets the operator save
        // their other settings while we surface an error banner.
        try {
            $banks = PaystackClient::forPlatform()->listBanks();
            // Strip down to (name, code) for the dropdown payload, we
            // don't need slug/longcode in the UI and they bloat the
            // Livewire wire snapshot.
            $this->banks = array_values(array_map(static fn (array $b): array => [
                'name' => $b['name'],
                'code' => $b['code'],
            ], $banks));
        } catch (Throwable $e) {
            $this->banksLoadFailed = true;
            $this->banks = [];
        }
    }

    /**
     * Preview payload for the Video tour field. Feeds an inline
     * iframe / <video> block on the form so the operator can see the
     * embed they'll ship without leaving the page.
     *
     * Delegates to {@see Property::videoEmbed()} so the settings form
     * and the marketplace show page agree on kind + URL shape.
     *
     * @return array{kind: string, embed_url?: string, src?: string, poster: ?string}|null
     */
    #[Computed]
    public function videoPreview(): ?array
    {
        $stub = new \App\Models\Property();
        $stub->video_url = trim($this->videoUrl);

        return $stub->videoEmbed();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:10000'],
            'amenities'   => ['array', 'max:60'],
            'amenities.*' => ['string', 'max:100'],
            'videoUrl' => [
                'nullable', 'string', 'max:400',
                // Accepts YouTube (watch/short/live/embed/youtu.be),
                // Vimeo (vimeo.com/{id}), and direct video files
                // (.mp4 / .webm / .mov / .m4v / .ogg / .ogv), with an
                // optional query string on the file URL. Rendering is
                // handled by Property::videoEmbed().
                'regex:~^https?://(?:(?:www\.|m\.)?(?:youtube\.com/(?:watch\?[^\#]*[?&]?v=|embed/|shorts/|live/|v/)[A-Za-z0-9_-]{11}|youtu\.be/[A-Za-z0-9_-]{11})|(?:www\.|player\.)?vimeo\.com/(?:video/)?\d+|[^\s]+?\.(?:mp4|webm|mov|m4v|ogg|ogv)(?:\?[^\s]*)?)~i',
            ],
            'address' => ['required', 'string', 'max:240'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geoFenceRadiusMeters' => ['integer', 'min:0', 'max:100000'],
            'guestCoordMinScore' => ['integer', 'min:0', 'max:100'],
            'type' => ['required', Rule::in(array_map(fn ($c) => $c->value, PropertyType::cases()))],
            'currencyCode' => ['required', Rule::in(array_map(fn ($c) => $c->value, \App\Enums\Currency::cases()))],
            'stockModel' => ['required', Rule::in(array_map(fn ($c) => $c->value, \App\Enums\StockModel::cases()))],
            'acceptsCorporates' => ['boolean'],
            'isLive' => ['boolean'],
            'isListedPublicly' => ['boolean'],
            'staysOnlineHoldMode' => ['required', 'in:room,category'],
            'publicSlug' => [
                'required_if:isListedPublicly,true',
                'nullable',
                'string',
                'min:3',
                'max:96',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('properties', 'public_slug')->ignore(
                    (app(\App\Services\Properties\PropertyContext::class)->current()
                        ?? Tenant::current()?->property)?->id
                ),
            ],
            'contactEmail' => ['nullable', 'email', 'max:180'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'houseRules.check_in_from' => ['nullable', 'date_format:H:i'],
            'houseRules.check_in_to' => ['nullable', 'date_format:H:i'],
            'houseRules.check_out_from' => ['nullable', 'date_format:H:i'],
            'houseRules.check_out_to' => ['nullable', 'date_format:H:i'],
            'houseRules.cancellation_policy' => ['nullable', 'string', 'max:1000'],
            'houseRules.early_checkout' => ['nullable', 'string', 'max:1000'],
            'houseRules.children_policy' => ['nullable', 'string', 'max:1000'],
            'houseRules.pets_policy' => ['nullable', 'string', 'max:1000'],
            'houseRules.smoking_policy' => ['nullable', 'string', 'max:1000'],
            'houseRules.quiet_hours' => ['nullable', 'string', 'max:80'],
            'checkoutPolicy.late_checkout_enabled' => ['boolean'],
            'checkoutPolicy.late_checkout_grace_minutes' => ['integer', 'min:0', 'max:720'],
            'checkoutPolicy.late_checkout_hourly_fee' => ['integer', 'min:0', 'max:10000000'],
            'checkoutPolicy.late_checkout_full_day_after_hours' => ['integer', 'min:0', 'max:24'],
            'checkoutPolicy.early_checkout_default_mode' => ['required', Rule::in(['ask', 'keep', 'wallet', 'cash'])],
            'checkoutPolicy.early_checkout_refund_percent' => ['integer', 'min:0', 'max:100'],
        ], [
            'houseRules.*.date_format' => 'Times must be HH:MM (24-hour, e.g. 13:00).',
            'publicSlug.regex' => 'Marketplace URL slug must be lowercase letters, numbers and hyphens (no spaces).',
            'publicSlug.unique' => 'That marketplace URL slug is taken by another property. Try adding your city or a differentiator.',
            'publicSlug.required_if' => 'Pick a marketplace URL slug when listing on Keverna Stays.',
            'videoUrl.regex' => 'Paste a YouTube or Vimeo link, or a direct .mp4 / .webm / .mov URL.',
        ]);

        $tenant = Tenant::current();
        // Edit the branch the operator has switched into via the sidebar
        // - falls back to the tenant's first property for pre-multi-branch
        // orgs so the settings screen keeps working exactly as before.
        $property = app(\App\Services\Properties\PropertyContext::class)->current()
            ?? $tenant?->property;
        if ($property === null) {
            return;
        }

        // Description is authored via a lightweight WYSIWYG that
        // outputs HTML with bold/italic/underline/link/list markup.
        // Sanitise through the allow-list HtmlSanitizer so a paste
        // from Word or a hostile clipboard can't smuggle scripts
        // through into the tenant landing (which renders it via
        // {!! !!} — unescaped).
        $descriptionHtml = app(\App\Services\Content\HtmlSanitizer::class)
            ->sanitize($data['description']);

        // Amenities normalised: trim, drop empties, dedupe case-insensitive,
        // cap at 20 (belt-and-braces with the validator). Preserves the
        // operator's ordering.
        $seen = [];
        $normalisedAmenities = [];
        foreach ((array) ($data['amenities'] ?? []) as $a) {
            $clean = trim((string) $a);
            if ($clean === '') {
                continue;
            }
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalisedAmenities[] = $clean;
            if (count($normalisedAmenities) >= 60) {
                break;
            }
        }

        $property->forceFill([
            'name' => $data['name'],
            'description' => $descriptionHtml !== '' ? $descriptionHtml : null,
            'amenities'   => $normalisedAmenities !== [] ? $normalisedAmenities : null,
            'video_url' => trim($data['videoUrl'] ?? '') !== '' ? trim($data['videoUrl']) : null,
            'address' => $data['address'],
            'city' => $data['city'] !== '' ? $data['city'] : null,
            'state' => $data['state'] !== '' ? $data['state'] : null,
            'latitude' => $data['latitude'] !== '' && $data['latitude'] !== null ? (float) $data['latitude'] : null,
            'longitude' => $data['longitude'] !== '' && $data['longitude'] !== null ? (float) $data['longitude'] : null,
            'geo_fence_radius_meters' => (int) $data['geoFenceRadiusMeters'],
            'guest_coord_min_score' => (int) $data['guestCoordMinScore'],
            'type' => $data['type'],
            'currency_code' => $data['currencyCode'],
            'stock_model' => $data['stockModel'],
            'accepts_corporates' => (bool) $data['acceptsCorporates'],
            'is_live' => (bool) $data['isLive'],
            'is_listed_publicly' => (bool) $data['isListedPublicly'],
            'stays_online_hold_mode' => $data['staysOnlineHoldMode'] ?? 'room',
            'public_slug' => ($data['publicSlug'] ?? '') !== '' ? $data['publicSlug'] : $property->public_slug,
        ])->save();

        if ($tenant !== null) {
            $tenant->forceFill([
                'email' => $data['contactEmail'] !== '' ? $data['contactEmail'] : null,
                'phone' => $data['contactPhone'] !== '' ? $data['contactPhone'] : null,
            ])->save();
        }

        // Persist nearby places, drop empty rows + cap to a sane
        // number so a runaway form post doesn't bloat the JSON.
        $places = collect($this->nearbyPlaces)
            ->map(fn ($p) => [
                'label'    => trim((string) ($p['label'] ?? '')),
                'distance' => trim((string) ($p['distance'] ?? '')),
                'mode'     => in_array($p['mode'] ?? 'drive', ['walk', 'drive', 'transit'], true)
                    ? (string) $p['mode']
                    : 'drive',
            ])
            ->filter(fn (array $p): bool => $p['label'] !== '')
            ->take(20)
            ->values()
            ->all();

        $property->forceFill(['nearby_places' => $places !== [] ? $places : null])->save();

        // Trim every rule field; if nothing is set, store null so the
        // landing page collapses the section automatically.
        $rules = array_map(static fn ($v): string => trim((string) $v), $this->houseRules);
        $hasAnyRule = false;
        foreach ($rules as $v) {
            if ($v !== '') {
                $hasAnyRule = true;
                break;
            }
        }
        $property->forceFill(['house_rules' => $hasAnyRule ? $rules : null])->save();

        // Checkout policy - persist an explicit payload rather than
        // null-when-disabled so the operator's typed-in numbers survive
        // a toggle-off/toggle-on cycle without needing re-entry.
        //
        // Before overwriting: snapshot the OLD policy onto every
        // in-house guest that doesn't already have one, so a policy
        // change today doesn't retroactively rewrite the rules a guest
        // was told at check-in yesterday. New arrivals from this point
        // pick up the new policy via MarkCheckedIn. Also stamp an
        // activity log so accounting can trace which policy applied
        // to any historical fee.
        $oldPolicySnapshot = \App\Models\Booking::buildCheckoutPolicySnapshot(
            $property,
            source: 'pre_change_backfill',
        );
        $newPolicy = [
            'late_checkout_enabled' => (bool) $data['checkoutPolicy']['late_checkout_enabled'],
            'late_checkout_grace_minutes' => (int) $data['checkoutPolicy']['late_checkout_grace_minutes'],
            'late_checkout_hourly_fee' => (int) $data['checkoutPolicy']['late_checkout_hourly_fee'],
            'late_checkout_full_day_after_hours' => (int) $data['checkoutPolicy']['late_checkout_full_day_after_hours'],
            'early_checkout_default_mode' => (string) $data['checkoutPolicy']['early_checkout_default_mode'],
            'early_checkout_refund_percent' => (int) $data['checkoutPolicy']['early_checkout_refund_percent'],
        ];
        $policyChanged = ($property->checkout_policy ?? []) != $newPolicy;

        if ($policyChanged) {
            // Backfill only CheckedIn rows on THIS property whose
            // snapshot is still null - a guest with an existing
            // snapshot has already been sealed by MarkCheckedIn or
            // the migration and mustn't be re-sealed.
            $affected = \App\Models\Booking::query()
                ->whereHas('room', fn ($r) => $r->where('property_id', $property->getKey()))
                ->where('booking_status', \App\Enums\BookingStatus::CheckedIn->value)
                ->whereNull('checkout_policy_snapshot')
                ->update(['checkout_policy_snapshot' => json_encode($oldPolicySnapshot)]);

            activity('property_policy')
                ->causedBy(auth('web')->user())
                ->performedOn($property)
                ->event('checkout_policy_changed')
                ->withProperties([
                    'before' => $property->checkout_policy,
                    'after' => $newPolicy,
                    'in_house_sealed' => $affected,
                ])
                ->log(sprintf(
                    'Checkout policy updated. %d in-house booking%s sealed with the previous policy.',
                    $affected,
                    $affected === 1 ? '' : 's',
                ));
        }

        $property->forceFill(['checkout_policy' => $newPolicy])->save();

        $this->dispatch('notify', title: 'Done', message: 'Property settings saved.', kind: 'ok');
    }

    /**
     * Verifies the bank account with Paystack and registers / updates a
     * Subaccount so future bookings auto-split. Runs as its own
     * Livewire action (not part of save()) because hitting Paystack's
     * /bank/resolve + /subaccount endpoints on every property-form
     * save would be wasteful and rate-limit-prone.
     */
    public function savePayoutAccount(CreatePaystackSubaccount $action): void
    {
        $this->payoutError = null;

        $this->validate([
            'payoutBankCode' => ['required', 'string', 'max:12'],
            'payoutAccountNumber' => ['required', 'string', 'regex:/^\d{10}$/'],
        ], [
            'payoutAccountNumber.regex' => 'Nigerian account numbers must be exactly 10 digits.',
        ]);

        $tenant = Tenant::current();
        if ($tenant === null) {
            return;
        }

        $bankName = collect($this->banks)
            ->firstWhere('code', $this->payoutBankCode)['name'] ?? null;

        try {
            $action->handle(
                tenant: $tenant,
                bankCode: $this->payoutBankCode,
                accountNumber: $this->payoutAccountNumber,
                bankName: $bankName,
            );
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response?->json();
            $msg = is_array($body) ? (string) ($body['message'] ?? '') : '';
            $this->payoutError = $msg !== ''
                ? "Paystack rejected the request: {$msg}"
                : 'Paystack rejected the request. Re-check the bank and account number.';

            return;
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $this->payoutError = "Couldn't reach Paystack right now. Try again in a moment.";

            return;
        } catch (Throwable $e) {
            $this->payoutError = $e->getMessage();

            return;
        }

        $this->refreshPayoutSnapshot($tenant->fresh());
        $this->payoutAccountNumber = '';
        $this->dispatch('notify', title: 'Done', message: 'Payout bank account verified, bookings will now settle here.', kind: 'ok');
    }

    public function addNearbyPlace(): void
    {
        $this->nearbyPlaces[] = ['label' => '', 'distance' => '', 'mode' => 'drive'];
    }

    public function removeNearbyPlace(int $index): void
    {
        unset($this->nearbyPlaces[$index]);
        $this->nearbyPlaces = array_values($this->nearbyPlaces);
    }

    public function render(): View
    {
        $tenant = Tenant::current();

        return view('livewire.dashboard.settings.property-form', [
            'propertyTypes' => PropertyType::cases(),
            'stockModels' => \App\Enums\StockModel::cases(),
            'currenciesByRegion' => \App\Enums\Currency::byRegion(),
            'publicUrl' => $tenant !== null ? 'https://'.$tenant->primaryHost().'/' : null,
        ]);
    }

    private function refreshPayoutSnapshot(?Tenant $tenant): void
    {
        if ($tenant === null) {
            return;
        }
        $this->hasSubaccount = (string) $tenant->paystack_subaccount_code !== '';
        $this->currentBankName = (string) ($tenant->paystack_bank_name ?? '');
        $this->currentAccountName = (string) ($tenant->paystack_account_name ?? '');

        $acct = (string) ($tenant->paystack_account_number ?? '');
        $this->currentAccountMask = $acct === ''
            ? ''
            : str_repeat('•', max(0, strlen($acct) - 4)).substr($acct, -4);

        // Pre-fill the bank dropdown with the saved code so the
        // operator sees their current bank selected if they navigate
        // back to update payouts.
        if ($this->payoutBankCode === '' && $tenant->paystack_bank_code) {
            $this->payoutBankCode = (string) $tenant->paystack_bank_code;
        }
    }
}
