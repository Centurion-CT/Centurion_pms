<div>
    <style>
        .demo-page { padding: 56px 0 96px; }
        @media (min-width: 900px) { .demo-page { padding: 88px 0 128px; } }

        .demo-split {
            display: grid; grid-template-columns: 1fr; gap: 40px;
            align-items: start;
        }
        @media (min-width: 900px) {
            .demo-split { grid-template-columns: 1fr 1.15fr; gap: 72px; }
        }

        .demo-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--mkt-mute);
        }
        .demo-eyebrow::before {
            content: ''; width: 24px; height: 1px; background: currentColor;
            display: inline-block;
        }
        .demo-headline {
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: clamp(34px, 5vw, 60px);
            line-height: 0.98; letter-spacing: -0.028em;
            margin: 16px 0 20px;
        }
        .demo-italic {
            font-family: 'Fraunces', Georgia, serif; font-style: italic; font-weight: 500;
            color: var(--mkt-accent-d);
        }
        .demo-lead {
            font-size: 15.5px; color: var(--mkt-mute);
            line-height: 1.65; max-width: 50ch;
        }
        .demo-pointlist {
            list-style: none; padding: 0; margin: 32px 0 0;
            display: grid; gap: 14px;
        }
        .demo-point {
            display: flex; align-items: flex-start; gap: 12px;
            font-size: 14px; color: var(--mkt-ink);
        }
        .demo-point::before {
            content: ''; flex-shrink: 0;
            width: 22px; height: 22px; border-radius: 999px;
            background: var(--mkt-accent-d) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12' fill='none' stroke='%230F1215' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M2.5 6l2.5 2.5L9.5 3.5'/></svg>") center/12px no-repeat;
        }
        .demo-fallback {
            margin-top: 40px; padding-top: 24px;
            border-top: 1px solid var(--mkt-line-2);
            font-size: 13px; color: var(--mkt-mute);
        }
        .demo-fallback a {
            color: var(--mkt-ink); font-weight: 500;
            text-decoration: underline; text-underline-offset: 3px;
        }

        /* ── Form card ──────────────────────────────────────────── */
        .demo-card {
            padding: 32px 28px;
            background: var(--mkt-paper);
            border-radius: 18px;
            border: 1px solid var(--mkt-line-2);
            box-shadow: 0 40px 90px -50px rgba(0, 0, 0, 0.35);
        }
        @media (min-width: 640px) { .demo-card { padding: 40px; } }
        .demo-grid {
            display: grid; grid-template-columns: 1fr; gap: 18px;
        }
        @media (min-width: 640px) {
            .demo-grid { grid-template-columns: 1fr 1fr; }
            .demo-grid > .full { grid-column: 1 / -1; }
        }
        .demo-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--mkt-ink); margin-bottom: 8px;
            letter-spacing: 0.02em;
        }
        .demo-input, .demo-select, .demo-textarea {
            width: 100%; box-sizing: border-box;
            padding: 12px 14px;
            font-family: inherit; font-size: 14.5px;
            color: var(--mkt-ink);
            background: #FBF9F3;
            border: 1px solid var(--mkt-line);
            border-radius: 10px;
            transition: border-color 140ms, box-shadow 140ms, background 140ms;
        }
        .demo-input:focus, .demo-select:focus, .demo-textarea:focus {
            outline: 0;
            background: #fff;
            border-color: var(--mkt-accent-d);
            box-shadow: 0 0 0 3px rgba(138, 174, 58, 0.15);
        }
        .demo-textarea { min-height: 100px; resize: vertical; }
        .demo-error { color: #B85C42; font-size: 12.5px; margin-top: 6px; }
        .demo-help  { color: var(--mkt-mute); font-size: 12px; margin-top: 6px; }

        .demo-honeypot { position: absolute; left: -9999px; opacity: 0; height: 0; }

        .demo-submit {
            display: inline-flex; align-items: center; gap: 12px;
            padding: 14px 14px 14px 24px;
            background: var(--mkt-ink); color: #F5EFE1;
            border: 0; border-radius: 9999px;
            font-family: inherit; font-size: 14px; font-weight: 500;
            cursor: pointer;
            transition: background 200ms, transform 80ms;
        }
        .demo-submit:hover { background: var(--mkt-ink-2); }
        .demo-submit:disabled { opacity: 0.6; cursor: wait; }
        .demo-submit .dot {
            width: 32px; height: 32px; border-radius: 999px;
            background: var(--mkt-accent);
            color: var(--mkt-ink);
            display: inline-flex; align-items: center; justify-content: center;
        }

        .demo-footer-note {
            font-size: 12.5px; color: var(--mkt-mute);
            margin-top: 20px; text-align: center;
        }
        .demo-footer-note a { color: var(--mkt-ink); text-decoration: underline; text-underline-offset: 3px; }

        /* ── Success state ──────────────────────────────────────── */
        .demo-thanks {
            max-width: 640px; margin: 0 auto; text-align: center;
            padding: 32px 24px;
        }
        .demo-thanks-mark {
            width: 72px; height: 72px; margin: 0 auto 24px;
            border-radius: 999px;
            background: var(--mkt-accent);
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--mkt-ink);
        }
    </style>

    <main class="demo-page">
        <div class="mkt-wrap">
            @if ($submitted)
                <section class="demo-thanks">
                    <div class="demo-thanks-mark" aria-hidden="true">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
                    </div>
                    <span class="demo-eyebrow" style="justify-content: center;">Almost there</span>
                    <h1 class="demo-headline" style="margin-top: 12px;">
                        We opened WhatsApp.<br>
                        <span class="demo-italic">Tap Send to finish.</span>
                    </h1>
                    <p class="demo-lead" style="margin: 0 auto;">
                        Your details are already in the message. Hit send in WhatsApp and
                        someone from the team will reply within the hour (usually much
                        sooner).
                    </p>
                    <div style="margin-top: 32px;">
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener"
                           class="demo-submit"
                           style="text-decoration: none;">
                            Open WhatsApp
                            <span class="dot" aria-hidden="true">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M20.5 3.5A11 11 0 0 0 4 18.5L3 21l2.6-.9A11 11 0 0 0 20.5 3.5zm-3.9 12.7c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-3-.8-2.4-1-4-3.4-4.1-3.6-.1-.2-1-1.3-1-2.5s.6-1.8.9-2c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5.2.5.7 1.6.7 1.7.1.1.1.3 0 .5-.1.2-.2.3-.3.5-.2.2-.3.3-.4.5-.1.1-.3.3-.1.6.2.3.9 1.5 2 2.4 1.4 1.1 2.5 1.5 2.9 1.6.3.1.5.1.7-.1.2-.2.8-.9 1-1.2.2-.3.4-.2.7-.1.3.1 1.7.8 2 .9.3.2.5.2.6.4.1.1.1.7-.1 1.3z"/>
                                </svg>
                            </span>
                        </a>
                    </div>
                    <p class="demo-footer-note" style="margin-top: 32px;">
                        Nothing happening?
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener">
                            Try the WhatsApp link again →
                        </a>
                    </p>
                </section>
                <script>
                    // Auto-open WhatsApp in a new tab as soon as the
                    // thank-you screen renders. On mobile this hands
                    // off to the native WhatsApp app; on desktop it
                    // opens web.whatsapp.com pre-filled. Popup blockers
                    // may swallow this - the visible "Open WhatsApp"
                    // button above is the always-works fallback.
                    (function () {
                        var url = @js($whatsappUrl);
                        if (! url) return;
                        try { window.open(url, '_blank', 'noopener'); } catch (e) { /* blocker */ }
                    })();
                </script>
            @else
                <div class="demo-split">
                    {{-- LEFT: pitch --}}
                    <div>
                        <span class="demo-eyebrow">Book a demo</span>
                        <h1 class="demo-headline">
                            15 minutes.<br>
                            <span class="demo-italic">Then decide.</span>
                        </h1>
                        <p class="demo-lead">
                            We'll walk your property through the front-desk console + guest
                            portal in real time, using your actual room list and rates. No
                            slide decks. No pressure to sign up on the call.
                        </p>

                        <ul class="demo-pointlist">
                            <li class="demo-point">Live on WhatsApp video, no download, no meeting link roulette</li>
                            <li class="demo-point">Concierge setup - your rooms, staff, rates all seeded before you touch a keyboard</li>
                            <li class="demo-point">30-day money-back on your first month if it doesn't earn its cost</li>
                            <li class="demo-point">Zero commission on every booking - flat 2% only on card payments</li>
                        </ul>

                        <div class="demo-fallback">
                            Prefer to explore alone first?
                            <a href="{{ Route::has('register') ? route('register') : '/register' }}">
                                Skip straight to self-serve signup →
                            </a>
                        </div>
                    </div>

                    {{-- RIGHT: form --}}
                    <form wire:submit.prevent="submit" class="demo-card" novalidate>
                        {{-- Honeypot --}}
                        <label class="demo-honeypot" aria-hidden="true">
                            Company website
                            <input type="text" wire:model="company_website" tabindex="-1" autocomplete="off">
                        </label>

                        <div class="demo-grid">
                            <div class="full">
                                <label for="dr-name" class="demo-label">Your name</label>
                                <input id="dr-name" type="text" wire:model="name"
                                       class="demo-input" placeholder="Adaeze Okoro"
                                       required autocomplete="name">
                                @error('name') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="full">
                                <label for="dr-prop" class="demo-label">Property name</label>
                                <input id="dr-prop" type="text" wire:model="property_name"
                                       class="demo-input" placeholder="Grand Palace Abuja"
                                       required>
                                @error('property_name') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="dr-phone" class="demo-label">WhatsApp number</label>
                                <input id="dr-phone" type="tel" wire:model="phone"
                                       class="demo-input" placeholder="+234 …"
                                       required autocomplete="tel">
                                @error('phone') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="dr-email" class="demo-label">Email</label>
                                <input id="dr-email" type="email" wire:model="email"
                                       class="demo-input" placeholder="you@yourproperty.com"
                                       required autocomplete="email">
                                @error('email') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="dr-city" class="demo-label">City</label>
                                <input id="dr-city" type="text" wire:model="city"
                                       class="demo-input" placeholder="Abuja"
                                       autocomplete="address-level2">
                                @error('city') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="dr-size" class="demo-label">How many rooms / units?</label>
                                <select id="dr-size" wire:model="property_size" class="demo-select">
                                    <option value="">Pick a range</option>
                                    <option value="1-5">1 - 5 units</option>
                                    <option value="6-20">6 - 20 units</option>
                                    <option value="21-50">21 - 50 units</option>
                                    <option value="50+">50+ units</option>
                                </select>
                                @error('property_size') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="full">
                                <label for="dr-window" class="demo-label">When works for you?</label>
                                <select id="dr-window" wire:model="preferred_window" class="demo-select">
                                    <option value="">Any time we can reach you</option>
                                    <option value="morning">Morning (8am - 12pm WAT)</option>
                                    <option value="afternoon">Afternoon (12pm - 5pm WAT)</option>
                                    <option value="evening">Evening (5pm - 8pm WAT)</option>
                                    <option value="anytime">Anytime</option>
                                </select>
                            </div>

                            <div class="full">
                                <label for="dr-notes" class="demo-label">Anything we should know? <span style="color: var(--mkt-mute); font-weight: 400;">Optional</span></label>
                                <textarea id="dr-notes" wire:model="notes" class="demo-textarea"
                                          placeholder="Currently using X. Migrating from Y. Etc."></textarea>
                                @error('notes') <p class="demo-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div style="margin-top: 28px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                            <button type="submit" class="demo-submit"
                                    wire:loading.attr="disabled" wire:target="submit">
                                <span wire:loading.remove wire:target="submit">Book demo</span>
                                {{-- Pre-hydration `display: none` so both spans
                                     don't render side-by-side before Livewire
                                     wires up the wire:loading toggle. --}}
                                <span wire:loading wire:target="submit" style="display: none;">Sending…</span>
                                <span class="dot" aria-hidden="true">
                                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                                </span>
                            </button>
                            <p class="demo-help" style="margin: 0;">We reply on WhatsApp within the hour.</p>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </main>
</div>
