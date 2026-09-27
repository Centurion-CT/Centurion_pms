{{--
    Guest-facing footer for the Keverna Stays marketplace.
    Deliberately different from the operator marketing footer
    (welcome.blade.php + the marketing-footer partial we shipped
    earlier and are removing this pass):

      * primary column is "Explore stays" - deep links into
        /stays?q=<city> so the guest can jump to popular cities
        without going back to the home landing
      * "Contact" is second - phone / email / WhatsApp all on
        one row so mobile guests can tap-to-call
      * hosting is a secondary column (guests own this surface,
        hosts are the "you can too" corner)
      * bottom bar strips the currency + region so the guest
        knows what they'll be charged in
--}}
<style>
    .mp-stays-footer {
        margin-top: 80px;
        border-top: 1px solid rgba(15, 18, 21, 0.06);
        padding: 44px 24px 32px;
        background: #F8F7F3;
        color: #0F1215;
        font-family: 'Inter', sans-serif;
    }
    @media (min-width: 900px) { .mp-stays-footer { padding: 52px 40px 32px; } }
    .mp-stays-footer-inner {
        max-width: 1280px; margin: 0 auto;
        display: grid; gap: 32px;
        grid-template-columns: 1fr;
    }
    @media (min-width: 640px)  { .mp-stays-footer-inner { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 1024px) { .mp-stays-footer-inner { grid-template-columns: repeat(4, 1fr); } }

    .mp-stays-footer-col h4 {
        margin: 0 0 14px;
        font-size: 12px; font-weight: 700;
        letter-spacing: 0.08em; text-transform: uppercase;
        color: #0F1215;
    }
    .mp-stays-footer-col ul {
        list-style: none; margin: 0; padding: 0;
        display: grid; gap: 10px;
    }
    .mp-stays-footer-col li { font-size: 13.5px; line-height: 1.5; }
    .mp-stays-footer-col a {
        color: #4F5257; text-decoration: none;
        transition: color 120ms;
    }
    .mp-stays-footer-col a:hover { color: #0F1215; text-decoration: underline; text-underline-offset: 3px; }
    /* Contact column links pair an icon + label. Use inline-flex so the
       SVG sits beside the text — Tailwind's preflight sets `svg { display: block }`,
       which would otherwise stack the glyph above the label. */
    .mp-stays-footer-col li a {
        display: inline-flex; align-items: center; gap: 8px;
    }
    .mp-stays-footer-col li svg {
        width: 14px; height: 14px; color: #6E7278; flex-shrink: 0;
    }

    .mp-stays-footer-brand {
        display: inline-flex; align-items: center; gap: 8px;
        font-weight: 700; font-size: 18px; color: #0F1215;
        text-decoration: none; letter-spacing: -0.02em;
    }
    .mp-stays-footer-brand-mark {
        width: 24px; height: 24px; border-radius: 7px;
        background: #0F1215; color: #fff;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .mp-stays-footer-brand-mark svg { width: 11px; height: 11px; }
    .mp-stays-footer-tag {
        margin: 10px 0 16px; font-size: 12.5px; color: #6E7278;
        line-height: 1.55; max-width: 24ch;
    }
    .mp-stays-footer-trust {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 6px 10px 6px 6px;
        background: rgba(15,18,21,0.04);
        border: 1px solid rgba(15,18,21,0.08);
        border-radius: 999px;
        text-decoration: none; transition: background 160ms;
    }
    .mp-stays-footer-trust:hover { background: rgba(15,18,21,0.08); }
    .mp-stays-footer-trust img { width: 22px; height: 22px; display: block; object-fit: contain; }
    .mp-stays-footer-trust span {
        font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase;
        font-weight: 700; color: #0F1215;
    }

    .mp-stays-footer-meta {
        max-width: 1280px; margin: 28px auto 0;
        padding-top: 20px;
        border-top: 1px solid rgba(15, 18, 21, 0.06);
        display: flex; flex-wrap: wrap;
        gap: 12px; justify-content: space-between;
        font-size: 12px; color: #6E7278;
    }
    .mp-stays-footer-meta-strip {
        display: inline-flex; flex-wrap: wrap; gap: 6px 14px; align-items: center;
    }
    .mp-stays-footer-meta-strip .sep {
        width: 3px; height: 3px; border-radius: 50%;
        background: #6E7278; display: inline-block;
    }
    .mp-stays-footer-meta a {
        color: #0F1215; opacity: 0.72; text-decoration: none; transition: opacity 120ms;
    }
    .mp-stays-footer-meta a:hover { opacity: 1; text-decoration: underline; text-underline-offset: 3px; }
</style>

<footer class="mp-stays-footer" data-testid="mp-stays-footer">
    <div class="mp-stays-footer-inner">

        {{-- ── Column 1: Brand + tagline + trust badge ── --}}
        <div class="mp-stays-footer-col">
            <a href="/" class="mp-stays-footer-brand">
                <span class="mp-stays-footer-brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                </span>
                Keverna Stays
            </a>
            <p class="mp-stays-footer-tag">
                Verified stays across Nigeria. Direct booking, card payment, zero commission on the operator side.
            </p>
            <a href="{{ route('legal.ndpr') }}"
               class="mp-stays-footer-trust"
               data-testid="ndpr-trust-badge"
               aria-label="NDPR compliance trustmark - view certificate">
                <img src="https://fregatelab-file.dokwe.com/trust-badge-only.png"
                     alt="NDPR compliance" loading="lazy">
                <span>NDPR audited</span>
            </a>
        </div>

        {{-- ── Column 2: Explore stays ── --}}
        <div class="mp-stays-footer-col">
            <h4>Explore stays</h4>
            <ul>
                <li><a href="{{ route('marketplace.stays.index') }}">Browse all stays</a></li>
                <li><a href="{{ route('marketplace.stays.index', ['q' => 'Lagos']) }}">Stays in Lagos</a></li>
                <li><a href="{{ route('marketplace.stays.index', ['q' => 'Abuja']) }}">Stays in Abuja</a></li>
                <li><a href="{{ route('marketplace.stays.index', ['q' => 'Port Harcourt']) }}">Stays in Port Harcourt</a></li>
                <li><a href="{{ route('marketplace.stays.index', ['q' => 'Enugu']) }}">Stays in Enugu</a></li>
                <li><a href="{{ route('marketplace.stays.index', ['q' => 'Ibadan']) }}">Stays in Ibadan</a></li>
            </ul>
        </div>

        {{-- ── Column 3: Contact ── --}}
        <div class="mp-stays-footer-col">
            <h4>Contact</h4>
            <ul>
                <li>
                    <a href="mailto:hello@keverna.com">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M2.5 4l5.5 5 5.5-5"/></svg>
                        hello@keverna.com
                    </a>
                </li>
                <li>
                    <a href="tel:+2347043043061">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4c0 5.5 3.5 9 9 9v-2.5l-2.5-.5-1.5 1.5c-2.2-1.1-3.4-2.3-4.5-4.5L5 5.5 4.5 3H2z"/></svg>
                        +234 704 304 3061
                    </a>
                </li>
                <li>
                    <a href="https://wa.me/2347043043061"
                       target="_blank" rel="noopener"
                       data-testid="stays-footer-whatsapp">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2a6 6 0 0 0-5 9L2 14l3-1a6 6 0 1 0 3-11zM6 6c.3 1 1 2 2 2.5.7.4 1 .5 1.5 0M6 6c.4-.4 0-1.5-.5-1.5"/></svg>
                        Chat on WhatsApp
                    </a>
                </li>
            </ul>
        </div>

        {{-- ── Column 4: For hosts + Legal ── --}}
        <div class="mp-stays-footer-col">
            <h4>For hosts</h4>
            <ul>
                <li><a href="{{ route('for-hosts') }}">List your property</a></li>
                <li><a href="{{ route('pricing') }}">Pricing for hosts</a></li>
                @if (Route::has('login'))
                    <li><a href="{{ route('login') }}">Host sign-in</a></li>
                @endif
            </ul>

            <h4 style="margin-top: 22px;">Legal</h4>
            <ul>
                <li><a href="{{ route('legal.privacy') }}">Privacy</a></li>
                <li><a href="{{ route('legal.terms') }}">Terms</a></li>
                <li><a href="{{ route('legal.cookies') }}">Cookies</a></li>
                <li><a href="{{ route('legal.ndpr') }}">NDPR</a></li>
            </ul>
        </div>

    </div>

    <div class="mp-stays-footer-meta">
        <span>© {{ date('Y') }} FregateLab Limited. Built in Abuja.</span>
        <span class="mp-stays-footer-meta-strip">
            <span>🇳🇬 English (NG)</span>
            <span class="sep" aria-hidden="true"></span>
            <span>Prices in <strong>&#8358; NGN</strong></span>
        </span>
    </div>
</footer>
