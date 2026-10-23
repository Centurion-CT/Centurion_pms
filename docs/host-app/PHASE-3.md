# Host app, phase 3: calendar, rooms, inbox, notifications, search (contract)

Read first: DECISIONS.md, PHASE-1.md, PHASE-2.md (host group, branch binding, errors, idempotency, write shape
`{status:"ok", <thing>:...}`). Designs: design/host-app/a/17, 18, 18b, 19, 20, 20b, 21, 29 (+ inbox badge on the tab bar).
Everything branch-scoped and tenant-isolated; reuse the same Actions/Services the web uses (no new business rules).

## Server endpoints (/api/mobile/v1/host/...)
### Calendar (17)
- `GET calendar?from=YYYY-MM-DD&days=7` (days 1..31) -> {data:{from, to, today,
    rooms:[{id, number, category, category_short}],
    stays:[{ref, room_id, guest_name, guest_surname, start, end,   // end exclusive (checkout date), clipped to window
            state: arriving|in_house|booked|overdue|departing, balance_due}],
    blocks:[{room_id, start, end, kind: maintenance|blocked, reason}],
    occupancy:[{date, pct}] }}
  overdue = checked-in past checkout (shown from booked checkout through today). Same data the web calendar uses.

### Rooms board (18, 18b)
- `GET rooms?state=all|ready|dirty|occupied|out_of_order` -> {data:[{id, number, category, floor|null,
    housekeeping: clean|dirty|inspected|out_of_order, occupancy: occupied|arriving|free,
    tile_state: ready|dirty|occupied|arriving|out_of_order,      // what the tile colour shows
    guest:{ref, name, checkout, overdue}|null, next_arrival:{ref, name, time_label}|null,
    dirty_since|null, cleaner:{id,name}|null, open_inspection_id|null }],
    counts:{ready, dirty, occupied, arriving, out_of_order, total}}
- `POST rooms/{id}/status` {state: clean|dirty|out_of_order, reason?} -> {status, room}
  Same rules as web Housekeeping (markRoomClean / setRoomStatus); clean on a room with an open inspection follows the web rule.
- `GET housekeepers` -> {data:[{id, name, rooms_today}]}  (operators who can clean, same list the web assign-cleaner modal uses)
- `POST rooms/{id}/assign-cleaner` {operator_id} -> {status, room}  (App\Actions\Housekeeping\OpenRoomInspection, as web)
- `GET rooms/{id}/history` -> reuse existing response shape from the staff app endpoint (or wrap it).

### Inbox (19, 20, 20b) and badge
- `GET inbox/counts` -> {data:{approvals, requests_open, requests_urgent, notifications_unread}}
- `GET inbox/approvals` -> {data:[ApprovalItem]} newest first, where ApprovalItem =
  {id:"<kind>:<id>", kind: portal_action|held_money|corporate_request, title, subtitle, amount|null, created_at,
   requested_by|null, booking_ref|null, guest_name|null,
   actions:[{value, label, tone: primary|secondary|danger, needs_reason:bool, needs_amount:bool, needs_method:bool}]}
  - portal_action = pending GuestActionApproval (geo-fence held: extend, checkout, order, reserve, request:*, checkin_no_payment);
    actions approve / reject (reason). Use the same code as web Dashboard\Approvals (approve runs the held payload).
  - held_money = cancelled/no-show bookings still holding money (same query as web Refunds page); actions
    refund (amount, method from refund methods incl. paystack, note) / keep (reason) via RecordBookingRefund / ForfeitHeldBookingMoney.
  - corporate_request = pending CorporateBookingRequest / rate proposals / transfers the web corporate-requests page lists;
    actions approve / reject (reason) via the same actions.
  Gate: capabilities.approve; held_money also needs the refunds module (same as web).
- `POST inbox/approvals/{id}/decide` {action, reason?, amount?, method?, note?} -> {status, item|null}
  422 {error:"decision_failed", detail}; 409 {error:"already_decided"} when someone else got there first.

### Guest requests (20, 20b)
- `GET guest-requests?status=open|resolved&kind=` -> {data:[{id, kind, kind_label, urgent, status, room_number, guest_name,
  booking_ref, preview, created_at, resolved_at, resolved_by|null}], counts:{open, urgent, resolved_today}}
- `GET guest-requests/{id}` -> {data: row + {body, guest_phone, messages:[{id, from: guest|staff, author, body, created_at}],
  quick_replies:[string]}}
- `POST guest-requests/{id}/reply` {body} -> {status, request}  (same channel the web reply uses: portal thread + WhatsApp notify;
  find how web guest-requests sends replies; if the web has no reply action, add one shared action and use it in both)
- `POST guest-requests/{id}/resolve` / `reopen` -> {status, request}  (same actions as web; resolve keeps the WhatsApp notice)

### Notifications (21)
- `GET notifications?page=` -> {data:[{id, kind, icon_kind, title, body, booking_ref|null, created_at, read}], unread}
  Same merged feed as the web bell (activity + database notifications, app/Livewire/Dashboard/NotificationBell.php).
- `POST notifications/read` {ids:[...]} and `POST notifications/read-all` -> {status, unread}

### Search (29)
- `GET search?q=` (q >= 2 chars) -> {data:{guests:[{customer_id, name, phone, stays_count, last_ref|null}],
  bookings:[BookingCard], rooms:[{id, number, category, tile_state}]}} max 5 each; same matching rules as web GlobalSearch.

## App (mobile-host/)
- Calendar tab: segment "Calendar | Rooms".
  - Calendar (17): week grid like the PNG (rooms as rows, 7 days, bars with surname, states coloured as legend, maintenance
    hatched, today column tinted), month chips + week arrows, tap a bar -> booking detail, tap a free cell -> new booking
    prefilled (room + dates), floating "+ Book".
  - Rooms (18): counts header, state segment, tile grid with one action per tile; tap tile -> room sheet (18b): Mark clean,
    Assign cleaner (picker from housekeepers), Out of order (reason), View history, open guest booking.
- Inbox tab: segment "Approvals N | Requests N | Alerts N" (19, 20b, 21). Approval cards render server `actions`
  (reason/amount/method dialogs when needed). Request detail (20): thread, quick replies, reply box, Mark resolved / Reopen.
  Alerts = notifications with mark all read. Tab bar badge = approvals + requests_open + notifications_unread (poll inbox/counts
  every 60s while foreground and on resume).
- Search (29): search icon on Today and Bookings top bars -> search screen with recent searches (local) and grouped results.
- All writes via the outbox; refresh affected caches after success/drain. Copy: sentence case, no em-dashes.
