{{--
    Region picker — a client-side country/currency chooser shown across
    every marketing page.

    Two surfaces, both driven by the same Alpine x-data root (`kevernaRegion`):

    1. Modal that auto-opens on first visit (no `kv_region` cookie set)
       so a visitor from Kenya sees prices in USD before they even
       scroll. Once picked, a 90-day cookie carries the choice through
       the whole marketing funnel.
    2. Persistent header dropdown that always shows the current pick
       and lets the visitor swap without hunting for the modal.

    Any pricing block elsewhere (pricing.blade.php, welcome CTA cells,
    etc.) reads region via `x-show="$store.kv.region === 'NG'"` — no
    server round-trip needed on switch.

    Regions:
      - NG   → Nigeria, prices in naira (₦)
      - INTL → everywhere else, prices in USD ($). We'll extend to
               proper local currencies once FX support lands.
--}}

{{-- Alpine store: single source of truth for the region across the
     whole page. Any component reads `$store.kv.region`. --}}
<script>
    (() => {
        // Read the cookie synchronously at script-eval time so the
        // Alpine store is created with the correct initial state on
        // its very first tick. Previous implementation used a microtask
        // inside init() which fired before the modal's reactive x-show
        // binding was live, so the first-visit auto-open silently
        // dropped.
        const readRegion = () => {
            const raw = document.cookie.split('; ').find(r => r.startsWith('kv_region='));
            return raw ? decodeURIComponent(raw.split('=')[1]) : null;
        };
        // Per-country config. WhatsApp numbers, currency codes, and
        // testimonial names swap when the visitor picks their country.
        // Photography swap is a follow-up (requires per-country assets
        // in the CDN); the copy fields flip today so a Kenyan visitor
        // reads Nairobi operator names + a Nairobi WhatsApp support
        // line, not the default Nigerian ones. Kept in one map so a
        // new market slots in without hunting through the tree.
        const KV_COUNTRIES = {
            'NG': {
                label: 'Nigeria',        flag: '🇳🇬', currency: 'NGN',
                whatsapp: '+2347043043061',
                cities: 'Abuja · Lagos · Port Harcourt',
                sample: 'Adaeze in Maitama',
            },
            'KE': {
                label: 'Kenya',          flag: '🇰🇪', currency: 'KES',
                whatsapp: '+254700043061',
                cities: 'Nairobi · Mombasa · Kisumu',
                sample: 'Wanjiru in Westlands',
            },
            'ZA': {
                label: 'South Africa',   flag: '🇿🇦', currency: 'ZAR',
                whatsapp: '+27100043061',
                cities: 'Cape Town · Johannesburg · Durban',
                sample: 'Thandi in Sea Point',
            },
            'GH': {
                label: 'Ghana',          flag: '🇬🇭', currency: 'GHS',
                whatsapp: '+233240043061',
                cities: 'Accra · Kumasi · Takoradi',
                sample: 'Ama in East Legon',
            },
            'INTL': {
                label: 'Everywhere else', flag: '🌍', currency: 'USD',
                whatsapp: '+2347043043061',
                cities: 'Global · USD pricing · WhatsApp direct',
                sample: 'a boutique operator running any stay-length',
            },
        };
        document.addEventListener('alpine:init', () => {
            const stored = readRegion();
            const valid = (r) => Object.prototype.hasOwnProperty.call(KV_COUNTRIES, r);
            const initial = valid(stored) ? stored : null;
            Alpine.store('kv', {
                region: initial,
                openPicker: initial === null,
                countries: KV_COUNTRIES,
                get country() {
                    return this.region && KV_COUNTRIES[this.region]
                        ? KV_COUNTRIES[this.region]
                        : KV_COUNTRIES['NG']; // Nigeria-first fallback.
                },
                set(next) {
                    if (! valid(next)) return;
                    this.region = next;
                    const expires = new Date(Date.now() + 90 * 864e5).toUTCString();
                    document.cookie = `kv_region=${encodeURIComponent(next)}; expires=${expires}; path=/; SameSite=Lax`;
                    this.openPicker = false;
                },
                open() { this.openPicker = true; },
            });
        });
    })();
</script>

{{-- Modal shell. Teleported to <body> so a marketing page's own
     overflow / transform ancestors can't clip it. Splitting x-teleport
     onto its own <template> and letting a plain x-show inside handle
     visibility — combining x-teleport with x-if on the same template
     rendered the modal twice (once inline, once at the teleport
     target) in Alpine 3.x. --}}
<template x-teleport="body">
    <div x-data x-show="$store.kv.openPicker" x-cloak
         role="dialog" aria-modal="true" aria-labelledby="kv-region-title"
         data-testid="region-picker-modal"
         style="position: fixed; inset: 0; z-index: 500;
                display: flex; align-items: center; justify-content: center;
                background: rgba(15, 18, 21, 0.62); padding: 20px;">
        <div style="width: min(460px, 100%); background: #F7F3EA;
                    border-radius: 18px; padding: 28px 26px;
                    box-shadow: 0 32px 72px -24px rgba(15, 18, 21, 0.4);
                    font-family: 'Inter', system-ui, sans-serif;
                    color: #0F1215;">
            <p style="font-family: 'JetBrains Mono', ui-monospace, monospace;
                      font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase;
                      color: #6E7278;">
                Welcome to Keverna
            </p>
            <h2 id="kv-region-title"
                style="font-family: 'Fraunces', Georgia, serif; font-weight: 600;
                       letter-spacing: -0.025em; font-size: 26px;
                       margin: 6px 0 8px;">
                Where are you running your property?
            </h2>
            <p style="font-size: 14px; color: #4B4F53; line-height: 1.55;">
                We'll show pricing in your currency. Nigerian operators run in naira;
                everyone else runs in US dollars for now, with local currencies
                landing soon.
            </p>

            <div style="display: grid; gap: 8px; margin-top: 22px;">
                {{-- Iterate the full country map so the modal grows
                     with new markets without a per-country blade edit.
                     Keys map 1:1 to the Alpine store constants. --}}
                <template x-for="(c, key) in $store.kv.countries" :key="key">
                    <button type="button"
                            x-on:click="$store.kv.set(key)"
                            :data-testid="'region-pick-' + key.toLowerCase()"
                            style="display: flex; align-items: center; justify-content: space-between;
                                   padding: 14px 18px; border-radius: 12px; border: 1.5px solid rgba(15, 18, 21, 0.14);
                                   background: #FFFFFF; cursor: pointer; text-align: left;
                                   font-family: inherit; color: inherit;
                                   transition: border-color 150ms, background 150ms;"
                            onmouseover="this.style.borderColor='#0F1215'"
                            onmouseout="this.style.borderColor='rgba(15, 18, 21, 0.14)'">
                        <span>
                            <span style="font-size: 22px; margin-right: 8px;" aria-hidden="true" x-text="c.flag"></span>
                            <span style="font-weight: 600; font-size: 15px;" x-text="c.label"></span>
                            <span style="display: block; font-size: 12px; color: #6E7278; margin-top: 2px;">
                                <span x-text="c.currency"></span>
                                <span> · </span>
                                <span x-text="c.cities"></span>
                            </span>
                        </span>
                        <span style="font-size: 18px; color: #6E7278;">→</span>
                    </button>
                </template>
            </div>

            <p style="font-size: 11.5px; color: #6E7278; margin-top: 18px;">
                You can change this anytime from the country picker at the top of the page.
            </p>
        </div>
    </div>
</template>

{{-- Persistent header dropdown. Drop this next to the primary nav
     on any marketing page so visitors can flip regions on demand. --}}
<div x-data="{ open: false }"
     x-on:click.outside="open = false"
     data-testid="region-header-picker"
     style="position: relative;">
    <button type="button"
            x-on:click="open = !open"
            data-testid="region-header-toggle"
            style="display: inline-flex; align-items: center; gap: 8px;
                   padding: 8px 12px; border-radius: 999px;
                   border: 1px solid rgba(15, 18, 21, 0.14);
                   background: rgba(255, 255, 255, 0.6);
                   font-family: inherit; font-size: 13px; color: #0F1215;
                   cursor: pointer;">
        {{-- Chip reads the active country from the store so a Kenyan
             visitor sees 🇰🇪 KES, a South African sees 🇿🇦 ZAR, etc.
             Nigeria-first fallback ($store.kv.country) means the chip
             never shows an empty state even before the visitor picks. --}}
        <span style="display: inline-flex; align-items: center; gap: 6px;">
            <span aria-hidden="true" x-text="$store.kv.country.flag"></span>
            <span x-text="$store.kv.country.currency"></span>
        </span>
        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"
             style="opacity: 0.5;">
            <path d="M2 4l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>

    <div x-show="open"
         x-cloak
         x-transition.opacity.duration.150ms
         style="position: absolute; top: calc(100% + 6px); right: 0;
                min-width: 240px; padding: 6px;
                background: #FFFFFF; border-radius: 12px;
                border: 1px solid rgba(15, 18, 21, 0.08);
                box-shadow: 0 18px 40px -20px rgba(15, 18, 21, 0.28);
                z-index: 40;">
        <template x-for="(c, key) in $store.kv.countries" :key="key">
            <button type="button"
                    x-on:click="$store.kv.set(key); open = false"
                    :data-testid="'region-header-' + key.toLowerCase()"
                    style="display: flex; align-items: center; justify-content: space-between; gap: 10px;
                           width: 100%; padding: 10px 12px; border: 0; background: transparent;
                           border-radius: 8px; text-align: left; cursor: pointer;
                           font-family: inherit; font-size: 13px; color: #0F1215;"
                    onmouseover="this.style.background='#F7F3EA'"
                    onmouseout="this.style.background='transparent'">
                <span style="display: inline-flex; align-items: center; gap: 8px;">
                    <span aria-hidden="true" x-text="c.flag"></span>
                    <span>
                        <span x-text="c.label"></span>
                        <span style="color: #6E7278; margin-left: 6px;">·</span>
                        <span x-text="c.currency" style="color: #6E7278; margin-left: 4px;"></span>
                    </span>
                </span>
                <span x-show="$store.kv.region === key" style="color: #8AAE3A;">✓</span>
            </button>
        </template>
    </div>
</div>
