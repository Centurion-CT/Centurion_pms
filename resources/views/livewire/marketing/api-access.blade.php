<div>
    <style>
        .api-page { padding: 56px 0 96px; }
        @media (min-width: 900px) { .api-page { padding: 88px 0 128px; } }

        .api-split {
            display: grid; grid-template-columns: 1fr; gap: 40px;
            align-items: start;
        }
        @media (min-width: 900px) {
            .api-split { grid-template-columns: 1fr 1.15fr; gap: 72px; }
        }

        .api-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--mkt-mute);
        }
        .api-eyebrow::before {
            content: ''; width: 24px; height: 1px; background: currentColor;
            display: inline-block;
        }
        .api-headline {
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: clamp(34px, 5vw, 60px);
            line-height: 0.98; letter-spacing: -0.028em;
            margin: 16px 0 20px;
        }
        .api-italic {
            font-family: 'Fraunces', Georgia, serif; font-style: italic; font-weight: 500;
            color: var(--mkt-accent-d);
        }
        .api-lead {
            font-size: 15.5px; color: var(--mkt-mute);
            line-height: 1.65; max-width: 50ch;
        }

        .api-samples {
            margin-top: 32px;
            padding: 22px 26px;
            background: var(--mkt-ink);
            color: rgba(245, 239, 225, 0.85);
            border-radius: 16px;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 12.5px; line-height: 1.75;
            overflow-x: auto;
        }
        .api-samples .m { color: rgba(245, 239, 225, 0.5); }
        .api-samples .p { color: var(--mkt-accent); }
        .api-samples .s { color: #FCD34D; }
        .api-samples .ok { color: #86EFAC; }

        .api-fallback {
            margin-top: 32px; padding-top: 24px;
            border-top: 1px solid var(--mkt-line-2);
            font-size: 13px; color: var(--mkt-mute);
        }
        .api-fallback a {
            color: var(--mkt-ink); font-weight: 500;
            text-decoration: underline; text-underline-offset: 3px;
        }

        .api-card {
            padding: 32px 28px;
            background: var(--mkt-paper);
            border-radius: 18px;
            border: 1px solid var(--mkt-line-2);
            box-shadow: 0 40px 90px -50px rgba(0, 0, 0, 0.35);
        }
        @media (min-width: 640px) { .api-card { padding: 40px; } }
        .api-grid { display: grid; grid-template-columns: 1fr; gap: 18px; }
        @media (min-width: 640px) {
            .api-grid { grid-template-columns: 1fr 1fr; }
            .api-grid > .full { grid-column: 1 / -1; }
        }
        .api-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--mkt-ink); margin-bottom: 8px;
        }
        .api-input, .api-textarea {
            width: 100%; box-sizing: border-box;
            padding: 12px 14px;
            font-family: inherit; font-size: 14.5px;
            color: var(--mkt-ink);
            background: #FBF9F3;
            border: 1px solid var(--mkt-line);
            border-radius: 10px;
            transition: border-color 140ms, box-shadow 140ms, background 140ms;
        }
        .api-input:focus, .api-textarea:focus {
            outline: 0; background: #fff;
            border-color: var(--mkt-accent-d);
            box-shadow: 0 0 0 3px rgba(138, 174, 58, 0.15);
        }
        .api-textarea { min-height: 90px; resize: vertical; }
        .api-error { color: #B85C42; font-size: 12.5px; margin-top: 6px; }
        .api-help  { color: var(--mkt-mute); font-size: 12px; margin-top: 6px; }

        .api-honeypot { position: absolute; left: -9999px; opacity: 0; height: 0; }

        .api-submit {
            display: inline-flex; align-items: center; gap: 12px;
            padding: 14px 14px 14px 24px;
            background: var(--mkt-ink); color: #F5EFE1;
            border: 0; border-radius: 9999px;
            font-family: inherit; font-size: 14px; font-weight: 500;
            cursor: pointer;
            transition: background 200ms, transform 80ms;
        }
        .api-submit:hover { background: var(--mkt-ink-2); }
        .api-submit:disabled { opacity: 0.6; cursor: wait; }
        .api-submit .dot {
            width: 32px; height: 32px; border-radius: 999px;
            background: var(--mkt-accent);
            color: var(--mkt-ink);
            display: inline-flex; align-items: center; justify-content: center;
        }

        .api-thanks {
            max-width: 640px; margin: 0 auto; text-align: center;
            padding: 32px 24px;
        }
        .api-thanks-mark {
            width: 72px; height: 72px; margin: 0 auto 24px;
            border-radius: 999px;
            background: var(--mkt-accent);
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--mkt-ink);
        }
    </style>

    <main class="api-page">
        <div class="mkt-wrap">
            @if ($submitted)
                <section class="api-thanks">
                    <div class="api-thanks-mark" aria-hidden="true">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
                    </div>
                    <span class="api-eyebrow" style="justify-content: center;">On the list</span>
                    <h1 class="api-headline" style="margin-top: 12px;">
                        You're in. <span class="api-italic">Check your inbox.</span>
                    </h1>
                    <p class="api-lead" style="margin: 0 auto;">
                        A confirmation is on its way. When the beta opens we'll email
                        you a client ID and a link to the reference docs at
                        <code style="font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 13px; padding: 2px 8px; background: var(--mkt-cream-2); border-radius: 4px;">api.keverna.com</code>.
                    </p>
                    <p style="margin-top: 32px; font-size: 12.5px; color: var(--mkt-mute);">
                        <a href="/" style="color: var(--mkt-ink); text-decoration: underline; text-underline-offset: 3px;">← Back to landing</a>
                    </p>
                </section>
            @else
                <div class="api-split">
                    <div>
                        <span class="api-eyebrow">Public API - early access</span>
                        <h1 class="api-headline">
                            Build on top.<br>
                            <span class="api-italic">Get on the list.</span>
                        </h1>
                        <p class="api-lead">
                            OAuth, webhooks and REST across bookings, rooms, payments and
                            housekeeping. When the beta opens we'll ship you a client ID.
                            The waitlist is what shapes what we build first.
                        </p>

                        <pre class="api-samples" aria-hidden="true"><span class="m">POST</span> <span class="p">/v1/rates</span> HTTP/1.1
<span class="m">Authorization:</span> Bearer <span class="s">kv_live_…</span>
<span class="m">Content-Type:</span> application/json

{
  <span class="s">"room_id"</span>: <span class="s">"rm_9b3f"</span>,
  <span class="s">"from"</span>:    <span class="s">"2026-10-15"</span>,
  <span class="s">"to"</span>:      <span class="s">"2026-10-22"</span>,
  <span class="s">"rate"</span>:    <span class="s">45000</span>
}

<span class="ok">→ 200 OK</span>  <span class="m">2 rates pushed · sync in 4s</span></pre>

                        <div class="api-fallback">
                            Running a property, not building software?
                            <a href="{{ route('demo') }}">Book a 15-min demo instead →</a>
                        </div>
                    </div>

                    <form wire:submit.prevent="submit" class="api-card" novalidate>
                        <label class="api-honeypot" aria-hidden="true">
                            Company website
                            <input type="text" wire:model="company_website" tabindex="-1" autocomplete="off">
                        </label>

                        <div class="api-grid">
                            <div>
                                <label for="ar-name" class="api-label">Your name</label>
                                <input id="ar-name" type="text" wire:model="name"
                                       class="api-input" placeholder="Chidi N."
                                       required autocomplete="name">
                                @error('name') <p class="api-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="ar-email" class="api-label">Email</label>
                                <input id="ar-email" type="email" wire:model="email"
                                       class="api-input" placeholder="you@company.com"
                                       required autocomplete="email">
                                @error('email') <p class="api-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="ar-company" class="api-label">Company <span style="color: var(--mkt-mute); font-weight: 400;">Optional</span></label>
                                <input id="ar-company" type="text" wire:model="company"
                                       class="api-input" placeholder="Channel Manager Co">
                                @error('company') <p class="api-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="ar-github" class="api-label">GitHub / portfolio <span style="color: var(--mkt-mute); font-weight: 400;">Optional</span></label>
                                <input id="ar-github" type="url" wire:model="github_url"
                                       class="api-input" placeholder="https://github.com/…">
                                @error('github_url') <p class="api-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="full">
                                <label for="ar-use" class="api-label">What do you want to build?</label>
                                <textarea id="ar-use" wire:model="use_case" class="api-textarea"
                                          placeholder="Sync inventory from an OTA, build a guest concierge app, price optimisation, etc."></textarea>
                                @error('use_case') <p class="api-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="full">
                                <label for="ar-int" class="api-label">Systems you need to integrate with <span style="color: var(--mkt-mute); font-weight: 400;">Optional</span></label>
                                <input id="ar-int" type="text" wire:model="integrations"
                                       class="api-input" placeholder="Booking.com, Airbnb, WhatsApp Business, custom PMS, etc.">
                                @error('integrations') <p class="api-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div style="margin-top: 28px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                            <button type="submit" class="api-submit"
                                    wire:loading.attr="disabled" wire:target="submit">
                                <span wire:loading.remove wire:target="submit">Request early access</span>
                                <span wire:loading wire:target="submit" style="display: none;">Sending…</span>
                                <span class="dot" aria-hidden="true">
                                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                                </span>
                            </button>
                            <p class="api-help" style="margin: 0;">We'll email a confirmation right away.</p>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </main>
</div>
