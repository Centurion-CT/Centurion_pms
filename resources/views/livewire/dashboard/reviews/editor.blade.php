@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back link + header ───────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="lp-eyebrow-strip">{{ $reviewId ? 'Edit review' : 'New review' }}</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    {{ $reviewId ? 'Edit review' : 'Add a review' }}
                </h1>
            </div>
            {{-- Save confirmation surfaces via the global notify modal. --}}
        </div>
    </div>

    {{-- ── Form ─────────────────────────────────────────────────── --}}
    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;">

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label">Guest name</label>
                <input type="text" wire:model="guest_name" required maxlength="120"
                       data-testid="review-guest" class="lp-input"
                       placeholder="Amara Okafor">
                @error('guest_name') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="lp-form-label">Rating</label>
                <select wire:model="rating" data-testid="review-rating" class="lp-input">
                    @for ($i = 5; $i >= 1; $i--)
                        <option value="{{ $i }}">{{ str_repeat('★', $i) }}{{ str_repeat('☆', 5 - $i) }} ({{ $i }})</option>
                    @endfor
                </select>
                @error('rating') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label">Channel</label>
                <select wire:model="channel"
                        data-testid="review-channel"
                        class="lp-input">
                    @foreach ($channels as $c)
                        <option value="{{ $c->value }}">{{ $c->label() }}</option>
                    @endforeach
                </select>
                <p class="lp-form-help">Where the review originally came from. Renders as a small badge on the public page.</p>
                @error('channel') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="lp-form-label">Guest country <span style="color: var(--color-ink-mute);">· optional</span></label>
                @php
                    $flag = \App\Services\Locale\Countries::flagFor($guest_country);
                @endphp
                <div style="position: relative;">
                    <input type="text" wire:model.blur="guest_country" maxlength="80"
                           placeholder="Nigeria"
                           list="review-country-list"
                           autocomplete="off"
                           data-testid="review-country"
                           class="lp-input"
                           style="padding-left: {{ $flag ? '38px' : '12px' }};">
                    @if ($flag)
                        <span aria-hidden="true"
                              data-testid="review-country-flag"
                              style="position: absolute; top: 50%; left: 12px; transform: translateY(-50%); font-size: 16px; line-height: 1;">
                            {{ $flag }}
                        </span>
                    @endif
                </div>
                <datalist id="review-country-list">
                    @foreach ($countries as $name => $info)
                        <option value="{{ $name }}">{{ $info['flag'] }} {{ $name }}</option>
                    @endforeach
                </datalist>
                <p class="lp-form-help">Start typing to pick from the list, or type any country manually.</p>
                @error('guest_country') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="lp-form-label">Published on</label>
                <input type="date" wire:model="published_at"
                       data-testid="review-published-at"
                       class="lp-input">
                <p class="lp-form-help">Back-date this if the review came in earlier and you're transcribing it now.</p>
                @error('published_at') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="lp-form-label">Scope</label>
            <select wire:model="room_id" data-testid="review-room" class="lp-input">
                <option value="">Property-wide (shows on landing + every room)</option>
                @foreach ($rooms as $r)
                    <option value="{{ $r->id }}">Room: {{ $r->name }}</option>
                @endforeach
            </select>
            <p class="lp-form-help">
                Property-wide reviews appear on the landing reviews carousel. Per-room reviews
                appear on that room&rsquo;s detail page and contribute to the room&rsquo;s rating.
            </p>
        </div>

        <div>
            <label class="lp-form-label">Review text</label>
            <textarea wire:model="body" rows="5" required maxlength="2000"
                      data-testid="review-body" class="lp-input"
                      placeholder="What did the guest say about their stay?"></textarea>
            @error('body') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Photo attachment. Operators use this for:
             - Screenshot of the source review (Google / Booking) for
               provenance
             - Guest self-portrait shared during the stay
             - Room photo the guest sent along with the review text
             Kept optional. Max 5MB. --}}
        {{-- Photo upload: click-or-drop tile with live preview. On file
             select we downsize client-side to 1600px on the long edge
             at JPEG q0.85 before handing to Livewire, so a 12MB phone
             photo lands as ~200-400KB on the wire. --}}
        <div x-data="reviewPhotoUploader"
             x-init="init($refs.file)"
             class="review-photo-upload">
            <label class="lp-form-label">Photo <span style="color: var(--color-ink-mute);">· optional</span></label>

            @php
                $existingUrl = $existing_photo_path
                    ? \Illuminate\Support\Facades\Storage::disk(config('media-library.disk_name', 'public'))->url($existing_photo_path)
                    : null;
                $freshUrl = $photo ? $photo->temporaryUrl() : null;
                $previewUrl = $freshUrl ?? $existingUrl;
            @endphp

            <div class="review-photo-drop"
                 :class="{ 'is-dragging': dragging, 'is-busy': working }"
                 x-on:click="pick"
                 x-on:dragover.prevent="dragging = true"
                 x-on:dragleave.prevent="dragging = false"
                 x-on:drop.prevent="onDrop($event)"
                 data-testid="review-photo-drop"
                 role="button"
                 tabindex="0"
                 x-on:keydown.enter.prevent="pick"
                 x-on:keydown.space.prevent="pick">
                @if ($previewUrl)
                    <div class="review-photo-preview">
                        <img src="{{ $previewUrl }}"
                             alt="Review photo preview"
                             data-testid="review-photo-thumb">
                        <div class="review-photo-preview-meta">
                            <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em; margin: 0;">
                                {{ $freshUrl ? 'New upload' : 'Current photo' }}
                            </p>
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink); margin: 0;">
                                Click or drop a file to replace, or use the buttons.
                            </p>
                        </div>
                    </div>
                @else
                    <div class="review-photo-empty" x-show="! working">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="14" rx="2"/>
                            <path d="M3 15l4-4 4 4 4-5 6 6"/>
                            <circle cx="8" cy="9" r="1.2"/>
                        </svg>
                        <p class="text-[13px] mt-2 mb-0" style="color: var(--color-ink); font-weight: 500;">
                            Click to choose or drag a photo here
                        </p>
                        <p class="text-[11.5px] mt-1 mb-0" style="color: var(--color-ink-mute);">
                            JPG or PNG · auto-resized to 1600px · up to ~5MB on disk after resize
                        </p>
                    </div>
                @endif
                <div class="review-photo-busy" x-show="working" x-cloak>
                    <span class="text-[12.5px]" style="color: var(--color-ink);">
                        Resizing<span x-text="statusSuffix"></span>…
                    </span>
                </div>
            </div>

            {{-- Hidden file input, opened only by the drop tile's pick()
                 handler. No wire:model — we upload manually via
                 $wire.upload() from Alpine after canvas resize. --}}
            <input type="file" x-ref="file"
                   accept="image/*"
                   style="position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0;"
                   data-testid="review-photo-input"
                   x-on:change="onSelect($event)">

            <div class="flex items-center gap-2 mt-3">
                @if ($existing_photo_path && ! $photo)
                    <button type="button"
                            wire:click="clearPhoto"
                            data-testid="review-photo-remove"
                            class="lp-btn">
                        Remove
                    </button>
                @endif
                @if ($photo)
                    <button type="button"
                            wire:click="$set('photo', null)"
                            data-testid="review-photo-cancel-upload"
                            class="lp-btn">
                        Cancel upload
                    </button>
                @endif
                <span wire:loading wire:target="photo"
                      class="text-[12px]" style="color: var(--color-ink-mute);">
                    Uploading…
                </span>
                <span x-show="resizedNote" x-cloak
                      class="text-[11.5px]" style="color: var(--color-ink-mute);">
                    <span x-text="resizedNote"></span>
                </span>
            </div>
            @error('photo') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        @once
            <style>
                .review-photo-upload { position: relative; }
                .review-photo-drop {
                    display: block;
                    padding: 22px 18px;
                    border: 2px dashed var(--color-line);
                    border-radius: 12px;
                    background: var(--color-card-deep);
                    text-align: center;
                    cursor: pointer;
                    transition: border-color 140ms, background 140ms;
                    outline: none;
                }
                .review-photo-drop:hover,
                .review-photo-drop:focus-visible {
                    border-color: var(--color-ink);
                }
                .review-photo-drop.is-dragging {
                    border-color: var(--color-accent-deep);
                    background: color-mix(in srgb, var(--color-accent-deep) 8%, var(--color-card-deep));
                }
                .review-photo-drop.is-busy { cursor: progress; }
                .review-photo-empty svg { color: var(--color-ink-mute); }
                .review-photo-preview {
                    display: flex; align-items: center; gap: 14px; text-align: left;
                }
                .review-photo-preview img {
                    width: 96px; height: 96px; object-fit: cover;
                    border-radius: 10px; border: 1px solid var(--color-line);
                    flex-shrink: 0;
                }
                .review-photo-preview-meta { flex: 1; min-width: 0; }
                .review-photo-busy { padding: 4px 0; }
                [x-cloak] { display: none !important; }
            </style>
            <script>
                // Review photo uploader:
                //   1. Reads the picked / dropped File
                //   2. Downsamples it via <canvas> to 1600px on the
                //      long edge at JPEG q0.85. Zero deps, works in
                //      every evergreen browser.
                //   3. Wraps the resulting Blob in a File and hands
                //      to Livewire via $wire.upload('photo', file, ...).
                //   4. Alpine mirrors "working" so the drop tile can
                //      show a Resizing… affordance while the canvas
                //      encoding is in flight.
                window.reviewPhotoUploader = {
                    fileInput: null,
                    dragging: false,
                    working: false,
                    resizedNote: '',
                    statusSuffix: '',
                    tick: null,
                    init(fileInput) {
                        this.fileInput = fileInput;
                    },
                    pick() {
                        if (this.working) return;
                        this.fileInput?.click();
                    },
                    async onSelect(event) {
                        const file = event.target.files?.[0];
                        if (!file) return;
                        await this.processFile(file);
                    },
                    async onDrop(event) {
                        this.dragging = false;
                        const file = event.dataTransfer?.files?.[0];
                        if (!file) return;
                        await this.processFile(file);
                    },
                    async processFile(file) {
                        if (!file.type.startsWith('image/')) {
                            this.resizedNote = 'That doesn\'t look like an image.';
                            return;
                        }
                        this.working = true;
                        this.startTicker();
                        try {
                            const resized = await this.resize(file);
                            this.resizedNote =
                                'Resized ' + this.humanKB(file.size)
                                + ' → ' + this.humanKB(resized.size);
                            await this.$wire.upload('photo', resized);
                        } catch (e) {
                            console.error(e);
                            this.resizedNote = 'Couldn\'t process that image.';
                        } finally {
                            this.working = false;
                            this.stopTicker();
                        }
                    },
                    resize(file) {
                        return new Promise((resolve, reject) => {
                            const img = new Image();
                            const url = URL.createObjectURL(file);
                            img.onload = () => {
                                try {
                                    const MAX = 1600;
                                    const scale = Math.min(1, MAX / Math.max(img.width, img.height));
                                    const w = Math.round(img.width * scale);
                                    const h = Math.round(img.height * scale);
                                    const canvas = document.createElement('canvas');
                                    canvas.width = w;
                                    canvas.height = h;
                                    const ctx = canvas.getContext('2d');
                                    ctx.imageSmoothingQuality = 'high';
                                    ctx.drawImage(img, 0, 0, w, h);
                                    canvas.toBlob(
                                        (blob) => {
                                            URL.revokeObjectURL(url);
                                            if (!blob) return reject(new Error('canvas toBlob failed'));
                                            const baseName = (file.name || 'photo').replace(/\.[^.]+$/, '');
                                            const outFile = new File([blob], baseName + '.jpg', { type: 'image/jpeg' });
                                            resolve(outFile);
                                        },
                                        'image/jpeg',
                                        0.85
                                    );
                                } catch (err) {
                                    URL.revokeObjectURL(url);
                                    reject(err);
                                }
                            };
                            img.onerror = () => {
                                URL.revokeObjectURL(url);
                                reject(new Error('image load failed'));
                            };
                            img.src = url;
                        });
                    },
                    humanKB(bytes) {
                        if (bytes < 1024) return bytes + 'B';
                        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + 'KB';
                        return (bytes / (1024 * 1024)).toFixed(1) + 'MB';
                    },
                    startTicker() {
                        let dots = 0;
                        this.statusSuffix = '';
                        this.tick = setInterval(() => {
                            dots = (dots + 1) % 4;
                            this.statusSuffix = '.'.repeat(dots);
                        }, 350);
                    },
                    stopTicker() {
                        clearInterval(this.tick);
                        this.tick = null;
                        this.statusSuffix = '';
                    },
                };
            </script>
        @endonce

        <label class="lp-check-row">
            <input type="checkbox" wire:model="is_displayed" data-testid="review-displayed">
            <span>Display publicly</span>
        </label>

        {{-- ── Reply as property ────────────────────────────────────
             A thoughtful operator response turns a middling review
             into a trust signal. Reply is optional; when set, renders
             under the guest review on the marketplace + landing pages
             with a "Response from {property}" header + relative
             timestamp. Clearing the textarea + saving removes the
             reply from the public page. --}}
        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 20px;">
            <label class="lp-form-label" for="response-body">Reply as the property</label>
            <p class="lp-form-help" style="margin-bottom: 10px;">
                Guests reading reviews before booking see this reply directly under the guest&rsquo;s words.
                Leave empty to hide the reply. Speak as the property, keep it warm and specific.
            </p>
            <textarea id="response-body"
                      wire:model="response_body"
                      rows="4"
                      maxlength="2000"
                      data-testid="review-response-body"
                      class="lp-input"
                      placeholder="Thanks so much, Amara. Glad the balcony worked out. See you on your next trip."></textarea>
            @error('response_body') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            @if ($responded_at_iso)
                <p class="lp-form-help" style="margin-top: 8px; color: var(--color-ink-soft);"
                   data-testid="review-response-meta">
                    Replied
                    <time datetime="{{ $responded_at_iso }}">{{ \Illuminate\Support\Carbon::parse($responded_at_iso)->diffForHumans() }}</time>
                    @if ($responded_by_name)
                        by {{ $responded_by_name }}
                    @endif.
                </p>
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.reviews.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" data-testid="review-save" class="lp-pill">
                <span wire:loading.remove wire:target="save">{{ $reviewId ? 'Save changes' : 'Add review' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
