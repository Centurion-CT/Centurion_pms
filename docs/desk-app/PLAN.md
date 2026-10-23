# Centurion Desk (Windows): plan

Draft 2026-10-23. A native Windows app for managers and front desk officers at Centurion Apartments to do all
reservation and in-house work at reception. Not a wrapped website, and not a re-skin of Keverna Desk: it shares the
server rules and plumbing, but its screens are designed from Centurion's own design system
(`.superdesign/design-system.md`).

## Decisions
- **Native Flutter, Windows 10/11.** New app `desktop/` in this repo, "Centurion Desk", bundle/company Centurion
  Apartments, the Centurion logo as the app icon. Min window 1280 x 800, opens 1440 x 900.
- **Roles:** manager, front desk officer, reservation officer. Everyone else gets "This app is for reception. Use the
  web dashboard or the Centurion Staff app."
- **Shared with Keverna, below the screens only:** the host API, the desk unlock endpoints and the Dart core (api
  client, outbox, cache, models, repositories). Every booking, payment and check-in goes through the same server
  actions as the web, so the desk, the web and the phone can't disagree.
- **Not shared:** layouts, navigation, type, colour, icons, copy, screen flow. See "Design" below.
- **Rarely used admin** (settings, rates, reports, accounting, inventory, corporates) opens the web dashboard already
  signed in (one-time web handoff), inside the app.
- **POS at reception** comes after v1.

## Design (phase 0)
Built from Centurion's system, so the desk feels like the web dashboard's sibling that staff already know:
- Bricolage Grotesque headings, Hanken Grotesk UI with tabular figures for money. No serif, no mono labels.
- Centurion Blue #336799, Harbour navy #16324B / #0F2438, Mist #EEF3F7 background, Ankara gold #E3A83A for money
  only. Borders over shadows; 8px controls, 12px cards.
- Labelled list sidebar like the web dashboard's 3A layout, with the per-function nav colours. Keverna's icon rail and
  right-hand "Do next" column are not used.
- One signature element per screen from the logo's rising bars, for example "Tonight's house": occupancy floor by
  floor drawn as bars, which opens the room board.
- Two directions (A "Daylight", B "Harbour" navy chrome) on five key screens at 1440 x 900: Today, calendar, new
  booking, folio, check-in. Files in `design/desk-app/` (index.html compares them).
- **Chosen 2026-10-23: B "Harbour".** Navy window and sidebar; tabs for open work (Ctrl Tab), each guest or new
  booking opens in its own tab; logo occupancy dial on Today; check-in and other multi-step tasks are side sheets
  over the current tab; the receipt preview shows what will print. Full screen set drawn next for approval.

## Scope v1
| Area | What |
|---|---|
| Today | Arrivals, departures, in-house, overdue, rooms not ready, money owed today; live updates |
| Calendar | Room x date grid; drag to move a booking or change dates; click an empty cell to book |
| Bookings | Search; walk-in and phone booking; guest profile, history and wallet; bill to a company |
| Folio | Payments, charges, extend, move room, discounts (sent for approval), refunds, invoices |
| Check-in / out | Registration card print, guest ID details, room assignment; checkout blocked while money is owed |
| In-house | Room and housekeeping board (shows the Staff app's progress), assign a cleaner, guest requests and replies, approvals |
| Printing | A4 invoice, receipt and registration card; 80 mm receipts |
| My shift | What I took this shift by payment method; printable handover |

Desktop habits: keyboard first (Ctrl K search, F2 new booking, Esc closes, Enter confirms). A shared reception PC
switches operator with a staff PIN and locks itself after 5 minutes idle, so every action is recorded against the
person. Works offline with a send-later queue. Windows notifications for new web bookings, guest requests and
approvals. Signed installer with auto-update.

## Server work
centurion-hms (and keverna-solo, which forked before these landed) has none of Keverna's host or desk API yet.
- Port the host API (`/api/mobile/v1/host/*`: session, today, meta, bookings and their actions, calendar, rooms,
  housekeepers, inbox, guest requests, notifications, search, web handoff) and the desk endpoints (`desk/session`,
  `operators`, `unlock`, `lock`) from Keverna, adapted to solo mode. Leave out the owner finance screens.
- Most booking actions already exist here. Missing pieces to bring across or trim the endpoint to: held-money refund
  and forfeit, guest request reply and resolve, guest-action and corporate approvals, AssignRoomCleaner,
  SetRoomHousekeepingStatus, HostPush, DashboardSearch, NotificationFeed, PaymentMethod enum.
- The staff PIN already on operators (staff app) unlocks the desk too. Managers set it on the Staff page.
- New: shift summary per operator; guest ID fields at check-in (ID type, number, nationality, expiry, optional scan)
  with a retention period.
- Generic server code is mirrored to keverna-solo as usual; Centurion branding is not.

## Server: built (branch `desk-api`, 2026-10-23)
Ported from Keverna (upstream 176d436 host API, 2656001 push, and its in-progress desk work), adapted to
Centurion. Centurion's web dashboard code is unchanged except the room form (floor), the reservation page (ID row)
and the Staff page (desk PIN).
- **Set up:** central `POST /api/mobile/v1/login` with `X-Keverna-App: desk`, `platform: desktop`; host roles only.
- **Desk:** `desk/session`, `desk/property`, `desk/operators` (who can use the desk, has_pin, last unlock),
  `desk/unlock` {operator_id, pin} returns that person's 12 h token, `desk/lock`. 5 wrong PINs lock that name for
  15 min. The desk's own token can do nothing else; activity from a desk is logged with via=desk.
- **PIN:** the staff PIN (`operators.staff_pin_hash`), shared with the Staff app. Managers set it on the Staff page
  ("Set desk PIN"), their own included.
- **Front desk API (`host/*`):** session, today, meta, available rooms, customer lookup, bookings (list, create,
  show, payments, check-in/out, charges, extend + quote, no-show, cancel, resend confirmation), folio (move options,
  move room, change dates, discount request, ID document + image, invoice, payment receipt), calendar, rooms (status,
  assign cleaner, history, housekeepers), inbox (counts, approvals, decide: portal actions and held money), guest
  requests (reply, resolve, reopen), notifications, search, web handoff (`/auth/app-handoff`), device push settings.
- **Left out:** owner finance screens, phone + PIN host login, corporate request / rate / transfer approvals,
  per-occupant charges, multi-room category holds.
- **Migrations:** push fields on mobile_devices, desk fields on mobile_devices, rooms.floor, guest_id_documents.
- **Guest ID retention:** `keverna.guest_id_retention_months` (12, placeholder), purged nightly at 03:30 Lagos.

## Phases
0. **Design**: two Centurion directions on five screens; pick one; full screen set approved.
1. **Foundation**: server port with Pest tests; `desktop/` shell, desk setup, operator PIN unlock and auto-lock,
   offline queue, print service, Today screen, search.
2. **Front desk**: bookings, new booking, folio, payments, check-in/out with registration card. Windows build in CI.
3. **Calendar and in-house**: drag calendar, rooms and housekeeping, requests, approvals, my shift.
4. **Hardware and release**: ID scanner, card terminal, notifications, MSIX installer, code signing, auto-update,
   trial at the Centurion front desk.
5. **POS** (after release): reception sales and charge to room.

## Needs from the owner
- Production web address (built into the app, as for the Staff app).
- A Windows PC for trying builds. This Mac can't build Windows; CI builds it.
- Code-signing certificate, otherwise Windows SmartScreen warns on install.
- Models of the receipt printer, ID scanner and card terminal used at reception.
- How long guest ID details are kept (NDPA).
