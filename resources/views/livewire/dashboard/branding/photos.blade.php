@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + gallery status side panel ─────────
         Right side hosts the photo count so the operator sees
         how filled the property's gallery is at a glance. --}}
    @php
        $galleryCount = isset($galleryMedia)
            ? (is_countable($galleryMedia) ? count($galleryMedia) : $galleryMedia->count())
            : 0;
    @endphp
    <section class="lp-res-idx-hero" data-testid="photos-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Content</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Property photos
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Your logo, plus the photo carousel that leads your landing page. Mark one photo as the hero, it leads the carousel and stands in on surfaces that show a single image.
                </p>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Gallery status">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Gallery</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $galleryCount > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                        {{ number_format($galleryCount) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ($galleryCount === 0)
                            No photos yet
                        @else
                            {{ Str::plural('photo', $galleryCount) }} live
                            @if ($logoMedia)
                                · logo set
                            @endif
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>
    {{-- Interim inline CSS: the same rules exist in resources/css/app.css
         so the compiled bundle picks them up on the next deploy. This
         block keeps the redesign styled on prod until Vite rebuilds. --}}
    <style>
        .lp-page .logo-uploader { display: grid; gap: 16px; grid-template-columns: 140px minmax(0, 1fr); align-items: stretch; }
        @media (max-width: 620px) { .lp-page .logo-uploader { grid-template-columns: 1fr; } }
        .lp-page .logo-uploader__preview {
            position: relative; width: 140px; height: 140px; border-radius: 14px;
            border: 1.5px dashed var(--color-line);
            background:
                linear-gradient(45deg, var(--color-card-deep) 25%, transparent 25%),
                linear-gradient(-45deg, var(--color-card-deep) 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, var(--color-card-deep) 75%),
                linear-gradient(-45deg, transparent 75%, var(--color-card-deep) 75%);
            background-size: 14px 14px; background-position: 0 0, 0 7px, 7px -7px, -7px 0;
            background-color: var(--color-card);
            display: flex; align-items: center; justify-content: center;
            transition: border-color 160ms, box-shadow 160ms; overflow: hidden;
        }
        .lp-page .logo-uploader__preview.has-content { border-style: solid; border-color: var(--color-line-soft); }
        .lp-page .logo-uploader__preview.is-staging {
            border-style: dashed; border-color: var(--color-accent-deep);
            box-shadow: 0 0 0 3px var(--color-accent-soft);
        }
        .lp-page .logo-uploader__preview img { max-width: 108px; max-height: 108px; object-fit: contain; display: block; }
        .lp-page .logo-uploader__empty {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            color: var(--color-ink-mute); font-size: 11.5px; letter-spacing: 0.06em; text-transform: uppercase;
            font-family: var(--font-mono, monospace);
        }
        .lp-page .logo-uploader__delete {
            position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; border-radius: 9999px;
            background: rgba(20, 17, 12, 0.65); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            border: 0; cursor: pointer; opacity: 0;
            transition: opacity 160ms, background 160ms;
        }
        .lp-page .logo-uploader__preview:hover .logo-uploader__delete,
        .lp-page .logo-uploader__delete:focus-visible { opacity: 1; }
        .lp-page .logo-uploader__delete:hover { background: rgba(20, 17, 12, 0.9); }
        .lp-page .logo-uploader__chip {
            position: absolute; bottom: 6px; left: 6px; padding: 3px 8px; border-radius: 999px;
            background: var(--color-accent-deep); color: #fff;
            font-family: var(--font-mono, monospace);
            font-size: 9.5px; letter-spacing: 0.08em; text-transform: uppercase; font-weight: 600;
        }
        .lp-page .logo-uploader__loading { position: absolute; inset: 0; background: rgba(255,255,255,0.86); border-radius: 14px; }
        .lp-page .logo-uploader__loading-inner {
            width: 100%; height: 100%; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 6px;
            color: var(--color-ink-soft); font-size: 11.5px;
        }
        .lp-page .logo-uploader__loading-inner svg { animation: logo-uploader-spin 900ms linear infinite; color: var(--color-accent-deep); }
        @keyframes logo-uploader-spin { to { transform: rotate(360deg); } }
        .lp-page .logo-uploader__drop {
            position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; padding: 22px 20px; border: 1.5px dashed var(--color-line); border-radius: 14px;
            background: var(--color-card-deep); text-align: center; cursor: pointer;
            transition: border-color 160ms, background 160ms;
        }
        .lp-page .logo-uploader__drop:hover, .lp-page .logo-uploader__drop:focus-within {
            border-color: var(--color-accent-deep); background: var(--color-accent-soft, rgba(173, 203, 82,0.14));
        }
        .lp-page .logo-uploader__drop.is-dragging-over {
            border-color: var(--color-accent-deep); background: var(--color-accent-soft, rgba(173, 203, 82,0.18));
        }
        .lp-page .logo-uploader__drop-icon { color: var(--color-ink-mute); margin-bottom: 4px; }
        .lp-page .logo-uploader__drop-title { font-size: 14px; font-weight: 500; color: var(--color-ink); }
        .lp-page .logo-uploader__drop-help { font-size: 11.5px; color: var(--color-ink-soft); }
        .lp-page .logo-uploader__input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
    </style>

    {{-- ── Logo ─────────────────────────────────────────────────── --}}
    <section data-testid="logo-section" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Logo</span>
            <span class="lp-dash-card-meta">JPG / PNG / WebP / SVG &middot; up to 2MB &middot; shown across the top nav, emails and invoices</span>
        </div>

        <div class="logo-uploader"
             data-single-uploader
             data-upload-action="uploadLogo">

            {{-- Preview tile, big enough to actually read the mark.
                 Checker background gives transparent PNGs contrast. --}}
            <div class="logo-uploader__preview
                        @if ($logo || $logoMedia) has-content @endif
                        @if ($logo) is-staging @endif">
                @if ($logo && $logo->isPreviewable())
                    <img src="{{ $logo->temporaryUrl() }}" alt="Selected logo preview"
                         data-testid="logo-preview">
                @elseif ($logoMedia)
                    <img src="{{ $logoMedia->getUrl() }}" alt="Logo"
                         data-testid="current-logo">
                @else
                    <div class="logo-uploader__empty">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="3"/>
                            <circle cx="9" cy="9" r="1.5"/>
                            <path d="M21 15l-5-5-9 9"/>
                        </svg>
                        <span>No logo yet</span>
                    </div>
                @endif

                @if ($logoMedia && ! $logo)
                    <button type="button"
                            wire:click="deleteLogo"
                            wire:confirm="Remove the logo?"
                            class="logo-uploader__delete"
                            data-testid="logo-delete"
                            aria-label="Remove logo"
                            title="Remove logo">
                        <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                            <path d="M3 3l6 6M9 3l-6 6"/>
                        </svg>
                    </button>
                @endif

                @if ($logo)
                    <span class="logo-uploader__chip">Preview &middot; saving</span>
                @endif

                {{-- Loading overlay stays on top during both the temp
                     stage (target=logo) and the addMedia call
                     (target=uploadLogo). The flex layout lives on a
                     child so wire:loading can toggle display cleanly. --}}
                <div wire:loading wire:target="logo,uploadLogo" class="logo-uploader__loading">
                    <div class="logo-uploader__loading-inner">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M12 3a9 9 0 1 1-6.36 2.64" opacity="0.35"/>
                            <path d="M12 3a9 9 0 0 1 9 9"/>
                        </svg>
                        <span>Uploading&hellip;</span>
                    </div>
                </div>
            </div>

            {{-- Drop zone. Whole label is clickable + drag-target. The
                 file input sits behind the label so keyboard focus and
                 click both open the picker. Auto-upload is triggered
                 from resources/js/photo-manager.js on livewire-upload-finish. --}}
            <label class="logo-uploader__drop" data-testid="logo-drop">
                <svg class="logo-uploader__drop-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 3v14M6 9l6-6 6 6"/>
                </svg>
                <p class="logo-uploader__drop-title">
                    {{ $logoMedia ? 'Replace your logo' : 'Add your logo' }}
                </p>
                <p class="logo-uploader__drop-help">
                    Drag &amp; drop, or click anywhere in this box &middot; JPG / PNG / WebP / SVG &middot; up to 2MB
                </p>
                <input type="file"
                       wire:model="logo"
                       accept="image/jpeg,image/png,image/webp,image/svg+xml"
                       data-testid="logo-input"
                       class="logo-uploader__input">
            </label>
        </div>

        @error('logo') <p role="alert" class="lp-form-error mt-3" data-testid="logo-error">{{ $message }}</p> @enderror
    </section>

    {{-- Inline auto-upload hook. resources/js/photo-manager.js handles
         [data-single-uploader] once its bundle is deployed; the same
         binding here means the redesign works immediately on hotfix
         without waiting for a fresh vite build. The INIT_ATTR guard
         keeps the bundled version from double-firing after deploy. --}}
    <script>
        (function () {
            const INIT = 'data-photo-manager-initialised';
            function wire() {
                document.querySelectorAll('[data-single-uploader]').forEach(function (el) {
                    if (el.hasAttribute(INIT)) return;
                    el.setAttribute(INIT, '1');
                    const input = el.querySelector('input[type="file"]');
                    const action = el.getAttribute('data-upload-action');
                    if (!input || !action) return;
                    input.addEventListener('livewire-upload-finish', function () {
                        const wired = el.closest('[wire\\:id]');
                        if (!wired || !window.Livewire) return;
                        const comp = window.Livewire.find(wired.getAttribute('wire:id'));
                        if (comp) comp.call(action);
                    });
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', wire);
            } else {
                wire();
            }
            document.addEventListener('livewire:navigated', wire);
        })();
    </script>

    {{-- ── Gallery (leads the landing carousel; hero flag marks the
         featured tile) ─────────────────────────────────────────── --}}
    <section data-testid="gallery-section" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Photos</span>
            <span class="lp-dash-card-meta">JPG / PNG / WebP &middot; up to 5MB each</span>
        </div>
        <p class="text-[13px] mb-4" style="color: var(--color-ink-soft);">
            Drag photos to reorder. Star one to make it the hero, that image leads the
            landing carousel and stands in wherever we show a single property shot (invoice header,
            email hero, share preview).
        </p>

        <x-photo-manager
            :media="$galleryMedia"
            :pending="$gallery"
            wireModel="gallery"
            wireUpload="uploadGallery"
            wireReorder="reorderGallery"
            wireSetHero="setGalleryHero"
            wireDelete="deleteGalleryPhoto"
            wireDeleteMany="deleteGalleryPhotos"
            :maxFiles="$galleryMax"
            label="Landing carousel"
            help="Drag photos here or click to browse."
            emptyLabel="No photos yet. Add some to bring your landing page to life." />
    </section>
</div>
