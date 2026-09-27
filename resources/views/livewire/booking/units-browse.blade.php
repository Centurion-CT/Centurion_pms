@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="rs-page">
    <style>
        .rs-page {
            --rs-paper:    #FAF7F2;
            --rs-paper-2:  #F2EEE6;
            --rs-card:     #FFFFFF;
            --rs-ink:      #14171A;
            --rs-ink-soft: #3D434A;
            --rs-ink-mute: #7A7F87;
            --rs-line:     rgba(20, 23, 26, 0.10);
            --rs-line-soft:rgba(20, 23, 26, 0.06);

            background: var(--rs-paper);
            color: var(--rs-ink);
            font-family: var(--body-font, 'Inter', sans-serif);
            -webkit-font-smoothing: antialiased;
            padding-bottom: 4rem;
        }
        .rs-shell { max-width: 1120px; margin: 0 auto; padding: 0 1.5rem; }
        .rs-hero { padding: 56px 0 28px; }
        .rs-eyebrow {
            font-family: var(--display-font, 'Fraunces', serif);
            font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase;
            color: var(--rs-ink-mute);
        }
        .rs-title {
            font-family: var(--display-font, 'Fraunces', serif);
            font-size: clamp(30px, 4.6vw, 52px); line-height: 1.05;
            margin-top: 14px; font-weight: 600; letter-spacing: -0.01em;
        }
        .rs-sub { color: var(--rs-ink-soft); font-size: 15px; max-width: 60ch; margin-top: 14px; line-height: 1.55; }

        .rs-section { margin-top: 48px; }
        .rs-section-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .rs-section-title { font-family: var(--display-font, 'Fraunces', serif); font-size: 22px; font-weight: 600; letter-spacing: -0.005em; }
        .rs-section-meta { color: var(--rs-ink-mute); font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; }

        .rs-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .rs-card {
            background: var(--rs-card);
            border: 1px solid var(--rs-line-soft);
            border-radius: 18px;
            padding: 22px 24px;
            display: grid; gap: 10px;
        }
        .rs-card-name { font-family: var(--display-font, 'Fraunces', serif); font-size: 20px; font-weight: 600; }
        .rs-card-tag { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--rs-ink-mute); }
        .rs-card-desc { color: var(--rs-ink-soft); font-size: 13.5px; line-height: 1.55; }
        .rs-card-meta { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12.5px; color: var(--rs-ink-mute); margin-top: 4px; }
        .rs-price { font-family: var(--display-font, 'Fraunces', serif); font-size: 22px; font-weight: 600; }
        .rs-price-suffix { font-size: 13px; color: var(--rs-ink-soft); font-weight: 500; }

        .rs-form-card {
            background: var(--rs-card);
            border: 1px solid var(--rs-line);
            border-radius: 22px;
            padding: 28px 28px;
        }
        .rs-input {
            width: 100%; padding: 10px 14px; border: 1px solid var(--rs-line);
            background: var(--rs-paper); border-radius: 10px;
            font-size: 14px; color: var(--rs-ink);
        }
        .rs-input:focus { outline: none; border-color: var(--rs-ink); }
        .rs-label { font-size: 11px; letter-spacing: 0.10em; text-transform: uppercase; color: var(--rs-ink-mute); }
        .rs-btn {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 11px 18px; border-radius: 999px; cursor: pointer;
            background: var(--primary, var(--rs-ink));
            color: var(--rs-paper); border: 0; font-size: 13.5px; font-weight: 500;
        }
        .rs-btn:hover { filter: brightness(0.92); }
        .rs-empty { color: var(--rs-ink-mute); font-size: 13px; font-style: italic; }
        .rs-card--soon { opacity: 0.72; }
        .rs-card-tag--soon {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 8px; border-radius: 999px;
            background: color-mix(in srgb, var(--primary, var(--rs-ink)) 8%, transparent);
            color: var(--primary, var(--rs-ink));
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: 0.14em;
        }

        /* Stack the unit + move-in grid on mobile, `1fr 1fr` was
           making the date input squeeze unusably narrow at 360px. */
        .rs-form-grid-2 { display: grid; gap: 14px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 600px) {
            .rs-form-grid-2 { grid-template-columns: 1fr; }
        }

        /* ── Mobile polish (≤640px) ────────────────────────────────
           Tighter gutters, hero padding, and form chrome so the
           page reads as a single column without losing its airy
           feel. */
        @media (max-width: 640px) {
            .rs-shell { padding: 0 1.1rem; }
            .rs-hero { padding: 32px 0 16px; }
            .rs-title { font-size: clamp(26px, 8.5vw, 34px); }
            .rs-sub { font-size: 14.5px; margin-top: 12px; }

            .rs-section { margin-top: 32px; }
            .rs-section-head { margin-bottom: 12px; }
            .rs-section-title { font-size: 20px; }

            .rs-grid { gap: 12px; }
            .rs-card { padding: 18px 18px; }
            .rs-card-name { font-size: 18px; }
            .rs-price { font-size: 20px; }

            .rs-form-card { padding: 20px 18px; border-radius: 18px; }
            .rs-btn { width: 100%; justify-content: center; }
        }
    </style>

    <div class="rs-shell">
        <header class="rs-hero">
            <span class="rs-eyebrow">Residential leases</span>
            <h1 class="rs-title">A home you can move into, paid annually, signed online.</h1>
            <p class="rs-sub">
                Curated rental units with transparent pricing.
                Schedule a viewing, sign the lease, and we'll handle the paperwork.
            </p>
        </header>

        <section class="rs-section" data-testid="units">
            <div class="rs-section-head">
                <h2 class="rs-section-title">Available units</h2>
                <span class="rs-section-meta">{{ $units->count() }} listed</span>
            </div>

            @if ($units->isEmpty())
                {{-- Placeholder unit grid. Shows the shape of the
                     inventory (studio / 1-bed / 2-bed) even before
                     units are listed so the page reads as "long-let
                     operator with turnover coming", not "empty page,
                     lead form only". --}}
                <div class="rs-grid" data-testid="units-placeholder">
                    @foreach ([
                        ['Studio',       'Furnished · Wi-Fi · utilities included',  'Compact, city-facing.'],
                        ['One-bedroom',  'Furnished · long-let',                    'Separate lounge, walk to work.'],
                        ['Two-bedroom',  'Family or share',                          'Two ensuite rooms, quiet street.'],
                    ] as [$name, $meta, $desc])
                        <div class="rs-card rs-card--soon" aria-label="Placeholder unit">
                            <span class="rs-card-tag rs-card-tag--soon">Coming soon</span>
                            <h3 class="rs-card-name">{{ $name }}</h3>
                            <p class="rs-card-desc">{{ $desc }}</p>
                            <div class="rs-card-stats">
                                <span>{{ $meta }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="rs-empty" style="margin-top: 16px;">
                    Nothing on the books just yet. Register interest below and we'll email as soon as a unit opens.
                </p>
            @else
                <div class="rs-grid">
                    @foreach ($units as $u)
                        @php
                            $avail = $u->availabilitySummary();
                            $isLeased = $avail['status'] !== 'available';
                        @endphp
                        <div class="rs-card" data-testid="unit-{{ $u->id }}">
                            <span class="rs-card-tag" style="
                                {{ $isLeased ? 'color: #b00;' : 'color: #1f6b3a;' }}">
                                {{ $avail['label'] }}
                            </span>
                            <h3 class="rs-card-name">{{ $u->name }}</h3>
                            <p class="rs-card-desc">
                                {{ $u->bedrooms }} bed · {{ $u->bathrooms }} bath
                                @if ($u->size_sqm) · {{ $u->size_sqm }} sqm @endif
                            </p>
                            <p class="rs-card-desc">{{ $u->address }}{{ $u->city ? ', '.$u->city : '' }}</p>
                            @if ($u->description)
                                <p class="rs-card-desc">{{ \Illuminate\Support\Str::limit($u->description, 160) }}</p>
                            @endif
                            <div>
                                <span class="rs-price">₦{{ number_format($u->annual_rent_naira) }}</span>
                                <span class="rs-price-suffix">/year</span>
                            </div>
                            <div class="rs-card-meta">
                                @if ($u->deposit_naira)
                                    <span>Deposit ₦{{ number_format($u->deposit_naira) }}</span>
                                @endif
                                @if ($u->service_charge_annual_naira)
                                    <span>Service ₦{{ number_format($u->service_charge_annual_naira) }}/yr</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rs-section">
            <div class="rs-section-head">
                <h2 class="rs-section-title">Register interest</h2>
                <span class="rs-section-meta">Viewing · application</span>
            </div>

            <div class="rs-form-card" data-testid="enquiry-form">
                @if ($submitted)
                    <p role="status" data-testid="enquiry-success"
                       style="color: var(--rs-ink); font-size: 14.5px;">
                        Thanks. We've got your enquiry and a member of our team will be in touch shortly.
                    </p>
                @else
                    <form wire:submit="submit" style="display: grid; gap: 14px;">
                        <div style="display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                            <div>
                                <label class="rs-label" for="u-name">Name</label>
                                <input id="u-name" type="text" class="rs-input" wire:model.live="name"
                                       required maxlength="120" data-testid="enquiry-name">
                                @error('name') <p style="color:#b00; font-size:12px; margin-top:4px;">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="rs-label" for="u-email">Email</label>
                                <input id="u-email" type="email" class="rs-input" wire:model.live="email"
                                       maxlength="160" data-testid="enquiry-email">
                                @error('email') <p style="color:#b00; font-size:12px; margin-top:4px;">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="rs-label" for="u-phone">Phone</label>
                                <input id="u-phone" type="text" class="rs-input" wire:model.live="phone"
                                       maxlength="32" data-testid="enquiry-phone">
                            </div>
                        </div>

                        <div class="rs-form-grid-2">
                            @if ($units->isNotEmpty())
                                <div>
                                    <label class="rs-label" for="u-unit">Interested in</label>
                                    <select id="u-unit" class="rs-input" wire:model.live="unit_id"
                                            data-testid="enquiry-unit">
                                        <option value="">Any unit</option>
                                        @foreach ($units as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div>
                                <label class="rs-label" for="u-move">Target move-in</label>
                                <input id="u-move" type="date" class="rs-input" wire:model.live="move_in_target"
                                       data-testid="enquiry-move-in">
                            </div>
                        </div>

                        <div>
                            <label class="rs-label" for="u-msg">Anything we should know?</label>
                            <textarea id="u-msg" class="rs-input" rows="3" maxlength="2000"
                                      wire:model.live="message" data-testid="enquiry-message"
                                      placeholder="Household size, employer, references…"></textarea>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" class="rs-btn" data-testid="enquiry-submit">
                                <span wire:loading.remove wire:target="submit">Register interest</span>
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
