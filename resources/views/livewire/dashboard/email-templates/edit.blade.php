@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back + header ───────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="lp-eyebrow-strip">Email template</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    {{ $keyEnum->label() }}
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    {{ $keyEnum->description() }}
                </p>
            </div>
            @if (! $isDefault)
                <button type="button" wire:click="resetToDefault"
                        wire:confirm="Discard your customisations and restore the default subject + body?"
                        data-testid="reset-default"
                        class="lp-btn">Reset to default</button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2" data-testid="template-editor">

        {{-- ── Editor ─────────────────────────────────────────── --}}
        <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 18px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Edit</span>
                <label class="flex items-center gap-2 text-[12px]" style="color: var(--color-ink-soft);">
                    <input type="checkbox" wire:model.live="active"
                           data-testid="template-active"
                           style="accent-color: var(--color-accent-deep);">
                    Active
                </label>
            </div>

            <div>
                <label for="t-subject" class="lp-form-label">Subject</label>
                <input id="t-subject" type="text" wire:model.live="subject" required maxlength="240"
                       data-testid="template-subject" class="lp-input">
                @error('subject') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="t-body" class="lp-form-label">Body</label>
                <textarea id="t-body" wire:model.live="body" rows="14" maxlength="20000"
                          data-testid="template-body" class="lp-input lp-mono"
                          style="font-size: 13px; line-height: 1.55; min-height: 320px;"></textarea>
                @error('body') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Variable picker --}}
            <div>
                <label class="lp-form-label">Variables</label>
                <p class="lp-form-help" style="margin-top: 0; margin-bottom: 8px;">
                    Click to copy. Paste the token where you want the value to appear.
                </p>
                <div class="flex flex-wrap gap-2" data-testid="variable-picker">
                    @foreach ($variables as $v)
                        @php
                            // Build the literal `{{ token }}` string in PHP so we
                            // don't have to escape Blade braces inside the inline
                            // onclick handler (which previously tripped Blade's
                            // parser).
                            $tokenLiteral = '{{ '.$v['token'].' }}';
                        @endphp
                        <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $tokenLiteral }}'); this.dataset.copied='1'; setTimeout(() => this.dataset.copied='', 1200);"
                                data-testid="variable-{{ $v['token'] }}"
                                class="lp-tag-pill"
                                style="cursor: pointer; transition: background 160ms;"
                                data-copied="">
                            <span class="lp-mono">{{ $v['token'] }}</span>
                            <span style="color: var(--color-ink-mute); margin-left: 6px; font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase;">
                                {{ $v['label'] }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
                <button type="submit" data-testid="template-save" class="lp-pill">
                    <span wire:loading.remove wire:target="save">Save template</span>
                    <span wire:loading wire:target="save">Saving…</span>
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>

        {{-- ── Preview ────────────────────────────────────────── --}}
        <aside class="lp-dash-card" data-testid="template-preview">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Preview</span>
                <span class="lp-dash-card-meta">sample data &middot; updates as you type</span>
            </div>

            <div style="padding: 16px 18px; background: var(--color-card-deep); border-radius: 12px; margin-bottom: 14px;">
                <div class="lp-form-label" style="margin: 0 0 4px;">Subject</div>
                <div data-testid="preview-subject"
                     style="font-family: var(--font-display); font-weight: 600; font-size: 16px; color: var(--color-ink); letter-spacing: -0.01em;">
                    {{ $previewSubject ?: '(empty)' }}
                </div>
            </div>

            <div style="padding: 18px 20px; background: var(--color-card); border: 1px solid var(--color-line); border-radius: 12px;"
                 data-testid="preview-body">
                <div style="white-space: pre-wrap; font-size: 13.5px; line-height: 1.6; color: var(--color-ink);">{{ $previewBody }}</div>
            </div>

            <p class="text-[11.5px] mt-3" style="color: var(--color-ink-mute);">
                Variables wrapped in <span class="lp-mono">&#123;&#123; &#125;&#125;</span> are
                replaced with real booking data at send time. Tokens we don&rsquo;t recognise stay
                visible so you can spot typos.
            </p>
        </aside>
    </div>
</div>
