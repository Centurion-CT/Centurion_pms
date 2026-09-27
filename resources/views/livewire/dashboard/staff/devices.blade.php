<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Workspace</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Linked devices
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 68ch;">
            Phones + POS terminals paired to your account. Revoking a device boots that
            phone on its next request, and the operator can re-pair from the app or a POS
            terminal at any time.
        </p>
    </div>

    {{-- ─── Pair a phone (Keverna mobile app) ────────────────────
         Any signed-in operator can generate a one-shot code for
         their own phone. The QR encodes {base_url, code}; the
         Flutter app scans, calls /api/mobile/v1/pair, and gets a
         Sanctum token bound to a fresh MobileDevice row. Codes
         expire in 5 minutes. --}}
    <section class="lp-dash-card" data-testid="mobile-pair-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Link the Keverna mobile app</span>
            @if ($mobileCode !== null)
                <button type="button" wire:click="cancelPhoneLink"
                        data-testid="mobile-pair-cancel"
                        class="lp-mono text-[11px]"
                        style="color: var(--color-ink-mute); text-decoration: underline;
                               letter-spacing: 0.06em; text-transform: uppercase;
                               background: transparent; border: 0; cursor: pointer;">
                    Cancel
                </button>
            @endif
        </div>

        @if ($mobileCode === null)
            <p class="text-[13.5px]" style="color: var(--color-ink-soft); margin: 0 0 12px;">
                Get a one-time code + QR to sign your phone in. Codes expire after 5 minutes.
                Sign-in inherits your role and whoever generates the code is who the phone acts as.
            </p>
            <button type="button" wire:click="linkPhone"
                    data-testid="mobile-pair-start"
                    class="lp-pill">
                Generate pairing code →
            </button>
        @else
            <div class="grid gap-4 sm:grid-cols-[240px_1fr] items-start">
                <div style="padding: 12px; background: #FFFFFF; border-radius: 12px;
                            border: 1px solid var(--color-line-soft); display: inline-block;"
                     data-testid="mobile-pair-qr">
                    {!! $mobileQrSvg !!}
                </div>
                <div>
                    <p class="text-[13.5px]" style="color: var(--color-ink-soft); margin: 0 0 10px;">
                        Open the Keverna app on your phone, tap <strong style="color: var(--color-ink);">Pair a device</strong>,
                        and scan the QR above.
                    </p>
                    <p class="text-[13.5px]" style="color: var(--color-ink-soft); margin: 10px 0;">
                        Camera not working? Type this code into the app instead:
                    </p>
                    <div class="lp-mono"
                         data-testid="mobile-pair-code"
                         style="display: inline-block; padding: 10px 16px;
                                background: var(--color-card-deep); color: var(--color-ink);
                                font-size: 22px; font-weight: 600; letter-spacing: 0.2em;
                                border-radius: 8px; user-select: all;">
                        {{ $mobileCode }}
                    </div>
                    <p class="text-[12px] lp-mono" style="color: var(--color-ink-mute); margin-top: 12px;
                              letter-spacing: 0.06em; text-transform: uppercase;">
                        Expires
                        <time datetime="{{ $mobileCodeExpiresAt }}">
                            in 5 minutes
                        </time>
                    </p>
                </div>
            </div>
        @endif
    </section>

    {{-- ─── Paired mobile devices ────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="mobile-devices-table">
        <div class="lp-dash-card-head" style="padding: 16px 18px;">
            <span class="lp-dash-card-title">Mobile phones</span>
            <span class="lp-mono text-[11px]"
                  style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
                {{ $mobileDevices->where('revoked_at', null)->count() }} active
            </span>
        </div>

        @if ($mobileDevices->isEmpty())
            <div style="padding: 24px; text-align: center; color: var(--color-ink-soft);">
                <p class="text-[13.5px]" style="margin: 0; color: var(--color-ink-mute);">
                    No phones linked yet. Use the pairing card above to sign in.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Operator</th>
                            <th>Device</th>
                            <th>Paired</th>
                            <th>Last seen</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mobileDevices as $m)
                            @php $isRevoked = $m->revoked_at !== null; @endphp
                            <tr data-testid="mobile-device-row-{{ $m->id }}"
                                style="{{ $isRevoked ? 'opacity: 0.55;' : '' }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $m->operator?->name ?? '-' }}
                                    <span style="display: block; color: var(--color-ink-mute); font-size: 12px; font-weight: 400;">
                                        {{ $m->operator?->email }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink);">
                                    {{ $m->device_name }}
                                    <span style="display: block; color: var(--color-ink-mute); font-size: 11.5px;
                                                 letter-spacing: 0.06em; text-transform: uppercase;">
                                        {{ $m->platform }} · v{{ $m->app_version }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 13px;">
                                    {{ $m->paired_at?->diffForHumans() }}
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 13px;">
                                    {{ $m->last_seen_at?->diffForHumans() ?? 'never' }}
                                </td>
                                <td>
                                    @if ($isRevoked)
                                        <span class="lp-tag lp-tag--cancelled">Revoked</span>
                                    @else
                                        <span class="lp-tag lp-tag--confirmed">Active</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    @if (! $isRevoked)
                                        <button type="button" wire:click="revokeMobile({{ $m->id }})"
                                                wire:confirm="Revoke {{ $m->device_name }}? The phone drops on its next request."
                                                data-testid="mobile-device-revoke-{{ $m->id }}"
                                                class="lp-mono text-[11px]"
                                                style="color: #B85C42; text-decoration: underline;
                                                       letter-spacing: 0.06em; text-transform: uppercase;
                                                       background: transparent; border: 0; cursor: pointer;">
                                            Revoke
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ─── POS terminals (session-cookie based) ─────────────── --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="devices-table">
        <div class="lp-dash-card-head" style="padding: 16px 18px;">
            <span class="lp-dash-card-title">POS terminals</span>
            <span class="lp-mono text-[11px]"
                  style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
                {{ $devices->where('revoked_at', null)->count() }} active
            </span>
        </div>
        @if ($devices->isEmpty())
            <div style="padding: 32px 24px; text-align: center; color: var(--color-ink-soft);">
                <p class="text-[14.5px]" style="margin: 0 0 6px; color: var(--color-ink);">
                    No linked devices yet
                </p>
                <p class="text-[13px]" style="color: var(--color-ink-mute); max-width: 42ch; margin: 0 auto;">
                    Staff can pair a phone from any POS terminal. Look for the
                    &ldquo;Link a phone&rdquo; button in the kiosk header.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Operator</th>
                            <th>Device</th>
                            <th>Paired</th>
                            <th>Last seen</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($devices as $d)
                            @php
                                $isRevoked = $d->revoked_at !== null;
                            @endphp
                            <tr data-testid="device-row-{{ $d->id }}"
                                style="{{ $isRevoked ? 'opacity: 0.55;' : '' }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $d->operator?->name ?? '-' }}
                                    <span style="display: block; color: var(--color-ink-mute); font-size: 12px; font-weight: 400;">
                                        {{ $d->operator?->email }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $d->device_label }}
                                    @if ($d->paired_ip)
                                        <span style="display: block; color: var(--color-ink-mute); font-size: 11.5px;">
                                            from {{ $d->paired_ip }}
                                        </span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $d->paired_at?->toFormattedDateString() }}
                                    <span style="display: block; color: var(--color-ink-mute); font-size: 11.5px;">
                                        {{ $d->paired_at?->diffForHumans() }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $d->last_seen_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td>
                                    @if ($isRevoked)
                                        <span class="lp-tag lp-tag--on-hold">Revoked</span>
                                        @if ($d->revokedBy)
                                            <span style="display: block; color: var(--color-ink-mute); font-size: 11.5px; margin-top: 3px;">
                                                by {{ $d->revokedBy->name }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="lp-tag lp-tag--confirmed">Active</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    @if (! $isRevoked)
                                        <button type="button" wire:click="revoke({{ $d->id }})"
                                                wire:confirm="Revoke {{ $d->device_label }}? The phone will drop on its next action."
                                                data-testid="device-revoke-{{ $d->id }}"
                                                style="color: #B85C42; background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: 12.5px;">
                                            Revoke
                                        </button>
                                    @else
                                        <button type="button" wire:click="delete({{ $d->id }})"
                                                wire:confirm="Delete this device record permanently?"
                                                data-testid="device-delete-{{ $d->id }}"
                                                style="color: var(--color-ink-mute); background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: 12.5px;">
                                            Delete
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
