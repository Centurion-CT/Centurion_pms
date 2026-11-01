# centurionapartments.ng: website design options

Open `index.html` to compare the three directions. Each folder has `home.html` and `results.html`, plus full-page screenshots at desktop and phone widths (`*.png`, `*-phone.png`).

| Option | Folder | In one line |
| --- | --- | --- |
| A · Availability Index | `a-index/` | The search reads as a sentence, and the logo's bars show free apartments per building tonight |
| B · Neighbourhoods | `b-neighbourhoods/` | Full-bleed photography, the five names in Bodoni as the headline, booking in a drawer |
| C · Map Desk | `c-map/` | Map-first and app-like: a price on every pin, list and map results, quick booking card |

## What the research found

**Today's site.** centurionapartments.ng is only an iframe of a Tripmata page. That page lists five Abuja buildings with "from" prices:

| Building | From, per night |
| --- | --- |
| Wuse 2 (39 Agadez Street) | ₦59,400 |
| Zone 4 | ₦71,100 |
| Jabi | ₦58,500 |
| Jabi Annex | ₦60,000 |
| Lifecamp (2-bed duplex, pool and gym) | ₦50,000 |

- Wuse 2 has five apartment types, from Standard to Two bedroom family.
- Phones: 0816 241 7819 (reservations) and 0908 835 8213 (care).
- None of this is the hotel's own site: no search across buildings, no brand, and the URL and SEO belong to Tripmata.
- The mockups use this real content. Free-room counts, and the room types at the four other buildings, are sample data.

**diplomatsenclavesuites.com (the peer).**
- Look: dark plum and gold, Cormorant Garamond at 96px, and a full-bleed slideshow hero.
- Booking bar: under the hero, with dates and adults only.
- Page order: rooms (3 cards), experiences, a testimonial, gallery, location, then a WhatsApp float.
- It's well made for a single hotel, but has no location choice and no live availability. Centurion's advantage is five buildings and real-time stock, so every option puts searching across buildings first.

**Patterns from award-winning and best-in-class stay sites.** Sonder, Locke, Numa, Zoku, The Hoxton, Aman and Awwwards hotel winners are where these patterns come from. They're from review, not measured, because the design gallery tool wasn't connected.
- **Several locations:** the search has a location field that defaults to "all", and results are grouped by location (A, B). Or the search is a map with prices on the pins (C).
- **Hero:** the booking control sits in or right under the hero. Luxury brands lead with one large photo (B). Product-style brands lead with the search itself (A, C).
- **Trust:** a review score next to the price, "last one" scarcity, the total for the stay (not just per night), and the cancellation policy right next to the pay button.
- **Long stays:** monthly pricing gets its own entry point. It matters for serviced apartments; Locke and Numa both do this.

**Photography is the biggest risk.** The current photos are a few phone shots, one from 2020 at 5312px and the rest under 1400px. The mockups use Unsplash stand-ins in `assets/`. Whichever option wins, the site needs a shoot of each building and each apartment type. B depends on photos the most and A the least.

## How it stays in sync with the HMS

What exists in the HMS today:
- Booking pages for each branch: `/branches/{slug}/rooms`, `/branches/{slug}/categories/{slug}`, and the `CategoryDetail` / `RoomDetail` Livewire pages calling `CreateBooking` then Paystack.
- Availability (`AvailabilityService::categoryFreeUnits`, `RoomAllocator`) and pricing (`PricingCalculator`).
- Galleries on `Property`, `RoomCategory` and `Room`.

What's missing:
- A search across all branches.
- A public home page (`/` sends visitors to the staff login).
- Any public API.

**Recommended: build the website inside the HMS.**
- centurionapartments.ng points at the same Laravel app as centurionpms.com, and the public pages are Livewire.
- Categories, photos, rates, availability and bookings come from the same database. There's nothing to sync and no delay, and a booking made on the site appears at the front desk at once.
- New work:
  1. Map the public domain to the public routes.
  2. Add a cross-branch search service that loops every branch, counting free units by category and pricing the stay.
  3. Build the home and results pages in the chosen design.
  4. Reuse the existing `CreateBooking` + Paystack flow for payment.
- Branch descriptions and area notes ("Banex on foot") become fields the manager edits in Settings.

**Alternative: a separate site** (for example Next.js on Vercel), with a new public API in the HMS:
- endpoints: `GET /locations`, `GET /search?from&to&guests&locations[]`, `POST /quotes`, `POST /bookings` returning a Paystack link
- plus API keys, CORS and caching

It means more work and two codebases to deploy. It's only worth it if a separate agency will run the website.
