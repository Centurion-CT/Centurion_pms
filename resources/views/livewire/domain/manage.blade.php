@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    $address = $tenant->slug.$suffix;
    $domain = $tenant->custom_domain;

    // One state for the own domain, in the order it moves through them.
    $state = match (true) {
        $domain === null => null,
        $tenant->ownDomainIsLive() => 'live',
        $tenant->domain_verified_at !== null && $tenant->custom_domain_failed_checks >= \App\Actions\Domains\CheckOwnDomain::MISSES_BEFORE_PAUSE => 'not-reaching',
        $tenant->domain_verified_at !== null => 'verified',
        $tenant->custom_domain_status === \App\Enums\CustomDomainStatus::Error => 'not-found',
        default => 'waiting',
    };
    [$tagLabel, $tagClass] = match ($state) {
        'live' => ['Live', 'lp-tag--confirmed'],
        'verified' => ['Verified', 'lp-tag--pending'],
        'not-reaching' => ['Not reaching us', 'lp-tag--cancelled'],
        'not-found' => ['Record not found', 'lp-tag--cancelled'],
        default => ['Waiting for DNS', 'lp-tag--pending'],
    };
@endphp

<div class="lp-dash">

    {{-- ── Page header ────────────────────────────────────────────── --}}
    <div>
        <span class="lp-eyebrow-strip">Business</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Domain
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Where guests find your booking site and your staff log in.
        </p>
    </div>

    {{-- Save confirmations surface via the global notify modal. --}}

    {{-- ── Address ──────────────────────────────────────────────── --}}
    <section data-testid="slug-section" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Your {{ config('brand.name') }} address</span>
        </div>
        <p class="text-[13.5px]" style="color: var(--color-ink-soft);">
            Your booking site and staff login live at
            <a href="https://{{ $address }}" class="lp-mono" style="color: var(--color-ink); font-weight: 500;">{{ $address }}</a>.
            You can change it once a year. The old address keeps redirecting for 90 days, and you stay signed in.
        </p>

        <form wire:submit="renameSlug" data-testid="slug-form" class="mt-5" style="display: grid; gap: 14px;">
            <div>
                <label for="slug-input" class="lp-form-label">New address</label>
                <div class="flex items-center gap-3">
                    <input id="slug-input" type="text" wire:model="slug" autocomplete="off" spellcheck="false"
                           data-testid="slug-input" required class="lp-input" style="max-width: 280px;">
                    <span class="lp-mono text-[13px]" style="color: var(--color-ink-mute);">{{ $suffix }}</span>
                </div>
                @error('slug') <p data-error="slug" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end">
                <button type="submit" class="lp-btn lp-btn--primary">
                    <span wire:loading.remove wire:target="renameSlug">Change address</span>
                    <span wire:loading wire:target="renameSlug">Changing…</span>
                </button>
            </div>
        </form>
    </section>

    {{-- ── Own domain ───────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Your own domain</span>
            @if ($state)
                <span data-testid="domain-status" class="lp-tag {{ $tagClass }}">{{ $tagLabel }}</span>
            @endif
        </div>

        <p class="text-[13.5px]" style="color: var(--color-ink-soft);" data-testid="domain-state">
            @switch($state)
                @case('live')
                    Guests on <span class="lp-mono">{{ $address }}</span> are sent to
                    <a href="https://{{ $domain }}" class="lp-mono" style="color: var(--color-ink);">{{ $domain }}</a>.
                    Staff can log in on either address.
                    @break
                @case('verified')
                    {{ $domain }} is yours. Once it reaches us over HTTPS, guests move to it. Point it at us with the
                    record below; the security certificate can take up to an hour after that.
                    @break
                @case('not-reaching')
                    {{ $domain }} isn't reaching us over HTTPS, so guests stay on <span class="lp-mono">{{ $address }}</span>
                    for now. Check the record below. We check again every hour.
                    @break
                @case('not-found')
                    We couldn't find the TXT record after 48 hours. Check it below, then check again.
                    @break
                @case('waiting')
                    Add the records below at the company you bought the domain from. We check every 15 minutes.
                    @break
                @default
                    Use a domain you own, like <span class="lp-mono" style="color: var(--color-ink);">book.yourbrand.com</span>
                    or <span class="lp-mono" style="color: var(--color-ink);">yourbrand.com</span>, for your booking site.
                    <span class="lp-mono">{{ $address }}</span> keeps working.
            @endswitch
        </p>

        <form wire:submit="attach" data-testid="attach-form" class="mt-5" style="display: grid; gap: 14px;">
            <div>
                <label for="domain-input" class="lp-form-label">Domain</label>
                <input id="domain-input" type="text" wire:model="custom_domain" autocomplete="off" spellcheck="false"
                       placeholder="book.yourbrand.com" required class="lp-input">
                @error('custom_domain') <p data-error="custom_domain" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-3">
                @if ($domain)
                    <button type="button" wire:click="remove" wire:confirm="Remove {{ $domain }}? Guests will use {{ $address }} again."
                            data-testid="remove-domain" class="lp-btn">Remove domain</button>
                @endif
                <button type="submit" class="lp-pill">
                    <span wire:loading.remove wire:target="attach">{{ $domain ? 'Change domain' : 'Add domain' }}</span>
                    <span wire:loading wire:target="attach">Saving…</span>
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>

        {{-- ── DNS records ───────────────────────────────────────── --}}
        @if ($domain)
            <div data-testid="dns-instructions" class="mt-6 pt-6"
                 style="border-top: 1px solid var(--color-line-soft); display: grid; gap: 16px;">
                <div>
                    <p class="lp-form-label">DNS records</p>
                    <p class="text-[13px] mt-1" style="color: var(--color-ink-soft);">
                        One record points the domain at us. The TXT record shows it's yours.
                        @if ($aRecords)
                            For a subdomain like book.yourbrand.com use the CNAME. For the bare domain, yourbrand.com,
                            use the A {{ count($aRecords) === 1 ? 'record' : 'records' }} instead: a bare domain can't have a CNAME.
                        @endif
                    </p>
                </div>

                <div class="overflow-x-auto" style="border: 1px solid var(--color-line); border-radius: 12px;">
                    <table class="lp-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Name</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="lp-tag lp-tag--pending" style="font-size: 10px;">CNAME</span></td>
                                <td><span class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">{{ $domain }}</span></td>
                                <td>
                                    <span data-testid="cname-target" class="lp-mono text-[11.5px]"
                                          style="color: var(--color-ink); font-weight: 500;">{{ $cnameTarget }}</span>
                                </td>
                            </tr>
                            @foreach ($aRecords as $ip)
                                <tr>
                                    <td><span class="lp-tag lp-tag--pending" style="font-size: 10px;">A</span></td>
                                    <td><span class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">{{ $domain }}</span></td>
                                    <td>
                                        <span data-testid="a-record" class="lp-mono text-[11.5px]"
                                              style="color: var(--color-ink); font-weight: 500;">{{ $ip }}</span>
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <td><span class="lp-tag lp-tag--pending" style="font-size: 10px;">TXT</span></td>
                                <td>
                                    <span data-testid="txt-name" class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                        {{ $verifyName }}.{{ $domain }}
                                    </span>
                                </td>
                                <td>
                                    <span data-testid="txt-token" class="lp-mono text-[11.5px]"
                                          style="color: var(--color-ink); font-weight: 500; word-break: break-all;">
                                        {{ $tenant->custom_domain_verification_token }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="checkAgain" data-testid="reverify" class="lp-btn">
                        <svg width="13" height="13" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11.5 3.5v3h-3M2.5 10.5v-3h3"/>
                            <path d="M11 6a4 4 0 0 0-7.6-1M3 8a4 4 0 0 0 7.6 1"/>
                        </svg>
                        <span wire:loading.remove wire:target="checkAgain">Check again</span>
                        <span wire:loading wire:target="checkAgain">Checking…</span>
                    </button>
                </div>
            </div>
        @endif
    </section>
</div>
