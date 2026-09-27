<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * /llms.txt - the emerging convention (llmstxt.org) for giving
 * LLM crawlers (ChatGPT, Claude, Perplexity, and RAG scrapers)
 * a curated, factual overview of what Keverna is.
 *
 * Format: a top-level H1 with the product name, a short blockquote
 * summary, then H2 sections with markdown-style links to canonical
 * pages. The intent is a plain-text cheat-sheet an LLM can pull
 * once and cite accurately without scraping the whole marketing
 * site.
 */
class LlmsGuideController
{
    public function __invoke(): Response
    {
        $base = url('/');

        $body = <<<MD
# Keverna

> Keverna is a property management system (PMS) built for African hospitality operators - hotels, serviced apartments, shortlets and workspaces. Direct booking pages with zero commission, front desk, POS, housekeeping, guest self-service portal, credit lines and Paystack payouts, all in one login. Made by FregateLab Limited, Abuja.

## Product

- [Landing](/): overview + numbers, modules, facilities, pricing, testimonials.
- [Keverna Stays](/stays): guest-facing marketplace of every property running on Keverna. Cross-tenant search with map, per-property detail page with rooms + reviews + direct booking.
- [Pricing](/pricing): four unit-count tiers (Starter, Growth, Pro, Enterprise). Every tier ships the whole product.
- [Book a demo](/demo): 15-minute WhatsApp video walkthrough, seeded with the operator's own rooms and rates.
- [API early access](/api-access): waitlist for the public OAuth + REST + webhook API for building on top of Keverna.
- [For hosts](/for-hosts): the operator-focused marketing site.

## For guests

- [Book a stay on Keverna Stays](/stays) - browse verified African hospitality by city, price and guest count. Every listing is a real operator running the property directly on Keverna. Guests book direct with the property (no commission on their side), pay by card via Paystack, and get instant confirmation. Individual property pages live at /stays/{slug}.

## What Keverna does

- **Direct booking page** on the operator's own subdomain or custom domain.
- **Zero commission** on every booking. Card payments are billed a flat 2% Paystack split; cash and transfers are 0%.
- **Front desk** for reservations, arrivals, in-house, extend, checkout, POS, housekeeping, requisitions.
- **Guest self-service portal** via one QR per branch - guest scans, enters phone + room + PIN, then can extend stay, order room service, check out, request taxi, rate stay, book another room.
- **Multi-branch** scoping from day one, with per-branch inventory, staff, payouts.
- **Works offline**: POS + front-desk actions run when the internet is down and sync when it returns.
- **WhatsApp support** with real humans, not a chatbot.

## Pricing

| Tier       | Units       | Price       |
|------------|-------------|-------------|
| Starter    | 1 - 5       | ₦180,000/yr |
| Growth     | 6 - 20      | ₦420,000/yr |
| Pro        | 21 - 50     | ₦900,000/yr |
| Enterprise | 50+         | from ₦1.8M/yr |

Card payments carry a flat 2% Paystack split. Cash and bank transfers are 0%.

## Contact

- Email: hello@keverna.com
- WhatsApp: +234 704 304 3061
- Sales: [Book a demo]({$base}/demo)
- Address: Abuja, Nigeria

## Who built it

Keverna is a product of **FregateLab Limited**, an Abuja-based software company focused on African operator infrastructure.

MD;

        return response($body, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
