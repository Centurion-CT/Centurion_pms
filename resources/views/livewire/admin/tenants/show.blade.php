@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    $statusClass = match ($tenant->status->value) {
        'active', 'trial' => 'lp-tag--confirmed',
        'grace'           => 'lp-tag--checked-in',
        'locked'          => 'lp-tag--checked-out',
        'archived'        => 'lp-tag--checked-out',
        default           => 'lp-tag--checked-in',
    };
@endphp

<div class="lp-dash">

    {{-- ── Back + header ────────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="lp-eyebrow-strip">Tenant</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    {{ $tenant->name }}
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    <code class="lp-mono" style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $tenant->slug }}</code>
                </p>
            </div>
            <span data-testid="tenant-status" class="lp-tag {{ $statusClass }}" style="align-self: flex-start;">
                {{ $tenant->status->value }}
            </span>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">

        {{-- ── Summary ──────────────────────────────────────────── --}}
        <section data-testid="tenant-summary" class="lp-dash-card lg:col-span-2">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Summary</span>
                <span class="lp-dash-card-meta">Created {{ $tenant->created_at?->toFormattedDateString() }}</span>
            </div>

            <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2" style="margin-top: 8px;">
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Slug</dt>
                    <dd class="lp-mono mt-1" style="color: var(--color-ink); font-size: 13px;">{{ $tenant->slug }}</dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Email</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">{{ $tenant->email ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Phone</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">{{ $tenant->phone ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Status</dt>
                    <dd class="mt-1">
                        <span class="lp-tag {{ $statusClass }}">{{ $tenant->status->value }}</span>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Subscription</dt>
                    <dd data-testid="tenant-subscription" class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">
                        @if ($activeSubscription)
                            <span class="lp-tag lp-tag--confirmed">{{ $activeSubscription->status->value }}</span>
                            <span style="margin-left: 8px; color: var(--color-ink-soft);">
                                expires {{ $activeSubscription->expires_at?->toFormattedDateString() }}
                            </span>
                        @else
                            <span style="color: var(--color-ink-mute);">None</span>
                        @endif
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Custom domain</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">
                        @if ($tenant->custom_domain)
                            <code class="lp-mono" style="font-size: 12.5px;">{{ $tenant->custom_domain }}</code>
                            <span class="lp-tag {{ $tenant->domain_verified_at ? 'lp-tag--confirmed' : 'lp-tag--checked-in' }}"
                                  style="margin-left: 8px;">
                                {{ $tenant->domain_verified_at ? 'verified' : 'pending' }}
                            </span>
                        @else
                            <span style="color: var(--color-ink-mute);">-</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        {{-- ── Actions ──────────────────────────────────────────── --}}
        <section data-testid="admin-actions" class="lp-dash-card" style="display: grid; gap: 22px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Actions</span>
            </div>

            <div>
                <label for="extend-days" class="lp-form-label">Extend subscription (days)</label>
                <div class="flex gap-2 mt-1">
                    <input id="extend-days" type="number" wire:model="extendDays" min="1" max="365"
                           data-testid="extend-days" class="lp-input">
                    <button type="button" wire:click="extend" data-testid="extend-subscription"
                            class="lp-pill" style="white-space: nowrap;">
                        Extend <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
                @error('extendDays') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            @if ($tenant->custom_domain)
                <div>
                    <p class="lp-form-label">Custom domain</p>
                    <button type="button" wire:click="reverifyDomain" data-testid="reverify-domain"
                            class="lp-btn">Re-verify domain</button>
                </div>
            @endif

            {{-- Onboarding OTP resend, only rendered when the founder operator
                 still has an unverified channel, so a fully-verified tenant
                 doesn't show a dead affordance. --}}
            @if ($founder && (! $founder->email_verified_at || ! $founder->phone_verified_at))
                <div data-testid="resend-verification">
                    <p class="lp-form-label">Pending onboarding</p>
                    <p class="lp-form-help" style="margin-top: 2px; margin-bottom: 8px;">
                        {{ $founder->email }} · resends bypass the 60s cooldown
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @unless ($founder->email_verified_at)
                            <button type="button" wire:click="resendEmailVerification"
                                    data-testid="resend-email-otp"
                                    wire:loading.attr="disabled" wire:target="resendEmailVerification"
                                    class="lp-btn">
                                <span wire:loading.remove wire:target="resendEmailVerification">Resend email code</span>
                                <span wire:loading wire:target="resendEmailVerification">Sending…</span>
                            </button>
                        @endunless
                        @unless ($founder->phone_verified_at)
                            <button type="button" wire:click="resendPhoneVerification"
                                    data-testid="resend-phone-otp"
                                    wire:loading.attr="disabled" wire:target="resendPhoneVerification"
                                    class="lp-btn">
                                <span wire:loading.remove wire:target="resendPhoneVerification">Resend WhatsApp code</span>
                                <span wire:loading wire:target="resendPhoneVerification">Sending…</span>
                            </button>
                        @endunless
                    </div>
                </div>
            @endif

            <div>
                <p class="lp-form-label">Impersonate</p>
                <button type="button" wire:click="impersonate" data-testid="impersonate"
                        class="lp-btn">Generate one-time link</button>
                <p class="lp-form-help" style="margin-top: 6px;">15-minute SHA-256 token, single-use.</p>
                @if ($impersonationUrl)
                    <p data-testid="impersonation-url"
                       class="lp-mono mt-3"
                       style="font-size: 11.5px; word-break: break-all;
                              background: var(--color-card-deep); border: 1px solid var(--color-line-soft);
                              border-radius: 10px; padding: 10px 12px; color: var(--color-ink);">
                        {{ $impersonationUrl }}
                    </p>
                @endif
            </div>

            @if (auth('admin')->user()?->role === 'super')
                <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px; margin-top: 4px;">
                    <p class="lp-form-label" style="color: #A8650A;">Data cleanup</p>
                    <a href="{{ route('admin.tenants.cleanup', $tenant) }}" wire:navigate
                       data-testid="tenant-cleanup-link"
                       class="lp-btn"
                       style="border-color: #A8650A; color: #A8650A;">
                        Purge test bookings…
                    </a>
                    <p class="lp-form-help" style="margin-top: 6px;">
                        Wipe self-service test records so the property starts clean.
                    </p>
                </div>

                <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px; margin-top: 4px;">
                    <p class="lp-form-label" style="color: #B85C42;">Danger zone</p>
                    <a href="{{ route('admin.tenants.destroy', $tenant) }}" wire:navigate
                       data-testid="tenant-destroy-link"
                       class="lp-btn"
                       style="border-color: #B85C42; color: #B85C42;">
                        Delete tenant + all data…
                    </a>
                    <p class="lp-form-help" style="margin-top: 6px;">
                        Wipes every row belonging to this tenant. Requires 2FA + a
                        typed-slug confirmation. There is no undo.
                    </p>
                </div>
            @endif
        </section>
    </div>

    {{-- Properties panel with a one-click entry into concierge content
         import per property. Sits below the summary + actions so the
         admin sees "which properties can I onboard now" without hunting
         through URLs. --}}
    <section class="lp-dash-card" data-testid="admin-tenant-properties" style="margin-top: 18px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Properties</span>
            <span class="lp-dash-card-meta">
                {{ $properties->count() }} {{ \Illuminate\Support\Str::plural('property', $properties->count()) }}
                · concierge content import lives here
            </span>
        </div>
        @if ($properties->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                This tenant hasn't created any properties yet.
            </p>
        @else
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Property</th>
                        <th>City</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($properties as $p)
                        <tr data-testid="admin-tenant-property-row-{{ $p->id }}">
                            <td style="font-weight: 500; color: var(--color-ink);">
                                {{ $p->name }}
                            </td>
                            <td class="text-[12.5px]" style="color: var(--color-ink-mute);">
                                {{ $p->city ?? '-' }}
                            </td>
                            <td class="text-right">
                                <a href="{{ route('admin.tenants.properties.import-content', ['tenant' => $tenant, 'propertyId' => $p->id]) }}"
                                   wire:navigate
                                   class="lp-btn lp-btn--compact"
                                   data-testid="admin-tenant-property-import-{{ $p->id }}">
                                    Import content →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
