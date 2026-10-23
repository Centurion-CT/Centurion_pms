# Centurion Staff app (housekeepers and porters): plan + API contract

Design: direction **A** ("Room list") from `keverna/design/staff-app` (A-rooms, A-clean, A-porter,
A-supplies), recoloured to the Centurion brand. Flutter app rebuilt in place in `mobile/`.
Sign in with phone number + staff PIN.

## Decisions
- App name "Centurion Staff", Android applicationId / iOS bundle `com.centurionapartments.staff`,
  version 2.0.0+2. Roles: `house_cleaner` and `porter` only. Anyone else gets a friendly screen.
- The old `/api/mobile/v1/*` endpoints stay untouched. New endpoints live under `/api/mobile/v1/staff/*`.
- POS, reservations, manager glance and the old inspections UI are removed from the app.
- English only; all user-facing strings in one file (`lib/core/strings.dart`). No em dashes in copy.
- Offline: ticks, start, finish, ready, send back and supply requests go through the Drift outbox and
  apply optimistically. Lists are cached (last good JSON) and shown when offline.
- Manager sets each cleaner's / porter's phone + PIN on the web Staff page.
- Push notifications are out of scope for this pass.

## Brand tokens (Centurion)
| token | value | use |
|---|---|---|
| paper | #EEF3F7 | screen background |
| card | #FFFFFF | cards |
| cardDeep | #F4F7FA | nested bands, inactive progress track |
| ink | #1A2430 | body text |
| inkSoft | #4A5563 | secondary text |
| inkMute | #6B7785 | captions, eyebrows |
| inkDeep | #16324B | main action bar (the design's black pill) |
| accent | #336799 | brand blue: progress fill, active nav pill, ticks, the round icon on the action bar |
| accentDeep | #285480 | blue text on light |
| accentSoft | #336799 @ 12% | blue chip backgrounds ("With porter") |
| line | #1A2430 @ 10% | hairlines/borders |
| toClean | bg #FBE3DC, fg #9A3F27 | "To clean" / "Sent back" chips + room badge |
| ready | bg #DDF0E3, fg #1E6B3A | "Ready" |
| warn | bg #FCF1D6, fg #8A5A00 | cleaner's note on porter check |
Fonts (bundled, no runtime download): Bricolage Grotesque (display: greeting, room numbers, page
titles), Hanken Grotesk (everything else). Lime in the original A becomes Centurion blue.

## Central sign-in (host root, no tenant yet)
`POST /api/mobile/v1/staff/login`
body `{phone, pin, tenant_id?, platform: android|ios|web, device_name, app_version}`
- 200 `{token, base_url, operator:{id,name,first_name,initials,role,phone}, tenant:{id,name}}`
- 300 `{status:"pick_tenant", tenants:[{id,name}]}` (resend with tenant_id)
- 401 `{error:"invalid_credentials", detail}` (same for unknown phone and wrong PIN)
- 403 `{error:"staff_only", detail}` / `{error:"suspended", detail}`
- 429 `{error:"too_many_attempts", detail}`
Phone matching ignores spaces/dashes and treats `+234…`, `234…` and `0…` as the same number.
`base_url` is the host to use for every call below (e.g. `https://example.com`).

## Staff API (Bearer token, base `{base_url}/api/mobile/v1/staff/`)
All non-GET calls need an `Idempotency-Key` header (ULID; reuse on retry). Errors are
`{error, detail}`; 403 `staff_only` for any non cleaner/porter; 409 `wrong_state` when the room has
moved on (e.g. someone else finished it). Times are ISO 8601 with offset.

- `GET session` -> `{data:{operator:{id,name,first_name,initials,role,phone}, property:{id,name},
  shift_label:"Morning shift"|"Afternoon shift"|"Night shift", date_label:"Thursday 23 Oct"}}`
- `GET rooms` (housekeeper) -> `{data:{progress:{ready,total}, counts:{to_clean,with_porter},
  rooms:[Room]}}`. Today's open rooms assigned to me or unassigned, plus the ones I finished today.
  Order: guest arriving today first (do_first), then sent back, then oldest checkout; finished last.
  `Room = {inspection_id, room_id, number, category, state: to_clean|cleaning|sent_back|with_porter|ready,
  do_first:bool, arriving_today:bool, arriving_at|null (bookings carry a date only, so null for now),
  guest_left_at|null (when the room was opened for cleaning), started_at|null, assigned_to_me:bool,
  progress:{done,total}, sent_back:{by, at, reasons:[key], reasons_label:"towels, bed", note|null}|null}`
- `GET rooms/{inspection}` -> `{data: Room + {groups:[{key: bathroom|bedroom|extras|room, label,
  items:[{id,label,icon,checked}]}], note|null}}`.
  Item `icon` keys: toilet, shower, towel, soap, sheets, dust, floor, light, water, bin, minibar, generic.
- `POST rooms/{inspection}/start` -> `{status:"ok", room: Room}` (sets started_at once; claims an
  unassigned room)
- `POST rooms/{inspection}/items/{item}` `{checked:bool}` -> `{status:"ok", item:{id,checked}}`
- `POST rooms/{inspection}/finish` `{note?}` -> `{status:"ok", room: Room}` ("Done, send to porter")
- `GET checks` (porter) -> `{data:{progress:{checked,total}, rooms:[Check]}}`
  `Check = {inspection_id, number, category, cleaned_by:{name,initials}, cleaned_at, minutes|null,
  score, arriving_today, arriving_at|null, note|null}`. Order: guest arriving today first, then oldest cleaned.
- `GET checks/{inspection}` -> `{data: Check + {items:[{label, icon, checked}]}}`
- `POST checks/{inspection}/ready` -> `{status:"ok"}`
- `POST checks/{inspection}/send-back` `{reasons:[towels|bathroom|bed|floor|smell|missing|other],
  note?}` -> `{status:"ok"}`. The room goes back to the cleaner (state `sent_back`) with the matching
  ticks cleared.
- `GET supplies` -> `{data:{outlet:{id,name}|null, products:[{id,name,unit|null,icon}],
  requests:[{id, summary:"Pillowcases, 10", created_at, status: waiting|approved|declined|cancelled,
  by|null}]}}`
- `POST supplies` `{lines:[{product_id, qty}], note?}` -> `{status:"ok"}`
- `GET me` -> `{data:{operator, week:{rooms_cleaned, rooms_checked, avg_minutes|null, sent_back}}}`
- `POST /api/mobile/v1/logout` (existing) signs the device out.

## App screens
1. Phone number. 2. PIN keypad (4 to 6 digits). 3. Hotel pick (only if 300).
4. Not for you (other roles).
Housekeeper tabs: **My rooms** (A-rooms) / Supplies / Me. Porter tabs: **To check** / Supplies / Me.
- My rooms: greeting with date + shift eyebrow, "N rooms to clean, M with the porter", Today progress
  card, room cards (badge with number, state chip, "Do first", category, one-line reason).
- Cleaning (A-clean): back, "{category} · Guest arriving 2:00 PM" eyebrow, "Room 204", "3 of 9 done"
  progress, grouped checklist with big round ticks (strike-through when done), bottom bar
  "Done, send to porter" (asks "Anything broken or missing?" with an optional note before sending;
  if items are unticked, says how many and lets them send anyway).
- Porter list: same card style ("Cleaned by Blessing, 10:42 AM").
- Porter check (A-porter): "Check this room" eyebrow, "Room 210", "With porter" chip, cleaner card
  with initials, time, minutes and score, the cleaner's note in a warm card, "Look at these" list,
  bottom: "Send back" (sheet with one-tap reasons + note) and "Room is ready".
- Supplies (A-supplies): "Housekeeping store", steppers per product, "Asked earlier" list,
  bottom "Ask the store".
- Me: name, role, this week's numbers, sign out.
- Offline banner when the outbox has unsent changes or the phone is offline.
