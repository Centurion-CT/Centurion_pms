<div class="lp-dash">
    <div style="max-width: 640px;">
        @include('_partials._back-button')
        <span class="lp-eyebrow-strip" style="margin-top: 12px;">Danger zone</span>
        <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
            style="color: #B85C42; font-weight: 600; line-height: 1.04;">
            Delete tenant {{ $tenant->name }}
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink); max-width: 560px;">
            This wipes every row belonging to this tenant across every table:
            properties, rooms, bookings, payments, staff, guests, requisitions,
            housekeeping, audits within the tenant scope. The tenant subscription
            is cancelled. <strong>There is no undo.</strong>
        </p>

        <section class="lp-dash-card" style="margin-top: 20px; border: 1px solid rgba(184, 92, 66, 0.35);">
            <form wire:submit.prevent="destroy">
                <div>
                    <label for="dt-slug" class="lp-form-label">Type <code>{{ $tenant->slug }}</code> to confirm</label>
                    <input id="dt-slug" type="text" wire:model="slugConfirm" required autocomplete="off"
                           class="lp-input lp-mono" placeholder="{{ $tenant->slug }}"
                           data-testid="destroy-slug-confirm">
                    @error('slugConfirm') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div style="margin-top: 16px;">
                    <label for="dt-reason" class="lp-form-label">Reason (goes into the audit log)</label>
                    <textarea id="dt-reason" wire:model="reason" required rows="3"
                              class="lp-input"
                              placeholder="Fraudulent signup / GDPR erasure request / duplicate account / ..."
                              data-testid="destroy-reason"></textarea>
                    @error('reason') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div style="margin-top: 16px;">
                    <label for="dt-totp" class="lp-form-label">Current 2FA code from your authenticator</label>
                    <input id="dt-totp" type="text" inputmode="numeric" maxlength="6"
                           wire:model="totpCode" required autocomplete="one-time-code"
                           class="lp-input lp-mono" style="letter-spacing: 0.2em; font-size: 18px; width: 180px;"
                           placeholder="000 000"
                           data-testid="destroy-totp">
                    <p class="lp-form-help" style="margin-top: 6px;">
                        Recovery codes do NOT work here - this action requires the live second factor.
                    </p>
                    @error('totpCode') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div style="margin-top: 28px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="lp-pill" style="background: #B85C42; color: #fff;"
                            wire:loading.attr="disabled" wire:target="destroy"
                            data-testid="destroy-submit">
                        <span wire:loading.remove wire:target="destroy">Delete tenant permanently</span>
                        <span wire:loading wire:target="destroy">Wiping…</span>
                        <span class="lp-pill-dot" style="background: rgba(255,255,255,0.25); color: #fff;">
                            <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h8M4 3V2h4v1M4.5 6v3M7.5 6v3M3 3l0.5 8h5L9 3"/></svg>
                        </span>
                    </button>
                    <a href="{{ route('admin.tenants.show', $tenant) }}" class="lp-btn lp-btn--ghost">
                        Cancel
                    </a>
                </div>
            </form>
        </section>
    </div>
</div>
