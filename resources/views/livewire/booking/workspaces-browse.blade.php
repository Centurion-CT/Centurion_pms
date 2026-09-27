@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="ws-page">
    <style>
        .ws-page {
            --ws-paper:    #FAF7F2;
            --ws-paper-2:  #F2EEE6;
            --ws-card:     #FFFFFF;
            --ws-ink:      #14171A;
            --ws-ink-soft: #3D434A;
            --ws-ink-mute: #7A7F87;
            --ws-line:     rgba(20, 23, 26, 0.10);
            --ws-line-soft:rgba(20, 23, 26, 0.06);

            background: var(--ws-paper);
            color: var(--ws-ink);
            font-family: var(--body-font, 'Inter', sans-serif);
            -webkit-font-smoothing: antialiased;
            padding-bottom: 4rem;
        }
        .ws-shell { max-width: 1120px; margin: 0 auto; padding: 0 1.5rem; }
        .ws-hero {
            padding: 56px 0 28px;
        }
        .ws-eyebrow {
            font-family: var(--display-font, 'Fraunces', serif);
            font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase;
            color: var(--ws-ink-mute);
        }
        .ws-title {
            font-family: var(--display-font, 'Fraunces', serif);
            font-size: clamp(30px, 4.6vw, 52px); line-height: 1.05;
            margin-top: 14px; font-weight: 600; letter-spacing: -0.01em;
        }
        .ws-sub { color: var(--ws-ink-soft); font-size: 15px; max-width: 56ch; margin-top: 14px; line-height: 1.55; }

        .ws-section { margin-top: 48px; }
        .ws-section-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .ws-section-title { font-family: var(--display-font, 'Fraunces', serif); font-size: 22px; font-weight: 600; letter-spacing: -0.005em; }
        .ws-section-meta { color: var(--ws-ink-mute); font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; }

        .ws-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .ws-card {
            background: var(--ws-card);
            border: 1px solid var(--ws-line-soft);
            border-radius: 18px;
            padding: 20px 22px;
            display: grid; gap: 8px;
        }
        .ws-card-name { font-family: var(--display-font, 'Fraunces', serif); font-size: 19px; font-weight: 600; }
        .ws-card-tag {
            font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--ws-ink-mute);
        }
        .ws-card-desc { color: var(--ws-ink-soft); font-size: 13.5px; line-height: 1.55; }
        .ws-card-stats { display: flex; gap: 16px; font-size: 12.5px; color: var(--ws-ink-mute); margin-top: 4px; }

        .ws-plan-card {
            background: linear-gradient(160deg, var(--ws-paper-2) 0%, var(--ws-card) 65%);
            border: 1px solid var(--ws-line);
            border-radius: 20px;
            padding: 22px 24px;
            display: grid; gap: 10px;
        }
        .ws-plan-price { font-family: var(--display-font, 'Fraunces', serif); font-size: 28px; font-weight: 600; letter-spacing: -0.01em; }
        .ws-plan-suffix { font-size: 13px; color: var(--ws-ink-soft); font-weight: 500; }

        .ws-form-card {
            background: var(--ws-card);
            border: 1px solid var(--ws-line);
            border-radius: 22px;
            padding: 28px 28px;
        }
        .ws-input {
            width: 100%; padding: 10px 14px; border: 1px solid var(--ws-line);
            background: var(--ws-paper); border-radius: 10px;
            font-size: 14px; color: var(--ws-ink);
        }
        .ws-input:focus { outline: none; border-color: var(--ws-ink); }
        .ws-label { font-size: 11px; letter-spacing: 0.10em; text-transform: uppercase; color: var(--ws-ink-mute); }
        .ws-btn {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 11px 18px; border-radius: 999px; cursor: pointer;
            background: var(--primary, var(--ws-ink));
            color: var(--ws-paper); border: 0; font-size: 13.5px; font-weight: 500;
        }
        .ws-btn:hover { filter: brightness(0.92); }
        .ws-empty { color: var(--ws-ink-mute); font-size: 13px; font-style: italic; }

        /* "Coming soon" plan placeholders. Dimmed so a visitor's eye
           still lands on the enquiry form + Our-spaces below, but the
           model reads at a glance (Hot desk / Dedicated / Private /
           Meeting) so the page doesn't look like a dead lead-capture. */
        .ws-plan-card--soon { opacity: 0.72; position: relative; }
        .ws-card-tag--soon {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 8px; border-radius: 999px;
            background: color-mix(in srgb, var(--primary, var(--ws-ink)) 8%, transparent);
            color: var(--primary, var(--ws-ink));
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: 0.14em;
        }

        /* ── Mobile polish (≤640px) ────────────────────────────────
           Same recipe as units-browse so the two browse surfaces
           feel like sibling pages on a phone, not strangers. */
        @media (max-width: 640px) {
            .ws-shell { padding: 0 1.1rem; }
            .ws-hero { padding: 32px 0 16px; }
            .ws-title { font-size: clamp(26px, 8.5vw, 34px); }
            .ws-sub { font-size: 14.5px; margin-top: 12px; }

            .ws-section { margin-top: 32px; }
            .ws-section-head { margin-bottom: 12px; }
            .ws-section-title { font-size: 20px; }

            .ws-grid { gap: 12px; }
            .ws-card { padding: 18px 18px; }
            .ws-card-name { font-size: 18px; }
            .ws-plan-card { padding: 18px 20px; }
            .ws-plan-price { font-size: 24px; }

            .ws-form-card { padding: 20px 18px; border-radius: 18px; }
            .ws-btn { width: 100%; justify-content: center; }
        }
    </style>

    <div class="ws-shell">
        {{-- Hero --}}
        <header class="ws-hero">
            <span class="ws-eyebrow">Workspaces</span>
            <h1 class="ws-title">Desks &amp; offices, rented by the hour, day, or month.</h1>
            <p class="ws-sub">
                Built-for-work spaces with fast Wi-Fi, ergonomic seating, and meeting rooms.
                Pick a plan or just drop in for a day.
            </p>
        </header>

        {{-- Plans --}}
        <section class="ws-section" data-testid="plans">
            <div class="ws-section-head">
                <h2 class="ws-section-title">Plans &amp; pricing</h2>
                <span class="ws-section-meta">{{ $plans->count() }} option{{ $plans->count() === 1 ? '' : 's' }}</span>
            </div>
            @if ($plans->isEmpty())
                {{-- Placeholder plan grid so a visitor sees the model
                     even before the operator has locked in prices.
                     Reads as "this is a real co-working operation, we
                     just haven't posted rates yet" instead of "empty
                     lead-capture form". Chip on each card makes the
                     placeholder status honest without pretending
                     there's a real rate to book against. --}}
                <div class="ws-grid" data-testid="plans-placeholder">
                    @foreach ([
                        ['Hot desk',         'By the hour or day',   'Drop in, plug in, work.'],
                        ['Dedicated desk',   'Monthly',              'Same desk, same monitor, every day.'],
                        ['Private office',   'Monthly · team pack',  'Lockable room for two to eight.'],
                        ['Meeting room',     'By the hour',          'Book only when you need it.'],
                    ] as [$name, $suffix, $desc])
                        <div class="ws-plan-card ws-plan-card--soon"
                             aria-label="Placeholder plan">
                            <span class="ws-card-tag ws-card-tag--soon">Coming soon</span>
                            <h3 class="ws-card-name">{{ $name }}</h3>
                            <div>
                                <span class="ws-plan-price">₦-</span>
                                <span class="ws-plan-suffix">{{ $suffix }}</span>
                            </div>
                            <p class="ws-card-desc">{{ $desc }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="ws-empty" style="margin-top: 16px;">
                    Rates are being finalised. Drop your details below and we'll ping you when they're live.
                </p>
            @else
                <div class="ws-grid">
                    @foreach ($plans as $plan)
                        <div class="ws-plan-card" data-testid="plan-{{ $plan->id }}">
                            <span class="ws-card-tag">{{ $plan->billing_interval->label() }}</span>
                            <h3 class="ws-card-name">{{ $plan->name }}</h3>
                            <div>
                                <span class="ws-plan-price">₦{{ number_format($plan->price_naira) }}</span>
                                <span class="ws-plan-suffix">{{ $plan->billing_interval->suffix() }}</span>
                            </div>
                            @if ($plan->description)
                                <p class="ws-card-desc">{{ $plan->description }}</p>
                            @endif
                            @if ($plan->workspace)
                                <span class="ws-card-tag">{{ $plan->workspace->name }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Workspaces --}}
        @if ($workspaces->isNotEmpty())
            <section class="ws-section" data-testid="workspaces">
                <div class="ws-section-head">
                    <h2 class="ws-section-title">Our spaces</h2>
                    <span class="ws-section-meta">{{ $workspaces->count() }} space{{ $workspaces->count() === 1 ? '' : 's' }}</span>
                </div>
                <div class="ws-grid">
                    @foreach ($workspaces as $w)
                        @php
                            $avail = $w->availabilitySummary();
                            $noun = $w->unitNoun($avail['available']);
                            $availLabel = match ($avail['status']) {
                                'full'    => 'Fully subscribed',
                                'limited' => $avail['available'].' '.$noun.' left',
                                default   => $avail['available'].' of '.$avail['total'].' '.$noun.' available',
                            };
                            $isFull = $avail['status'] === 'full';
                        @endphp
                        <div class="ws-card" data-testid="ws-{{ $w->id }}">
                            <span class="ws-card-tag" style="
                                {{ $isFull ? 'color: #b00;' : ($avail['status'] === 'limited' ? 'color: #a36318;' : '') }}">
                                {{ $w->type->label() }} · {{ $availLabel }}
                            </span>
                            <h3 class="ws-card-name">{{ $w->name }}</h3>
                            @if ($w->description)
                                <p class="ws-card-desc">{{ $w->description }}</p>
                            @endif
                            <div class="ws-card-stats">
                                <span>Capacity {{ $w->capacity }}</span>
                                @if ($w->hourly_rate_naira)
                                    <span>· ₦{{ number_format($w->hourly_rate_naira) }} /hr</span>
                                @endif
                                @if ($w->daily_rate_naira)
                                    <span>· ₦{{ number_format($w->daily_rate_naira) }} /day</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Enquiry form --}}
        <section class="ws-section">
            <div class="ws-section-head">
                <h2 class="ws-section-title">Get in touch</h2>
                <span class="ws-section-meta">Sign up · day pass · tour</span>
            </div>

            <div class="ws-form-card" data-testid="enquiry-form">
                @if ($submitted)
                    <p role="status" data-testid="enquiry-success"
                       style="color: var(--ws-ink); font-size: 14.5px;">
                        Thanks. We've got your details and will reach out shortly to confirm next steps.
                    </p>
                @else
                    <form wire:submit="submit" style="display: grid; gap: 14px;">
                        <div style="display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                            <div>
                                <label class="ws-label" for="ws-name">Name</label>
                                <input id="ws-name" type="text" class="ws-input"
                                       wire:model.live="name" required maxlength="120"
                                       data-testid="enquiry-name">
                                @error('name') <p style="color:#b00; font-size:12px; margin-top:4px;">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="ws-label" for="ws-email">Email</label>
                                <input id="ws-email" type="email" class="ws-input"
                                       wire:model.live="email" maxlength="160"
                                       data-testid="enquiry-email">
                                @error('email') <p style="color:#b00; font-size:12px; margin-top:4px;">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="ws-label" for="ws-phone">Phone</label>
                                <input id="ws-phone" type="text" class="ws-input"
                                       wire:model.live="phone" maxlength="32"
                                       data-testid="enquiry-phone">
                            </div>
                        </div>

                        @if ($plans->isNotEmpty())
                            <div>
                                <label class="ws-label" for="ws-plan">Interested in</label>
                                <select id="ws-plan" class="ws-input"
                                        wire:model.live="plan_id" data-testid="enquiry-plan">
                                    <option value="">Open to suggestions</option>
                                    @foreach ($plans as $p)
                                        <option value="{{ $p->id }}">{{ $p->name }} · ₦{{ number_format($p->price_naira) }} {{ $p->billing_interval->suffix() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div>
                            <label class="ws-label" for="ws-msg">Anything else?</label>
                            <textarea id="ws-msg" class="ws-input" rows="3" maxlength="2000"
                                      wire:model.live="message"
                                      data-testid="enquiry-message"
                                      placeholder="Team size, preferred start date, special requests…"></textarea>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" class="ws-btn" data-testid="enquiry-submit">
                                <span wire:loading.remove wire:target="submit">Send enquiry</span>
                                <span wire:loading wire:target="submit">Sending…</span>
                                {!! $pillArrow !!}
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </section>
    </div>
</div>
