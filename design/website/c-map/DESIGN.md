# Map Desk: design system (Option C, v2)

`system.css` holds every token and component, and `system.html` shows them all. The pages (`home.html`, `results.html`, `checkout.html`) add layout only. They never set their own colours, sizes or radii. The first version is kept in `v1/` for comparison.

## What changed from v1

| v1 | v2 |
| --- | --- |
| Six radii (12–28px) chosen per element | Four radii tied to object size: 6 tags, 10 controls, 16 cards, 24 map |
| A soft shadow under every card | Cards use a hairline; only floating things cast a shadow (search, popovers, markers) |
| Yellow call to action, mint tags, navy and blue all competing | Blue = actions only; green and amber = availability only; everything else is one cool ink scale |
| Ad-hoc font sizes | Instrument Sans on a 1.2 scale (12 → 56), tighter tracking as size grows |
| Hand-drawn SVG map | Real street map (Leaflet + Esri tiles, tinted to the ink scale) with price markers at real coordinates |
| Location chips doubled as the location list | One location list beside the map, kept in step with the map: click either one and the other follows |
| "3 left" was the only availability signal | **Availability strip**: 14 bars per apartment, one per night, taken from the logo's bar chart |
| Booking panel squeezed over the map | A detail panel replaces the map (photo, calendar, price lines), then a separate checkout step |

## Tokens

- **Ink:**
  - 900 `#0E2238` headings
  - 700 `#33475C` body
  - 500 `#5F7387` secondary (4.9:1 contrast on white)
  - 300 `#A7B5C2` disabled
  - 200 `#D6DFE7` borders
  - 100 `#EAF0F5` fills
  - 50 `#F5F8FA` page bands
- **Brand:**
  - 600 `#336799` (the logo blue) for primary buttons and selection
  - 700 `#24507C` for hover
  - 50 `#EEF4FA` for the selected wash
- **Availability:**
  - free `#1F9A72`, text `#0F6B4F` on `#E2F4EC`
  - last one `#E09B2D`, text `#8F5300` on `#FFF1D9`
  - taken `#D6DFE7`
- **Type sizes (size/line height):** xs 12/16, sm 14/20, md 16/24, lg 19/28, xl 23/30, 2xl 28/34, 3xl 40/44, 4xl 56/58. Weights 400 and 600.
- **Space:** a 4px grid: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 96.
- **Elevation:**
  - `--e-float` for the search bar, popovers and floating panels on the map
  - `--e-marker` for map markers
  - nothing else casts a shadow

## Components

- **Button:** primary, secondary, ghost, inverse, in sm, md and lg. One primary per view. Labels name the action ("Search", "Continue", "Pay ₦213,300").
- **Search:** segments that each open a picker. "Where" is multi-select across the five locations and shows the free count for each. A compact version sits in inner-page headers.
- **Chip:** a filter that can be pressed on or off.
- **Tab:** switches views inside a section, with an underline for the selected tab.
- **Tag:** free, last one or taken. Used only for availability.
- **Availability strip:** 14 nights. A free night is tall and green, the last one is mid-height amber, and a taken night is short and grey. The guest's own nights are underlined. It also shows when a full apartment frees up.
- **Listing card:** contents, in order:
  1. photo with an availability tag
  2. name and score
  3. location and number of guests it sleeps
  4. the availability strip
  5. the nightly price, then the total for the stay
- **Map marker:** a pill showing the lowest price, with a dot in the availability colour. Its states are default, last one, selected (ink) and dimmed (filtered out).
- **Calendar, price lines, field, radio card, stepper:** used in the detail panel and checkout.

## Data each piece needs from the HMS

| UI | HMS source |
| --- | --- |
| Markers and the location list | `Property` (name, area, lat/lng, gallery, amenities), lowest `RoomCategory` rate |
| "N free tonight" | `AvailabilityService::categoryFreeUnits` summed per property for tonight |
| Availability strip | `categoryFreeUnits` per category for each of the next 14 nights, mapped to free / last one (1 left) / taken (0 left) |
| Listing price and total | `PricingCalculator` for the chosen dates |
| Detail and checkout | `CreateBooking`, then Paystack (`InitiateBookingPayment`) |

## Notes for the build

- The map should use MapLibre with a styled vector map matching the ink scale (no raster filter), and lat/lng from each branch.
- Photos are stand-ins. Each `RoomCategory` gallery needs real photos.
- The free-tonight counts and strips in these mockups come from a seeded sample, not live data.
