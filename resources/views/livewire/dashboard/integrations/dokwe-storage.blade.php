<div class="lp-dash">

    {{-- ── Back link + header ───────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">Integration</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Dokwe Storage
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Route every new upload to a Dokwe Storage space you own (property photos, room photos, guest invoices, PDFs).
                Existing media stays where it was saved; only new uploads flow to your bucket.
                Full setup guide: <a href="https://dokwe.com/docs/storage" target="_blank" rel="noopener"
                                       style="color: var(--color-accent-deep); text-decoration: underline;">dokwe.com/docs/storage</a>.
            </p>
        </div>
    </div>

    {{-- ── Status banner ────────────────────────────────────────── --}}
    @php
        $accent = $status['connected'] ? 'var(--color-accent-deep)' : '#B85C42';
        $tint   = $status['connected'] ? 'var(--color-accent-soft, rgba(51, 103, 153,0.10))' : 'rgba(224, 122, 95, 0.06)';
    @endphp
    <div data-testid="dokwe-status"
         class="lp-dash-card"
         style="border-left: 3px solid {{ $accent }}; background: {{ $tint }};">
        @if ($status['connected'])
            <p class="text-[13.5px]" style="color: var(--color-ink); font-weight: 500;">
                Connected to <span class="lp-mono">{{ $status['bucket'] }}</span>
                since {{ optional($status['connected_at'])->format('M j, Y') ?? '-' }}.
                New uploads land here until you disconnect.
            </p>
            <button type="button" wire:click="disconnect"
                    wire:confirm="Disconnect this Dokwe Storage space? New uploads will fall back to the platform default. Existing files stay in your space."
                    class="lp-btn lp-btn--danger mt-3"
                    data-testid="dokwe-disconnect">
                Disconnect
            </button>
        @else
            <p class="text-[13.5px]" style="color: var(--color-ink); font-weight: 500;">
                Not connected. Uploads land on the platform's default storage.
            </p>
            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                Provision a space in <a href="https://dokwe.com/dashboard/storage" target="_blank" rel="noopener"
                    style="color: var(--color-accent-deep); text-decoration: underline;">your Dokwe dashboard</a>,
                mint a read-write key (Storage → your space → Keys → Mint Key), and paste both below.
            </p>
        @endif
    </div>

    {{-- ── Setup form ───────────────────────────────────────────── --}}
    <form wire:submit="connect" class="lp-dash-card" style="display: grid; gap: 22px;">

        <div>
            <label for="dokwe-bucket" class="lp-form-label">Space (bucket) name</label>
            <input id="dokwe-bucket" type="text" wire:model.live.debounce.400ms="bucket"
                   data-testid="dokwe-bucket" required class="lp-input"
                   placeholder="chidi-hotel" autocomplete="off">
            <p class="lp-form-help mt-1">
                The slug of your Dokwe space. Typing this auto-fills the two URLs below to Dokwe's default hostnames.
            </p>
            @error('bucket') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="dokwe-endpoint" class="lp-form-label">S3 endpoint</label>
                <input id="dokwe-endpoint" type="url" wire:model="endpoint" required
                       data-testid="dokwe-endpoint" class="lp-input"
                       placeholder="https://chidi-hotel-s3.dokwe.com">
                @error('endpoint') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="dokwe-public-url" class="lp-form-label">Public / CDN URL</label>
                <input id="dokwe-public-url" type="url" wire:model="publicUrl" required
                       data-testid="dokwe-public-url" class="lp-input"
                       placeholder="https://chidi-hotel-file.dokwe.com">
                @error('publicUrl') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="dokwe-access-key" class="lp-form-label">Access key</label>
                <input id="dokwe-access-key" type="text" wire:model="accessKey" required
                       data-testid="dokwe-access-key" class="lp-input"
                       placeholder="dkwk_xxxxxxxxxxxxxxxx"
                       autocomplete="off" spellcheck="false">
                @error('accessKey') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="dokwe-secret-key" class="lp-form-label">Secret key</label>
                <input id="dokwe-secret-key" type="password" wire:model="secretKey" required
                       data-testid="dokwe-secret-key" class="lp-input"
                       placeholder="Only visible once at mint time"
                       autocomplete="off" spellcheck="false">
                <p class="lp-form-help mt-1">
                    Dokwe shows the secret <strong style="color: var(--color-ink);">once</strong> when you mint it. Paste it here now. We encrypt it at rest with your app key.
                </p>
                @error('secretKey') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="dokwe-region" class="lp-form-label">Region</label>
            <input id="dokwe-region" type="text" wire:model="region"
                   data-testid="dokwe-region" class="lp-input"
                   style="max-width: 220px;"
                   placeholder="us-east-1">
            <p class="lp-form-help mt-1">
                Dokwe ignores the region on the server side but the S3 protocol requires one. Leave as <code>us-east-1</code> unless the docs tell you otherwise.
            </p>
            @error('region') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.integrations.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-btn lp-btn--primary" data-testid="dokwe-connect">
                <span wire:loading.remove wire:target="connect">
                    {{ $status['connected'] ? 'Test &amp; update credentials' : 'Test &amp; connect' }}
                </span>
                <span wire:loading wire:target="connect">Talking to Dokwe&hellip;</span>
            </button>
        </div>
    </form>
</div>
